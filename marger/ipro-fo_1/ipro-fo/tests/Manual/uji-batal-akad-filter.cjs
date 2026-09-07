// Jalankan BatalAkad/Index.vue YANG SEBENARNYA: centang baris & filter per kolom.
//
// KENAPA PERLU UJI: halaman ini kini punya sepuluh filter kolom dan tiga cara mencentang
// (per baris, per customer, pilih-semua). Yang gampang salah bukan tampilannya, melainkan
// KESEPAKATAN antara tiga hal yang harus memakai nilai yang sama untuk tiap kolom: teks yang
// dirender, daftar opsi di dropdown, dan pencocokan filternya. Bila ketiganya punya versi
// sendiri, sebuah nilai bisa tampil "—" di sel tetapi terdaftar "" di dropdown — dicentang,
// tak menyaring apa pun, dan tak ada galat sama sekali.
//
// Diuji juga: pilih-semua hanya mengenai HASIL FILTER (bukan seluruh data — itu cara paling
// mudah mengekspor baris yang tidak sedang dilihat), centang bertahan saat filter berubah,
// dan opsi yang lenyap tidak meninggalkan filter aktif yang tak terlihat.
//
// Jalankan:  node tests/Manual/uji-batal-akad-filter.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/BatalAkad/Index.vue');

let tujuanEkspor = null;

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => {
            const dasar = path.join(ROOT, 'resources/js', a.path.slice(2));
            for (const p of [dasar, dasar + '.ts', dasar + '.js', dasar + '.vue',
                path.join(dasar, 'index.ts'), path.join(dasar, 'index.js')]) {
                if (fs.existsSync(p) && fs.statSync(p).isFile()) return { path: p };
            }

            return { path: dasar };
        });
        // Komponen anak distub: yang diuji logika halamannya, bukan render anaknya.
        build.onResolve({ filter: /(Badge|ColumnFilter|EmptyState|ScrollTopButton|TableTopScroll)\.vue$/ },
            (a) => ({ path: a.path, namespace: 'stubvue' }));
        build.onLoad({ filter: /.*/, namespace: 'stubvue' }, () => ({
            contents: 'export default { render: () => null };', loader: 'js', resolveDir: ROOT,
        }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? 'export const Link = { render: () => null }; export const router = { get(){}, post(){} };'
                : 'module.exports = new Proxy({}, { get: () => ({ render: () => null }) });',
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            return {
                contents: sfc.compileScript(descriptor, { id, inlineTemplate: false }).content,
                loader: 'ts', resolveDir: path.dirname(a.path),
            };
        });
    },
};

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

// Data uji: dua customer, satu di antaranya punya tiga pembatalan.
const ROWS = [
    { id: 1, kpr_number: 'KPR/2607/0034', customer_id: 7, customer: 'Roronoa Zorro', unit: 'JRK3-004-XV-12A', booking: 'SPR-1',
      bank: 'Bank Mandiri', status: 'cancelled', status_label: 'Dibatalkan', batal_at: '2026-07-29', tahap_sebelum: 'Akad Done',
      akad_at: '2026-08-23', sempat_akad: true, alasan: null, plafon: 500000000 },
    { id: 2, kpr_number: 'KPR/2607/0026', customer_id: 7, customer: 'Roronoa Zorro', unit: 'JRK3-004-XV-12A', booking: 'SPR-2',
      bank: 'Bank Tabungan Negara', status: 'cancelled', status_label: 'Dibatalkan', batal_at: '2026-07-29', tahap_sebelum: 'SI',
      akad_at: null, sempat_akad: false, alasan: null, plafon: 480000000 },
    { id: 3, kpr_number: 'KPR/2607/0015', customer_id: 9, customer: 'ARI CHUMAIDI', unit: 'JRK3-004-XVII-26', booking: 'SPR-3',
      bank: 'Bank Rakyat Indonesia', status: 'rejected', status_label: 'Ditolak', batal_at: '2026-07-03', tahap_sebelum: null,
      akad_at: null, sempat_akad: false, alasan: 'RPC KURANG', plafon: 345100000 },
    { id: 4, kpr_number: 'KPR/2608/0006', customer_id: 12, customer: 'GUNI', unit: 'JRK3-004-XVIII-17', booking: 'SPR-4',
      bank: 'Bank Rakyat Indonesia', status: 'rejected', status_label: 'Ditolak', batal_at: '2026-08-07', tahap_sebelum: null,
      akad_at: null, sempat_akad: false, alasan: 'ETSS GAJADI', plafon: 389200000 },
];

