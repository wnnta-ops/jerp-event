// Modul Legal — dokumen AJB ditautkan ke SPR (Final) lalu mengisi Detail AJB.
//
// Modul Legal adalah aplikasi Next.js tersendiri: di repo ini ia tidak punya
// node_modules dan tidak punya uji sendiri, jadi yang bisa dijaga adalah (a)
// setiap berkas yang diubah MASIH BISA DIBACA compiler — satu salah ketik di
// sini menggagalkan seluruh build container Legal — dan (b) rantai penautannya
// benar-benar tersambung dari formulir sampai ke pendorong.
//
// enums.ts tidak mengimpor apa pun, jadi ia BENAR-BENAR DIJALANKAN di sini:
// scope AJB, keanggotaan TRANSACTION_TYPES, dan isSprLinked diuji dengan memanggil
// fungsinya, bukan dengan mencocokkan teks.
//
// Jalankan:  node tests/Manual/uji-legal-ajb-dokumen.cjs .
const fs = require('fs');
const os = require('os');
const path = require('path');
const esbuild = require('esbuild');

const BO = path.resolve(process.argv[2] || '.');
const AKAR = path.resolve(BO, '../../..');
const L = (p) => path.join(AKAR, 'app/legal', p);

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const baca = (p) => fs.readFileSync(L(p), 'utf8');
/** Buang komentar supaya penjaga tidak cocok dengan komentarnya sendiri. */
const bersih = (s) => s.replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*$/gm, '');

console.log('\n=== 1. Semua berkas yang diubah masih bisa dibaca compiler ===');

const BERKAS = [
    'lib/enums.ts', 'lib/validation.ts', 'lib/jerp-bridge.ts', 'lib/ajb-sync.ts',
    'app/api/spr/route.ts', 'app/documents/new/actions.ts', 'app/documents/actions.ts',
    'app/documents/[id]/edit/page.tsx', 'app/page.tsx',
    'components/upload-form.tsx', 'components/document-drawer.tsx', 'components/directory-table.tsx',
];
for (const b of BERKAS) {
    let galat = null;
    try {
        esbuild.transformSync(baca(b), { loader: b.endsWith('.tsx') ? 'tsx' : 'ts', format: 'esm' });
    } catch (e) { galat = String(e.message || e).split('\n')[0]; }
    cek('  ' + b, galat === null, galat || '');
}

console.log('\n=== 2. enums.ts — dijalankan, bukan dicocokkan teksnya ===');

function muat(relatif, loader) {
    const kode = esbuild.transformSync(baca(relatif), { loader: loader || 'ts', format: 'cjs' }).code;
    const berkas = path.join(os.tmpdir(), 'legal-uji-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.cjs');
    fs.writeFileSync(berkas, kode);
    try { return require(berkas); } finally { try { fs.unlinkSync(berkas); } catch { /* sementara */ } }
}

const enums = muat('lib/enums.ts');

// AJB harus unit-scoped. scripts/import-ajb.mjs SUDAH menulis scope "unit" untuk
// 22 AJB hasil impor; selama formulirnya masih menganggap AJB dokumen tingkat
// developer, dokumen baru lahir tanpa unit & pembeli sementara yang lama punya.
cek('scopeForDocType("AJB") = "unit"', enums.scopeForDocType('AJB') === 'unit', enums.scopeForDocType('AJB'));
cek('  AJB anggota TRANSACTION_TYPES (memunculkan kolom cluster/blok/unit)',
    enums.TRANSACTION_TYPES.includes('AJB'));
cek('  isSprLinked("AJB") true', enums.isSprLinked('AJB') === true);
cek('  isSprLinked untuk tipe lain false',
    enums.isSprLinked('SKRK') === false && enums.isSprLinked('PBGSplit') === false);
cek('  isSprLinked aman untuk kosong/null',
    enums.isSprLinked('') === false && enums.isSprLinked(null) === false && enums.isSprLinked(undefined) === false);
