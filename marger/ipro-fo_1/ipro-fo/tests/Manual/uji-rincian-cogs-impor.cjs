// COGS Baru → Rincian COGS: "Tambah Unit Massal" (impor) & Ekspor.
//
// ── Sifat yang paling menentukan: BOLAK-BALIK ──
// Apa pun yang diekspor harus bisa diimpor kembali dan menghasilkan daftar yang SAMA PERSIS.
// Tanpa sifat itu, operator yang mengekspor 199 unit, menyunting beberapa baris di Excel, lalu
// mengimpornya kembali akan diam-diam kehilangan kolom yang bentuk tulisannya tidak terbaca —
// dan hilangnya baru terlihat saat angka HPP-nya tidak cocok.
//
// Yang juga dijaga:
//  1. ANGKA AMBIGU DITOLAK, BUKAN DITEBAK. "1.500" bisa berarti seribu lima ratus atau satu koma
//     lima; menebaknya salah menggeser luas unit seribu kali lipat tanpa terlihat.
//  2. LABEL DICOCOKKAN PERSIS. "Standard Costing / Realisasi Costing" memuat kedua label lain di
//     dalamnya, jadi pencocokan sebagian akan memilih yang salah.
//  3. ATURAN SERVER DIULANG DI PEMBACA: unit belum booked tidak boleh mengunci pilihan costing,
//     dan kode unit tidak boleh kembar di dalam satu berkas.
//  4. SELURUH BARIS TETAP DIBACA walau ada yang bergalat — daftar galatnya lengkap beserta nomor
//     barisnya, bukan berhenti di yang pertama.
//  5. PEMISAH KOLOM DIDETEKSI (titik koma / koma / TAB) supaya tempelan langsung dari Excel bisa.
//  6. Impornya mengisi DRAF, bukan basis data: simpanan Rincian COGS mengganti seluruh daftar,
//     jadi tulisan langsung akan tertimpa draf lama tanpa galat apa pun.
//
// Jalankan:  node tests/Manual/uji-rincian-cogs-impor.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '.');
const MODUL = path.join(ROOT, 'resources/js/Components/Cogs2/unitCsv.ts');
const DIALOG = path.join(ROOT, 'resources/js/Components/Cogs2/ImporUnit.vue');
const LAYAR = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Cogs2/Rincian.vue');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

