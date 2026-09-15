// Akun BEBAN untuk PPh DITANGGUNG mengikuti JENIS PPh — sisi layar.
//
// PPh 21 → BIAYA PPH 21 (70101010109) · PPh 23 → BIAYA PPH 23 (70101010107)
// PPh Pasal 4(2) → BIAYA PPH 4 AYAT 2 (70101010108)
//
// Inilah satu-satunya keputusan pada rantai ini yang bisa salah TANPA memunculkan galat apa pun:
// akun yang keliru tetap membentuk baris, jurnalnya tetap balance, dan angkanya tetap benar.
// Yang ketahuan belakangan hanya beban PPh 21, 23, dan 4(2) yang menumpuk di satu akun dan tidak
// bisa dipisahkan lagi di buku besar maupun SPT.
//
// Pasangan uji sisi server ada di tests/Manual/uji-coa-biaya-pph-ditanggung.php — ia yang
// membuktikan controller mengirim pemetaan yang sama dengan yang dipakai jalur otomatis.
//
// Jalankan:  node tests/Manual/uji-coa-biaya-pph-ditanggung.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '.');
const MODUL = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/strukturJurnal.ts');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Form.vue');
const CONFIG = path.join(ROOT, 'config/business_support.php');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

async function main() {
    const b = await esbuild.build({ entryPoints: [MODUL], bundle: true, write: false, format: 'cjs', platform: 'node' });
    const m = { exports: {} };
    new Function('module', 'exports', 'require', b.outputFiles[0].text)(m, m.exports, require);
    const { akunBiayaPphDitanggung, bangunStrukturJurnal, akunKurang } = m.exports;

    // Id akun tiruan — yang diuji pemetaannya, bukan angkanya.
    const PETA = { pph21: 419, pph23: 417, pph4_2: 418 };
    const CADANGAN = 221;

    console.log('\n=== 1. Tiap jenis PPh memakai akun bebannya sendiri ===');

    cek('PPh 21 → akun Biaya PPh 21', akunBiayaPphDitanggung('pph21', PETA, CADANGAN) === 419);
    cek('PPh 23 → akun Biaya PPh 23', akunBiayaPphDitanggung('pph23', PETA, CADANGAN) === 417);
    cek('PPh Pasal 4(2) → akun Biaya PPh 4 Ayat 2', akunBiayaPphDitanggung('pph4_2', PETA, CADANGAN) === 418);
    // Kalau ketiganya memulangkan akun yang sama, pemetaannya tidak berarti apa-apa.
    cek('ketiganya BERBEDA satu sama lain',
        new Set(['pph21', 'pph23', 'pph4_2'].map((j) => akunBiayaPphDitanggung(j, PETA, CADANGAN))).size === 3);
    cek('tidak satu pun jatuh ke akun beban umum',
        ! ['pph21', 'pph23', 'pph4_2'].some((j) => akunBiayaPphDitanggung(j, PETA, CADANGAN) === CADANGAN));

    console.log('\n=== 2. Jenis belum dipilih → akun cadangan, bukan kosong ===');

    // Jenis PPh tidak diwajibkan gerbang mana pun, sedangkan tarifnya bisa diisi manual.
    // Memulangkan null di sini membuat baris Biaya PPh tidak terbentuk dan jurnalnya tidak balance.
    for (const kosong of ['', null, undefined]) {
        cek('jenis ' + JSON.stringify(kosong) + ' → cadangan', akunBiayaPphDitanggung(kosong, PETA, CADANGAN) === CADANGAN);
    }
    cek('jenis asing → cadangan', akunBiayaPphDitanggung('pph_entah', PETA, CADANGAN) === CADANGAN);
    cek('peta kosong → cadangan', akunBiayaPphDitanggung('pph21', {}, CADANGAN) === CADANGAN);
    cek('peta null → cadangan', akunBiayaPphDitanggung('pph21', null, CADANGAN) === CADANGAN);
    cek('tanpa peta DAN tanpa cadangan → null (dilaporkan kurang, bukan akun asal)',
        akunBiayaPphDitanggung('pph21', null, null) === null);
    cek('jenis dikenal tapi akunnya belum ada di CoA → cadangan',
        akunBiayaPphDitanggung('pph23', { pph23: null }, CADANGAN) === CADANGAN);
    // Membedakan ?? dari ||: id 0 tidak sah, dan harus terbaca apa adanya supaya akunKurang
    // melaporkannya. Dengan ||, ia diam-diam jatuh ke cadangan dan barisnya terbentuk di akun
    // beban umum seolah semuanya baik-baik saja.
    cek('id 0 terbaca apa adanya, bukan diam-diam jatuh ke cadangan',
        akunBiayaPphDitanggung('pph21', { pph21: 0 }, CADANGAN) === 0,
        String(akunBiayaPphDitanggung('pph21', { pph21: 0 }, CADANGAN)));

    console.log('\n=== 3. Hasilnya benar-benar menentukan baris jurnalnya ===');

    const bahan = (jenis) => ({
        dpp: 1000, ppn: 0, pph: 25, ppnAktif: false, pphMode: 'borne',
        akun: {
            beban: 11, hutangUsaha: 21, ppnMasukan: null, hutangPph: 31,
            biayaPph: akunBiayaPphDitanggung(jenis, PETA, CADANGAN),
        },
        uraian: { beban: 'Jasa', ppn: '', hutangUsaha: 'Hutang', pphDebit: 'Biaya PPh ditanggung', pphKredit: 'Hutang PPh ditanggung' },
    });
    const barisBiaya = (jenis) => bangunStrukturJurnal(bahan(jenis)).find((r) => r.description === 'Biaya PPh ditanggung');

    cek('baris Biaya PPh untuk PPh 21 memakai akun 419', barisBiaya('pph21')?.account_id === 419,
        JSON.stringify(barisBiaya('pph21')));
    cek('baris Biaya PPh untuk PPh 23 memakai akun 417', barisBiaya('pph23')?.account_id === 417);
    cek('baris Biaya PPh untuk PPh 4(2) memakai akun 418', barisBiaya('pph4_2')?.account_id === 418);
    cek('barisnya di sisi DEBIT sebesar nilai PPh', barisBiaya('pph21')?.side === 'debit' && barisBiaya('pph21')?.nilai === 25);
    // Hutang Usaha TIDAK berkurang pada mode ditanggung — supplier tetap dibayar penuh.
    const hutang = bangunStrukturJurnal(bahan('pph21')).find((r) => r.account_id === 21);
    cek('Hutang Usaha tetap penuh 1.000 (supplier dibayar penuh)', hutang?.nilai === 1000, JSON.stringify(hutang));

    console.log('\n=== 4. Akun yang tidak tersedia tetap dilaporkan, bukan didiamkan ===');

    const bahanTanpaAkun = bahan('pph21');
    bahanTanpaAkun.akun.biayaPph = null;
    cek('tanpa akun Biaya PPh dilaporkan kurang', akunKurang(bahanTanpaAkun).includes('Biaya PPh'),
        JSON.stringify(akunKurang(bahanTanpaAkun)));
    cek('dengan akun Biaya PPh tidak dilaporkan kurang', ! akunKurang(bahan('pph21')).includes('Biaya PPh'));

    console.log('\n=== 5. Layar benar-benar memakai aturan ini ===');

    const srcForm = fs.readFileSync(FORM, 'utf8');
    cek('Form.vue mengimpor akunBiayaPphDitanggung', /akunBiayaPphDitanggung/.test(srcForm));
    cek('biayaPph pada bahan struktur memakai hasilnya', /biayaPph:\s*akunBiayaPph\.value/.test(srcForm),
        'struktur masih memakai satu akun tetap');
    // Ketiganya harus ikut: jenis PPh, peta per jenis, dan cadangannya. Melewatkan form.pph_type
    // membuat computed-nya tidak reaktif dan struktur tidak berubah saat jenis PPh diganti.
    cek('perhitungannya memakai jenis PPh, peta, dan cadangan',
        /akunBiayaPphDitanggung\(form\.pph_type,\s*props\.pphBorneDefaults,\s*props\.pphBorneExpense\?\.id\)/.test(srcForm),
        'salah satu argumennya tidak diteruskan');
    cek('prop pphBorneDefaults dideklarasikan', /pphBorneDefaults:\s*Record<string, number \| null>/.test(srcForm));

    console.log('\n=== 6. Kode akun pada config sesuai permintaan ===');

    const srcCfg = fs.readFileSync(CONFIG, 'utf8');
    const blok = /'pph_borne_expense'\s*=>\s*\[([\s\S]*?)\]/.exec(srcCfg);
    cek('config memetakan per jenis PPh (bukan satu kode tunggal)', !! blok,
        'pph_borne_expense masih berupa satu kode');
    const kode = (j) => (new RegExp("'" + j + "'\\s*=>\\s*'(\\d+)'").exec(blok ? blok[1] : '') || [])[1];
    cek('pph21 → 70101010109 (BIAYA PPH 21)', kode('pph21') === '70101010109', String(kode('pph21')));
    cek('pph23 → 70101010107 (BIAYA PPH 23)', kode('pph23') === '70101010107', String(kode('pph23')));
    cek('pph4_2 → 70101010108 (BIAYA PPH 4 AYAT 2)', kode('pph4_2') === '70101010108', String(kode('pph4_2')));
    cek('cadangan tetap akun beban umum yang lama', /'pph_borne_expense_default'\s*=>\s*'50302020228'/.test(srcCfg));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