// Tipe split tidak boleh ikut terseret jadi "tertaut SPR" — kolom AJB-nya akan
// muncul di dokumen yang sama sekali bukan akta.
cek('  hanya AJB yang tertaut SPR', JSON.stringify(enums.SPR_LINKED_TYPES) === JSON.stringify(['AJB']),
    JSON.stringify(enums.SPR_LINKED_TYPES));
cek('  AJB tetap di tab Perizinan (tabnya tidak berpindah)',
    enums.categoryOfDocType('AJB') === 'perizinan', String(enums.categoryOfDocType('AJB')));

console.log('\n=== 3. Skema penyimpanan (Prisma) ===');

const prisma = fs.readFileSync(path.join(AKAR, 'app/legal/prisma/schema.prisma'), 'utf8');
for (const kolom of ['sprNumber', 'notaryName', 'ajbValue', 'sprSyncedAt', 'sprSyncError']) {
    cek('  Document.' + kolom + ' ada', new RegExp('^\\s*' + kolom + '\\s+\\S', 'm').test(prisma));
}
cek('  ada index sprNumber', /@@index\(\[sprNumber\]\)/.test(prisma));
// notaryName HARUS kolom sendiri, bukan menumpang originalLocationDetail:
// kolom itu menjawab "di mana aslinya SEKARANG", jadi begitu akta pindah ke bank
// nama notarisnya hilang — padahal notaris pembuat akta melekat selamanya.
cek('  notaryName terpisah dari originalLocationDetail',
    /notaryName\s+String\?/.test(prisma) && /originalLocationDetail\s+String\?/.test(prisma));

console.log('\n=== 4. Aturan wajib untuk AJB (validation.ts) ===');

const val = bersih(baca('lib/validation.ts'));
cek('skemanya bisa dibentuk dengan opsi (buatSkemaDokumen)', /export function buatSkemaDokumen/.test(val));
cek('  documentInputSchema tetap diekspor (pemakai lama tidak putus)',
    /export const documentInputSchema = buatSkemaDokumen\(\)/.test(val));
// Dicocokkan lewat PESANNYA, bukan hanya nama kolomnya: ajbValue muncul dua kali
// (wajib diisi, dan harus berupa angka), jadi penjaga berbasis nama kolom tetap
// lulus walau aturan "wajib diisi"-nya dihapus.
const blokWajib = val.split('isSprLinked(t)')[1] || '';
for (const [pesan, label] of [
    ['Pilih No. SPR (Final) yang ditautkan', 'No. SPR'],
    ['Nomor akta AJB wajib diisi', 'nomor akta'],
    ['Nama notaris/PPAT wajib diisi', 'notaris'],
    ['Nominal akta wajib diisi', 'nominal'],
]) {
    cek('  AJB mewajibkan ' + label, blokWajib.includes(pesan), pesan);
}
// Hanya No. SPR yang boleh dilonggarkan untuk dokumen lama; kalau nomor akta
// atau nominal ikut longgar, baris di Detail AJB jadi setengah jadi.
const blokAjb = (val.split('isSprLinked(t)')[1] || '').split('\n  });')[0];
cek('  hanya No. SPR yang bisa dilonggarkan (sprWajib)',
    (blokAjb.match(/sprWajib/g) || []).length === 1, JSON.stringify(blokAjb.match(/sprWajib/g)));
cek('  validation.ts mengimpor isSprLinked dari enums', /isSprLinked,?\n/.test(baca('lib/validation.ts')));
cek('  isSprLinked memang diekspor enums', typeof enums.isSprLinked === 'function');

console.log('\n=== 5. Formulir: penaut SPR & kolom aktanya ===');