function muat() {
    const hasil = esbuild.buildSync({
        entryPoints: [MODUL], bundle: true, write: false, format: 'cjs', platform: 'node',
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    return modul.exports;
}

const UNIT = [
    { no_spr: 'SPR-1', nama_customer: 'ROKHIMULLAH', unit_rumah: 'JRK3-001-X-9', lokasi: 'JRK3', tahap: '1', tipe: 'CICA', lt: 75, lb: 36, status: 'belum', costing: 'belum', booked_revenue: null },
    { no_spr: null, nama_customer: 'PT. MUI', unit_rumah: 'JRK3-002-RUHA-3A', lokasi: 'JRK3', tahap: '2', tipe: 'RUHA CICI', lt: 87.5, lb: 47, status: 'sudah', costing: 'standard', booked_revenue: 650000000 },
    { no_spr: 'SPR-3', nama_customer: 'SASTRO; PANJI', unit_rumah: 'JRK3-001-IX-15', lokasi: 'JRK3', tahap: '1', tipe: 'CICA HOOK', lt: 1999.75, lb: 39, status: 'sudah', costing: 'realisasi', booked_revenue: 0 },
];

function main() {
    const m = muat();

    console.log('=== 1. Bolak-balik: ekspor lalu impor kembali ===');

    const csv = m.keCsv(UNIT);
    const balik = m.dariCsv(csv);
    cek('impor kembali tanpa galat', balik.galat.length === 0, JSON.stringify(balik.galat));
    cek('  jumlah barisnya sama', balik.baris.length === UNIT.length);
    // Dibandingkan PER KOLOM supaya kegagalannya menyebut kolom mana yang berubah, bukan sekadar
    // "tidak sama" — yang tidak menolong siapa pun mencarinya.
    for (const k of ['no_spr', 'nama_customer', 'unit_rumah', 'lokasi', 'tahap', 'tipe', 'lt', 'lb', 'status', 'costing', 'booked_revenue']) {
        const asli = UNIT.map((u) => u[k]);
        const kini = balik.baris.map((u) => u[k]);
        cek('  kolom ' + k + ' utuh', JSON.stringify(asli) === JSON.stringify(kini),
            'asli=' + JSON.stringify(asli) + ' kembali=' + JSON.stringify(kini));
    }
    cek('  titik koma di dalam nama tidak memecah kolom',
        balik.baris[2].nama_customer === 'SASTRO; PANJI',
        'nama: ' + JSON.stringify(balik.baris[2].nama_customer));
    cek('  diawali BOM UTF-8 supaya Excel membaca huruf beraksen dengan benar', csv.charCodeAt(0) === 0xFEFF);
    // Selnya memang DIKUTIP karena memuat koma desimal, dan itu disengaja: Excel berbahasa
    // Inggris memakai koma sebagai pemisah kolom, jadi kutipnya yang menjaga angka itu tetap
    // satu sel. Yang diuji di sini adalah tidak adanya pemisah RIBUAN, bukan ada-tidaknya kutip.
    cek('  angka ditulis TANPA pemisah ribuan', csv.includes('1999,75') && ! csv.includes('1.999,75'),
        'pemisah ribuan membuat bacaannya ambigu — sel LT: ' + (csv.match(/"?[\d.,]*1999[\d.,]*"?/) || [''])[0]);
    cek('  dan sel berkoma desimal dikutip supaya aman di Excel mana pun',
        csv.includes('"1999,75"'),
        'tanpa kutip, Excel yang memakai koma sebagai pemisah kolom akan memecah angkanya jadi dua sel');

    console.log('\n=== 2. Angka ambigu ditolak, bukan ditebak ===');

    cek('"1.500" ditolak dengan keterangan', (() => {
        try { m.bacaAngka('1.500'); return false; } catch (e) { return /ambigu/i.test(e.message); }
    })(), 'seribu lima ratus atau satu koma lima — menebaknya salah menggeser luas seribu kali lipat');
    cek('  dan galatnya menyebut nomor barisnya',
        (() => {
            const h = m.dariCsv('Unit Rumah;LT;LB\nA-1;1.500;36');

            return h.galat.some((g) => g.baris === 2 && /ambigu/i.test(g.pesan));
        })());
    cek('"1.234,56" terbaca 1234.56 (titik ribuan, koma desimal)', m.bacaAngka('1.234,56') === 1234.56);
    cek('"1234.56" terbaca 1234.56 (titik desimal)', m.bacaAngka('1234.56') === 1234.56);
    cek('"87,5" terbaca 87.5', m.bacaAngka('87,5') === 87.5);
    cek('"1234" terbaca 1234', m.bacaAngka('1234') === 1234);
    cek('kosong terbaca null, bukan nol', m.bacaAngka('') === null && m.bacaAngka('   ') === null);
    cek('bukan angka ditolak', (() => { try { m.bacaAngka('abc'); return false; } catch { return true; } })());

    console.log('\n=== 3. Label dicocokkan PERSIS ===');

    const cs = (teks) => m.dariCsv('Unit Rumah;LT;LB;Status;Pilihan Costing\nA-1;10;10;Sudah Booked;' + teks);
    cek('"Standard Costing" → standard', cs('Standard Costing').baris[0].costing === 'standard');
    cek('"Realisasi Costing" → realisasi', cs('Realisasi Costing').baris[0].costing === 'realisasi');
    // Label gabungannya memuat kedua label lain; pencocokan sebagian akan memilih yang salah.
    cek('"Standard Costing / Realisasi Costing" → belum, BUKAN standard',
        cs('Standard Costing / Realisasi Costing').baris[0].costing === 'belum',
        'hasil: ' + cs('Standard Costing / Realisasi Costing').baris[0].costing);
    cek('kunci mentah juga diterima', cs('realisasi').baris[0].costing === 'realisasi');
    cek('beda huruf besar-kecil & spasi berlebih tetap cocok',
        cs('  sTaNdArD   cOsTiNg  ').baris[0].costing === 'standard');
    cek('label tak dikenal ditolak dengan keterangan',
        cs('Costing Apa Saja').galat.some((g) => /tidak dikenal/i.test(g.pesan)));
    // Ini yang membedakan pencocokan PERSIS dari pencocokan sebagian. Contoh "Standard Costing /
    // Realisasi Costing" TIDAK cukup: label itu diperiksa paling awal, jadi pencocokan sebagian
    // pun kebetulan memulangkan jawaban yang benar. Yang benar-benar membongkarnya adalah nilai
    // yang MENGANDUNG kata kuncinya tetapi bukan label yang sah.
    cek('nilai yang hanya MENGANDUNG kata kunci ditolak, bukan diterima',
        cs('Realisasi Costing Tambahan').galat.some((g) => /tidak dikenal/i.test(g.pesan)),
        'hasil: ' + cs('Realisasi Costing Tambahan').baris[0].costing);
    cek('  begitu pula pada Status',
        m.dariCsv('Unit Rumah;LT;LB;Status\nA-1;10;10;Belum Booked Sekali')
            .galat.some((g) => /tidak dikenal/i.test(g.pesan)));

    console.log('\n=== 4. Aturan server diulang di pembaca ===');

    const h1 = m.dariCsv('Unit Rumah;LT;LB;Status;Pilihan Costing\nA-1;10;10;Belum Booked;Standard Costing');
    cek('unit belum booked tidak boleh mengunci costing',
        h1.galat.some((g) => g.baris === 2 && /belum booked/i.test(g.pesan)),
        'menguncinya mengeluarkan unit itu dari pembebanan realisasi padahal belum terjual');

    const h2 = m.dariCsv('Unit Rumah;LT;LB\nA-1;10;10\nA-1;20;20');
    cek('kode unit kembar di dalam berkas ditolak',
        h2.galat.some((g) => g.baris === 3 && /kembar/i.test(g.pesan)) && h2.baris.length === 1,
        'satu unit yang muncul dua kali menanggung realisasi dua kali');
    cek('  dan kembarnya tidak peka huruf besar-kecil',
        m.dariCsv('Unit Rumah;LT;LB\nA-1;10;10\na-1;20;20').galat.some((g) => /kembar/i.test(g.pesan)));

    const h3 = m.dariCsv('Unit Rumah;LT;LB\n;10;10');
    cek('Unit Rumah kosong ditolak', h3.galat.some((g) => /Unit Rumah kosong/i.test(g.pesan)) && h3.baris.length === 0);

    const h4 = m.dariCsv('Nama Customer;LT\nBUDI;10');
    cek('kolom Unit Rumah tidak ada → ditolak di baris judul',
        h4.galat.some((g) => g.baris === 1 && /Unit Rumah/i.test(g.pesan)) && h4.baris.length === 0,
        'tanpa kode unit seluruh barisnya tidak berarti apa-apa');

    const h5 = m.dariCsv('Unit Rumah;LT;LB\nA-1;-5;10');
    cek('luas negatif ditolak', h5.galat.some((g) => /negatif/i.test(g.pesan)));

    console.log('\n=== 5. Seluruh baris tetap dibaca walau ada yang bergalat ===');

    const banyak = m.dariCsv([
        'Unit Rumah;LT;LB;Status;Pilihan Costing',
        'A-1;10;10;Belum Booked;',
        'A-2;1.500;10;Belum Booked;',           // ambigu
        'A-3;10;10;Entah;',                      // status tak dikenal
        'A-4;10;10;Belum Booked;Standard Costing', // belum booked tapi mengunci
        'A-5;10;10;Belum Booked;',
    ].join('\n'));
    cek('galat dari EMPAT baris berbeda dikumpulkan sekaligus',
        new Set(banyak.galat.map((g) => g.baris)).size === 3
        && [3, 4, 5].every((n) => banyak.galat.some((g) => g.baris === n)),
        'baris bergalat: ' + JSON.stringify([...new Set(banyak.galat.map((g) => g.baris))])
        + ' — berhenti di galat pertama membuat operator mengunggah ulang berkali-kali');
    cek('  baris yang sehat tetap terbaca', banyak.baris.some((b) => b.unit_rumah === 'A-5'));

    console.log('\n=== 6. Pemisah kolom dideteksi ===');

    const tsv = 'Unit Rumah\tLT\tLB\nA-1\t75\t36';
    cek('TAB (tempelan langsung dari Excel) terbaca',
        m.dariCsv(tsv).galat.length === 0 && m.dariCsv(tsv).baris[0].lt === 75);
    const koma = 'Unit Rumah,LT,LB\nA-1,75,36';
    cek('koma terbaca', m.dariCsv(koma).galat.length === 0 && m.dariCsv(koma).baris[0].lb === 36);
    cek('titik koma terbaca', m.dariCsv('Unit Rumah;LT;LB\nA-1;75;36').baris[0].lt === 75);
    cek('urutan kolom boleh berbeda dari template',
        (() => {
            const h = m.dariCsv('LB;Unit Rumah;LT\n36;A-9;75');

            return h.galat.length === 0 && h.baris[0].unit_rumah === 'A-9' && h.baris[0].lt === 75 && h.baris[0].lb === 36;
        })(), 'operator menyusun ulang kolom di Excel, dan itu wajar');

    console.log('\n=== 7. Template ===');

    const tpl = m.templateCsv();
    const hTpl = m.dariCsv(tpl);
    cek('template bisa langsung diimpor kembali tanpa galat', hTpl.galat.length === 0, JSON.stringify(hTpl.galat));
    cek('  memuat seluruh judul kolom', m.KOLOM.every((k) => tpl.includes(k.judul)));
    cek('  memberi contoh angka berkoma desimal', /87,5/.test(tpl),
        'contoh yang semuanya bulat tidak memperlihatkan bentuk desimal yang benar');

    console.log('\n=== 8. Perkabelan layar ===');

    const srcLayar = fs.readFileSync(LAYAR, 'utf8');
    const srcDialog = fs.readFileSync(DIALOG, 'utf8');
    cek('tombol massal terpasang DI SAMPING "Tambah Unit"',
        /Tambah Unit<\/Button>\s*\n\s*<ImporUnit/.test(srcLayar),
        'potongan: ' + (srcLayar.match(/Tambah Unit<\/Button>[\s\S]{0,80}/) || [''])[0].replace(/\s+/g, ' '));
    cek('  tombolnya berbunyi "Tambah Unit Massal"', srcDialog.includes('Tambah Unit Massal'));
    cek('  dan ada tombol Ekspor', /Ekspor/.test(srcDialog));
    // Impor mengisi DRAF — bukan menulis ke basis data lewat router.
    cek('impor mengisi draf, bukan menulis ke basis data',
        ! /router\.(post|put|patch|delete)/.test(srcDialog) && /emit\('impor'/.test(srcDialog),
        'simpanan Rincian COGS mengganti seluruh daftar; tulisan langsung akan tertimpa draf lama');
    cek('  layarnya menerima hasilnya lewat @impor', /@impor="terimaImpor"/.test(srcLayar));
    cek('  mode "tambah" MELEWATI kode yang sudah ada, tidak menimpanya',
        /if \(ada\.has\(String\(b\.unit_rumah \?\? ''\)\.toLowerCase\(\)\)\) continue;/.test(srcLayar),
        'menimpa akan menghapus status booked & pilihan costing unit yang HPP-nya sudah terkunci');
    cek('  tombol impor mati selama masih ada galat',
        /galatCsv\.value\.length === 0/.test(srcDialog) && /:disabled="! siap"/.test(srcDialog),
        'mengimpor sebagian membuat operator mengira semuanya masuk');
    // Dialognya kini punya DUA jalan masuk; impor CSV tinggal salah satunya.
    cek('  dialognya punya tab "Pilih dari Modul Unit" di samping "Impor CSV"',
        srcDialog.includes('Pilih dari Modul Unit') && srcDialog.includes('Impor CSV'));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exit(gagal === 0 ? 0 : 1);
}

main();