(async () => {
    const out = path.join(ROOT, 'batalakad.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [BERKAS], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });

    globalThis.route = (nama, p) => { tujuanEkspor = { nama, p }; return '/export'; };
    global.window = { get location() { return win; }, set location(v) { win = v; } };
    let win = { set href(v) { global.__href = v; } };
    global.window.location = win;

    const Komponen = require(out).default;
    const props = { rows: ROWS, totals: { count: 4, cancelled: 2, rejected: 2, sempat_akad: 1 } };
    const c = Komponen.setup(props, { attrs: {}, slots: {}, emit: () => {}, expose: () => {} });

    const idsTerlihat = () => c.filtered.value.map((r) => r.id);

    console.log('\n=== 1. Filter tersedia untuk SETIAP kolom ===');

    const kolom = ['cust', 'no', 'unit', 'bank', 'tgl', 'status', 'tahap', 'akad', 'plafon', 'alasan'];
    cek('ada 10 filter kolom', Object.keys(c.col).length === 10, Object.keys(c.col).join(','));
    for (const k of kolom) cek('kolom "' + k + '" punya filternya', Array.isArray(c.col[k]));

    console.log('\n=== 2. Opsi filter = nilai yang benar-benar tampil di kolomnya ===');

    // Inilah kesepakatan yang gampang putus: opsi HARUS memakai teks yang sama dengan selnya.
    cek('opsi Status memakai label tampilan', JSON.stringify(c.optStatus.value) === JSON.stringify(['Dibatalkan', 'Ditolak']),
        JSON.stringify(c.optStatus.value));
    cek('nilai kosong tampil sebagai "—", bukan untai kosong', c.optTahap.value.includes('—'),
        JSON.stringify(c.optTahap.value));
    cek('opsi Plafon sudah berformat rupiah', c.optPlafon.value.every((v) => v.startsWith('Rp ')),
        JSON.stringify(c.optPlafon.value));

    console.log('\n=== 3. Mencentang opsi benar-benar menyaring ===');

    c.col.status = ['Ditolak'];
    cek('filter Status menyisakan 2 baris', idsTerlihat().length === 2, JSON.stringify(idsTerlihat()));
    c.col.status = [];

    c.col.tahap = ['—'];
    cek('filter "—" menyaring baris yang kolomnya kosong', JSON.stringify(idsTerlihat()) === '[3,4]',
        JSON.stringify(idsTerlihat()));
    c.col.tahap = [];

    c.col.akad = ['Sempat akad'];
    cek('filter Sempat Akad menyisakan 1 baris', JSON.stringify(idsTerlihat()) === '[1]', JSON.stringify(idsTerlihat()));
    c.col.akad = [];

    c.col.bank = ['Bank Rakyat Indonesia'];
    c.col.status = ['Ditolak'];
    cek('dua filter bekerja bersamaan (DAN, bukan ATAU)', JSON.stringify(idsTerlihat()) === '[3,4]',
        JSON.stringify(idsTerlihat()));
    c.resetFilter();
    cek('reset mengembalikan semuanya', idsTerlihat().length === 4);

    console.log('\n=== 4. Pencarian ketik menjangkau seluruh kolom ===');

    c.search.value = 'zorro';
    cek('cari nama customer', JSON.stringify(idsTerlihat()) === '[1,2]', JSON.stringify(idsTerlihat()));
    c.search.value = 'rpc kurang';
    cek('cari isi kolom Keterangan', JSON.stringify(idsTerlihat()) === '[3]', JSON.stringify(idsTerlihat()));
    c.search.value = '';

    console.log('\n=== 5. Centang baris ===');

    c.toggleRow(3);
    cek('mencentang satu baris', c.selected.value.has(3) && c.selected.value.size === 1);
    c.toggleRow(3);
    cek('mencentang lagi melepasnya', c.selected.value.size === 0);

    // Pilih-semua hanya mengenai HASIL FILTER. Kalau mengenai seluruh data, operator yang
    // menyaring lalu menekan pilih-semua akan ikut mengekspor baris yang tidak dilihatnya.
    c.col.status = ['Ditolak'];
    c.toggleAll();
    cek('pilih-semua hanya mencentang hasil filter', c.selected.value.size === 2
        && c.selected.value.has(3) && c.selected.value.has(4), [...c.selected.value].join(','));
    cek('baris di luar filter tidak ikut tercentang', ! c.selected.value.has(1) && ! c.selected.value.has(2));
    c.toggleAll();
    cek('pilih-semua kedua kali melepas hasil filter', c.selected.value.size === 0);
    c.resetFilter();

    console.log('\n=== 6. Centang per customer ===');

    const grupZorro = c.grouped.value.find((g) => g.nama === 'Roronoa Zorro');
    cek('customer dengan 2 pembatalan tergabung jadi satu grup', grupZorro && grupZorro.rows.length === 2,
        grupZorro ? String(grupZorro.rows.length) : 'grup tidak ada');
    c.toggleGrup(grupZorro);
    cek('mencentang grup mencentang seluruh barisnya', c.selected.value.has(1) && c.selected.value.has(2));
    cek('grup terbaca tercentang', c.grupTercentang(grupZorro) === true);
    c.toggleGrup(grupZorro);
    cek('mencentang grup lagi melepas semuanya', c.selected.value.size === 0);

    console.log('\n=== 7. Ekspor mengirim baris yang sedang terlihat ===');

    // Sisi server tidak lagi menyalin logika filter; layarnya yang menyebut id-nya. Kalau
    // yang dikirim bukan id, berkasnya berisi baris yang berbeda dari layarnya.
    c.col.status = ['Ditolak'];
    c.exportExcel();
    cek('tanpa centang: seluruh hasil filter yang dikirim', tujuanEkspor?.p?.ids === '3,4',
        JSON.stringify(tujuanEkspor));
    c.toggleRow(4);
    c.exportExcel();
    cek('dengan centang: hanya yang tercentang', tujuanEkspor?.p?.ids === '4', JSON.stringify(tujuanEkspor));
    cek('rutenya diserahkan ke route()', tujuanEkspor?.nama === 'bs.batal-akad.export', String(tujuanEkspor?.nama));

    console.log('\n=== 8. Centang bertahan saat filter berubah ===');

    // Operator biasa menyaring per bank, mencentang, lalu berpindah ke bank berikutnya. Kalau
    // centangnya hilang tiap kali filter berubah, mengumpulkan lintas-filter jadi mustahil.
    c.resetFilter();
    c.selected.value.clear();
    c.col.bank = ['Bank Mandiri'];
    c.toggleAll();
    const setelahMandiri = c.selected.value.size;
    c.col.bank = ['Bank Rakyat Indonesia'];
    cek('centang sebelumnya tidak hilang saat filter diganti', c.selected.value.size === setelahMandiri
        && setelahMandiri > 0, 'size=' + c.selected.value.size);
    c.toggleAll();
    cek('centang bertambah, bukan menggantikan', c.selected.value.size === setelahMandiri + 2,
        'size=' + c.selected.value.size);

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message + '\n' + e.stack); process.exit(1); });