const form = bersih(baca('components/upload-form.tsx'));
cek('ada komponen penaut SPR', /function SprPicker\(/.test(form));
// basePath "/legal": fetch("/api/spr") TIDAK di-prefix Next (hanya <Link> & router),
// jadi tanpa withBase() autocomplete-nya 404 di balik nginx.
cek('  fetch-nya lewat withBase (basePath /legal)', /fetch\(withBase\(`\/api\/spr/.test(form));
// Tanpa jeda, tiap huruf yang diketik jadi satu permintaan dan throttle FO
// (60/menit) habis di tengah pencarian — daftarnya lalu kosong tanpa penjelasan.
cek('  ada jeda ketik (debounce) sebelum memanggil',
    /setTimeout\(async \(\)/.test(form) && /\}, 300\);/.test(form));
cek('  nomor SPR tetap bisa diketik manual (bukan dropdown tertutup)',
    /onType\(e\.target\.value\)/.test(form));
cek('  memilih SPR ikut mengisi cluster/blok/unit/pembeli',
    /cluster: s\.cluster/.test(form) && /blok: s\.blok/.test(form)
    && /unitNo: s\.no_unit/.test(form) && /buyerName: s\.customer/.test(form));
cek('  kolom Notaris ada', /value=\{values\.notaryName\}/.test(form));
cek('  kolom Nominal akta ada', /value=\{values\.ajbValue\}/.test(form));
for (const k of ['sprNumber', 'notaryName', 'ajbValue']) {
    cek('  ' + k + ' ikut dikirim ke server', new RegExp('fd\\.set\\("' + k + '"').test(form));
}
cek('  validasi layar memakai skema beropsi yang sama dengan server',
    /buatSkemaDokumen\(\{ sprWajib \}\)/.test(form) && /skema\.safeParse/.test(form));
// Kegagalan penautan tidak boleh lenyap: arsipnya sudah tersimpan, yang tertunda
// tautannya — dan hanya di sini petugas bisa tahu ada yang perlu diulang.
cek('  kegagalan penautan ditampilkan sesudah tersimpan', /saved\.peringatan/.test(form));

console.log('\n=== 6. Rantai pendorong (server actions) ===');

const buat = bersih(baca('app/documents/new/actions.ts'));
const ubah = bersih(baca('app/documents/actions.ts'));
const sync = bersih(baca('lib/ajb-sync.ts'));

cek('dokumen baru didorong ke Detail AJB', /await sinkronAjb\(doc\)/.test(buat));
cek('  dorongannya SESUDAH dokumen tersimpan', buat.indexOf('prisma.document.create') < buat.indexOf('sinkronAjb(doc)'));
cek('dokumen yang disunting ikut didorong', /await sinkronAjb\(doc\)/.test(ubah));
cek('  penyuntingan memakai sprWajib dari data lama',
    /buatSkemaDokumen\(\{ sprWajib: Boolean\(existing\.sprNumber\) \}\)/.test(ubah));
cek('dokumen yang dihapus melepas tautannya', /await lepasSinkronAjb\(docId\)/.test(ubah));
// Urutannya menentukan: kalau dokumennya dihapus lebih dulu dan pelepasan gagal,
// tidak ada lagi yang bisa dipakai mengulang pelepasan.
cek('  pelepasan dilakukan SEBELUM dokumennya dihapus',
    ubah.indexOf('lepasSinkronAjb(docId)') < ubah.indexOf('prisma.document.delete'));

cek('bukan-AJB / tanpa SPR justru MELEPAS baris di Back Office', /if \(!tertaut\)[\s\S]{0,200}lepasAjb\(doc\.id\)/.test(sync));
cek('  hasil dorongan dicatat di dokumen (sprSyncedAt / sprSyncError)',
    /sprSyncedAt: galat \? null : new Date\(\)/.test(sync) && /sprSyncError: galat/.test(sync));
cek('  nominal dikirim lewat angkaRupiah (titik ribuan)', /amount: angkaRupiah\(doc\.ajbValue\)/.test(sync));
for (const [berkas, isi] of [['new/actions', buat], ['actions', ubah]]) {
    cek('  ' + berkas + ': kolom AJB dikosongkan bila tipenya bukan AJB',
        /sprNumber: isSprLinked\(docType\) \? data\.sprNumber \?\? null : null/.test(isi));
}

console.log('\n=== 7. Perantara autocomplete dijaga sesi ===');

const rute = bersih(baca('app/api/spr/route.ts'));
cek('rute /api/spr memeriksa pengguna aktif', /getActingUser\(\)/.test(rute) && /status: 401/.test(rute));
cek('  memanggil jembatan di server (kunci tidak sampai ke peramban)', /cariSpr\(/.test(rute));
cek('  kunci tanda tangan tidak pernah dikirim ke klien', ! /LEGAL_BRIDGE_SECRET|JERP_SSO_SECRET/.test(baca('components/upload-form.tsx')));

console.log('\n=== 8. Laci dokumen memperlihatkan keadaan tautannya ===');

const laci = bersih(baca('components/document-drawer.tsx'));
cek('menampilkan No. SPR yang tertaut', /doc\.sprNumber/.test(laci));
cek('  menampilkan notaris & nominal akta', /doc\.notaryName/.test(laci) && /doc\.ajbValue/.test(laci));
cek('  kegagalan penautan ditampilkan menonjol', /doc\.sprSyncError/.test(laci));
const tabel = baca('components/directory-table.tsx');
for (const k of ['sprNumber', 'notaryName', 'ajbValue', 'sprSyncedAt', 'sprSyncError']) {
    cek('  DocRow membawa ' + k, new RegExp('^\\s*' + k + ': ', 'm').test(tabel));
}
const halaman = baca('app/page.tsx');
cek('  direktori mengisi kolom-kolom itu dari database',
    /sprNumber: d\.sprNumber/.test(halaman) && /sprSyncError: d\.sprSyncError/.test(halaman));

console.log('\n=== 9. Container Legal tahu ke mana harus menelepon ===');

const compose = fs.readFileSync(path.join(AKAR, 'docker-compose.yml'), 'utf8');
const blokLegal = compose.split('\n  legal:')[1]?.split('\n  sales:')[0] ?? '';
cek('service legal diberi JERP_BASE_URL', /JERP_BASE_URL:/.test(blokLegal));
// service `app` itu PHP-FPM port 9000 (bukan HTTP). Mengarahkannya ke sana
// menghasilkan sambungan yang tidak pernah membalas HTTP — gejalanya timeout,
// bukan pesan galat yang menjelaskan apa pun.
cek('  arahnya nginx, bukan service app (PHP-FPM)', /http:\/\/nginx/.test(blokLegal) && ! /http:\/\/app/.test(blokLegal));
cek('  JERP_SSO_SECRET tetap ada (kunci jembatan diturunkan darinya)', /JERP_SSO_SECRET:/.test(blokLegal));

console.log('\n=== 10. Migrasi penanda sumber di Back Office ===');

const migrasi = fs.readFileSync(path.join(BO, 'database/migrations/2026_09_22_100000_add_legal_link_to_ajb_documents.php'), 'utf8');
cek('menambah kolom source', /string\('source', 16\)/.test(migrasi));
// Bawaannya WAJIB 'bo': kalau bawaannya 'legal', seluruh baris lama hasil Input
// Massal ikut terbuka dan terbaca SUDAH BAST tanpa dokumen apa pun di belakangnya.
cek('  bawaannya "bo" (baris lama tidak berubah perilakunya)', /->default\('bo'\)/.test(migrasi));
cek('  legal_doc_id unik (satu dokumen Legal = satu baris SPR)', /unique\('legal_doc_id'/.test(migrasi));
cek('  bisa dibalik (down menghapus ketiga kolomnya)',
    /dropColumn\(\['source', 'legal_doc_id', 'legal_synced_at'\]\)/.test(migrasi));

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
