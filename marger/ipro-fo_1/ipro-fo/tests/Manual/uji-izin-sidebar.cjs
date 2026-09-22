/**
 * Aturan izin sidebar & Command Palette — cocokIzin() dari resources/js/Composables/useIzin.ts.
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. SUPERADMIN MELIHAT SEMUA MENU. Di sisi server SuperAdmin melewati seluruh gate lewat
 *     Gate::before; daftar izin yang dikirim ke layar tidak tahu apa-apa soal itu. Tanpa
 *     pelolosan ini, setiap izin baru yang belum diberikan ke peran mana pun membuat menunya
 *     hilang dari layar sementara halamannya tetap bisa dibuka dengan mengetik alamatnya —
 *     persis yang terjadi pada sub-modul "Komponen COGS".
 *  2. PERAN LAIN TETAP DISARING. Pelolosan itu tidak boleh merembet: aturan ini tidak memberi
 *     hak akses kepada siapa pun selain SuperAdmin.
 *  3. WILDCARD MODUL tetap bekerja ('ap.*' = punya izin apa pun berawalan 'ap.'), dan tidak
 *     boleh terlalu longgar — 'ap.*' tidak boleh diloloskan oleh izin 'approval.view'.
 *  4. MENU TANPA SYARAT IZIN selalu tampil.
 *
 * Jalankan dengan: node tests/Manual/uji-izin-sidebar.cjs .
 */

const fs = require('fs');
const path = require('path');

const root = process.argv[2] || '.';
const berkas = path.join(root, 'resources/js/Composables/useIzin.ts');

let lulus = 0;
let gagal = 0;
function ok(judul, syarat, detail) {
    if (syarat) { lulus++; console.log('  OK    ' + judul); }
    else { gagal++; console.log('  GAGAL ' + judul + (detail ? ' — ' + detail : '')); }
}

if (! fs.existsSync(berkas)) {
    console.log('Berkas tidak ditemukan: ' + berkas);
    process.exit(1);
}

const sumber = fs.readFileSync(berkas, 'utf8');

// Diambil dari berkas SUNGGUHAN, bukan disalin ke sini: salinan aturan di dalam uji akan tetap
// hijau walaupun aturan yang dipakai aplikasi sudah berubah.
const potongan = sumber.match(/export function cocokIzin\([\s\S]*?\n}/);
if (! potongan) {
    console.log('GAGAL: fungsi cocokIzin() tidak ditemukan di ' + berkas);
    process.exit(1);
}
const js = potongan[0]
    .replace(/export function/, 'function')
    .replace(/: string\[\]/g, '')
    .replace(/: boolean/g, '')
    .replace(/perm\?: string \| string\[\]/, 'perm');
// eslint-disable-next-line no-new-func
const cocokIzin = new Function(js + '; return cocokIzin;')();

const IZIN_BIASA = ['ap.invoice.view', 'ap.aging.view', 'approval.view'];

console.log('\n=== 1. SuperAdmin melihat semua menu ===');
ok('izin yang belum dipegang siapa pun tetap tampil untuk SuperAdmin',
    cocokIzin([], true, ['cogs.component.*']) === true,
    'menu fitur baru hilang dari layar sampai seseorang menjalankan ulang RoleSeeder');
ok('  izin tunggal pun sama', cocokIzin([], true, 'cogs.component.view') === true);
ok('  daftar izin kosong tidak membuatnya gagal', cocokIzin([], true, ['apa.pun.*']) === true);

console.log('\n=== 2. Peran lain TETAP disaring ===');
ok('bukan SuperAdmin tanpa izin: ditolak',
    cocokIzin([], false, ['cogs.component.*']) === false,
    'pelolosan SuperAdmin merembet ke semua orang — seluruh menu terbuka untuk siapa saja');
ok('  bukan SuperAdmin dengan izin lain: tetap ditolak',
    cocokIzin(IZIN_BIASA, false, ['cogs.component.*']) === false);
ok('  bukan SuperAdmin dengan izin yang tepat: diterima',
    cocokIzin(IZIN_BIASA, false, ['ap.invoice.view']) === true);

console.log('\n=== 3. Wildcard modul ===');
ok('"ap.*" diloloskan oleh izin granular modul ap',
    cocokIzin(['ap.aging.view'], false, 'ap.*') === true);
ok('  "ap.*" TIDAK diloloskan oleh "approval.view"',
    cocokIzin(['approval.view'], false, 'ap.*') === false,
    'awalan dicocokkan tanpa titik — modul lain yang namanya berawalan sama ikut membuka menu');
ok('  "ap.*" ditolak bila tidak ada izin modul itu',
    cocokIzin(['gl.journal.view'], false, 'ap.*') === false);
ok('  daftar syarat: cukup salah satu terpenuhi',
    cocokIzin(['tax.pph.view'], false, ['ap.aging.*', 'tax.pph.*']) === true);
ok('  daftar syarat: tidak ada yang terpenuhi → ditolak',
    cocokIzin(['gl.journal.view'], false, ['ap.aging.*', 'tax.pph.*']) === false);

console.log('\n=== 4. Menu tanpa syarat izin ===');
ok('undefined selalu tampil', cocokIzin([], false, undefined) === true);
ok('  string kosong selalu tampil', cocokIzin([], false, '') === true);

console.log('\n=== 5. Aturannya dipakai bersama, bukan disalin ===');
for (const f of ['resources/js/Components/Layout/Sidebar.vue', 'resources/js/Components/Layout/CommandPalette.vue']) {
    const isi = fs.readFileSync(path.join(root, f), 'utf8');
    ok(path.basename(f) + ' memakai useIzin()',
        /from '@\/Composables\/useIzin'/.test(isi) && /useIzin\(\)/.test(isi));
    // Salinan aturan sendiri akan lambat laun menyaring hal yang berbeda dari sidebar —
    // menu yang tampil di satu tempat hilang di tempat lain, tanpa galat apa pun.
    ok('  ' + path.basename(f) + ' tidak lagi membaca daftar izin sendiri',
        ! /auth\??\.?\.user\??\.?\.permissions/.test(isi.replace(/\s/g, '')),
        'masih ada salinan pembacaan permissions di berkas ini');
}

console.log('\n────────────────────────────────');
console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
process.exit(gagal === 0 ? 0 : 1);
