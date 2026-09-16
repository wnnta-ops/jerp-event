// Mesin hitung Kalkulator KPR — dibuktikan SAMA PERSIS dengan aplikasi aslinya.
//
// Ini uji terpenting dari seluruh fitur Kalkulator KPR. Angsuran, plafon, dan gaji minimum
// yang meleset TIDAK memunculkan galat apa pun: angkanya tetap terlihat masuk akal, tetap
// tercetak rapi, dan baru ketahuan salah ketika seorang nasabah membandingkannya dengan
// perhitungan bank. Satu Math.round yang bergeser satu tempat sudah cukup.
//
// Karena itu yang dijaga bukan "kira-kira mirip", melainkan SIDIK JARI:
// 150 kombinasi (5 bank × seluruh skemanya × tenor 10/15/20/25/30 th × subsidi on/off),
// setiap barisnya (hingga 360 baris per kombinasi) diringkas jadi satu digest SHA-256.
// Digest mesin ASLI — dihitung langsung dari site/calc.js milik aplikasi Java Residence —
// adalah fa564e9b. Port di resources/js/Pages/BankLoan/Kalkulator/kprCalc.ts HARUS
// menghasilkan digest yang sama.
//
// Digest saja tidak cukup: kalau ia berubah, ia tidak memberi tahu APA yang berubah.
// Karena itu di bawahnya ada pemeriksaan bernama untuk setiap aturan yang paling mudah
// rusak saat kode "dirapikan".
//
// Jalankan:  node tests/Manual/uji-kalkulator-kpr-mesin.cjs .
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '.');
const MESIN = path.join(ROOT, 'resources/js/Pages/BankLoan/Kalkulator/kprCalc.ts');
const KONFIG = path.join(ROOT, 'database/seeders/data/kpr-calculator-default.json');

/** Sidik jari mesin ASLI (site/calc.js aplikasi Kalkulator KPR Java Residence). */
const GOLDEN = 'fa564e9b';
const GOLDEN_KOMBINASI = 150;

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const hasil = esbuild.buildSync({
    entryPoints: [MESIN], bundle: true, write: false, format: 'cjs', platform: 'node',
});
const modul = { exports: {} };
new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
const K = modul.exports;

const cfg = JSON.parse(fs.readFileSync(KONFIG, 'utf8'));

console.log('\n=== 1. Sidik jari 150 kombinasi = mesin asli ===');

const bagian = [];
for (const b of cfg.banks) {
    for (const s of b.schemes) {
        for (const th of [10, 15, 20, 25, 30]) {
            for (const sub of [0, 2]) {
                const rows = K.buildAmortization(s, 500000000, th * 12, sub, 12);
                const yearly = K.buildYearlySummary(rows);
                const ring = K.quickSummary(s, 500000000, th * 12, sub, 12);
                bagian.push([b.id, s.id, th, sub,
                    rows.length,
                    rows.map((r) => [r.month, r.payment.toFixed(6), r.principal.toFixed(6), r.interest.toFixed(6), r.balance.toFixed(6), r.rateLabel].join('|')).join(';'),
                    yearly.map((y) => [y.year, y.firstMonthPayment.toFixed(6), y.totalPayment.toFixed(6), y.totalInterest.toFixed(6), y.endBalance.toFixed(6), y.rateLabel].join('|')).join(';'),
                    ring.firstPayment.toFixed(6), ring.lastPayment.toFixed(6), ring.changesOverTime,
                    K.minSalaryFor(rows.length ? rows[rows.length - 1].payment : 0).toFixed(6),
                ].join('#'));
            }
        }
    }
}
const digest = crypto.createHash('sha256').update(bagian.join('\n')).digest('hex').slice(0, 8);

cek('jumlah kombinasinya ' + GOLDEN_KOMBINASI, bagian.length === GOLDEN_KOMBINASI, String(bagian.length));
cek('digest = ' + GOLDEN + ' (mesin asli)', digest === GOLDEN,
    'dapat ' + digest + ' — mesin hitung SUDAH BERBEDA dari aplikasi aslinya');

console.log('\n=== 2. Aturan yang paling mudah rusak saat kode dirapikan ===');

const skemaFF = { id: 'ff', type: 'fixed_floating', label: 'FF', fixedYears: 3, fixedRatePercent: 3.65, floatingRatePercent: 13 };
const rows = K.buildAmortization(skemaFF, 500000000, 240, 0, 12);

cek('jumlah barisnya = tenor dalam bulan', rows.length === 240, String(rows.length));
// Bulan terakhir memaksa pokok = sisa saldo; tanpa itu selalu tersisa recehan yang
// membuat "Sisa Pinjaman" tidak pernah benar-benar nol.
cek('lunas TEPAT Rp0 pada bulan terakhir', rows[rows.length - 1].balance === 0,
    String(rows[rows.length - 1].balance));
cek('saldo menurun monoton', rows.every((r, i) => i === 0 || r.balance <= rows[i - 1].balance));
cek('angsuran = pokok + bunga di setiap baris',
    rows.every((r) => Math.abs(r.payment - (r.principal + r.interest)) < 1e-9));

// Anuitas DIHITUNG ULANG saat masuk periode floating, memakai sisa pokok saat itu.
// Kalau tidak, angsuran floating dihitung dari pokok AWAL dan angkanya terlalu besar.
const bln36 = rows[35].payment, bln37 = rows[36].payment;
cek('angsuran berubah saat masuk floating (bulan 37)', Math.abs(bln37 - bln36) > 1,
    'bln36=' + bln36.toFixed(2) + ' bln37=' + bln37.toFixed(2));
const sisaBln36 = rows[35].balance;
const seharusnya = K.annuityPayment(sisaBln36, 13 / 100 / 12, 240 - 36);
cek('angsuran floating dihitung dari SISA pokok, bukan pokok awal',
    Math.abs(bln37 - seharusnya) < 1e-6,
    'dapat ' + bln37.toFixed(6) + ' seharusnya ' + seharusnya.toFixed(6));
const dariPokokAwal = K.annuityPayment(500000000, 13 / 100 / 12, 240 - 36);
cek('  dan angkanya memang BERBEDA dari versi pokok-awal', Math.abs(dariPokokAwal - seharusnya) > 1,
    'fikstur ini tidak membedakan apa-apa');

cek('36 bulan pertama berlabel fixed', rows.slice(0, 36).every((r) => r.rateLabel === '3.65% (fixed)'), rows[0].rateLabel);
cek('sesudahnya berlabel floating', rows.slice(36).every((r) => r.rateLabel === '13.00% (floating)'), rows[36].rateLabel);
cek('label persen memakai dua desimal', rows[0].rateLabel.includes('3.65%') && rows[36].rateLabel.includes('13.00%'));

console.log('\n=== 3. Subsidi bunga tahun pertama (khusus BTN) ===');

const sub = K.buildAmortization(skemaFF, 500000000, 240, 2, 12);
cek('jumlah baris TIDAK berubah oleh subsidi', sub.length === rows.length, String(sub.length));
cek('12 bulan pertama kena potongan 2%', sub.slice(0, 12).every((r) => r.rateLabel.includes('(subsidi -2%)')), sub[0].rateLabel);
cek('bulan ke-13 sudah tidak kena', ! sub[12].rateLabel.includes('subsidi'), sub[12].rateLabel);
cek('bunganya memang lebih kecil di bulan pertama', sub[0].interest < rows[0].interest,
    'subsidi=' + sub[0].interest.toFixed(2) + ' normal=' + rows[0].interest.toFixed(2));
// 3,65% − 2% = 1,65%
cek('tarif tersubsidi = tarif − 2 (bukan tarif × 0,98)',
    Math.abs(sub[0].interest - 500000000 * (1.65 / 100 / 12)) < 1e-6,
    sub[0].interest.toFixed(6));
cek('subsidi tetap lunas TEPAT Rp0', sub[sub.length - 1].balance === 0);

console.log('\n=== 4. Skema berjenjang (tiered) ===');

const skemaT = { id: 't', type: 'tiered', label: 'T', floatingRatePercent: 13,
    tiers: [{ years: 3, ratePercent: 5.5, tierLabel: 'Fixed' }, { years: 2, ratePercent: 8.5, tierLabel: 'Cap' }, { years: 3, ratePercent: 9.95, tierLabel: 'Cap' }] };
const rt = K.buildAmortization(skemaT, 500000000, 240, 0, 12);
cek('tahapnya berurutan sesuai tiers', rt[0].rateLabel === 'Fixed 5.50%' && rt[36].rateLabel === 'Cap 8.50%' && rt[60].rateLabel === 'Cap 9.95%',
    [rt[0].rateLabel, rt[36].rateLabel, rt[60].rateLabel].join(' / '));
cek('sesudah semua tahap → floating', rt[96].rateLabel === '13.00% (floating)', rt[96].rateLabel);
cek('total bulannya tetap penuh', rt.length === 240, String(rt.length));
// Tenor lebih pendek daripada total tahapan: tahap terakhir harus TERPOTONG, bukan meluber.
const rtPendek = K.buildAmortization(skemaT, 500000000, 48, 0, 12);
cek('tenor 4 th memotong tahapan, tidak meluber', rtPendek.length === 48, String(rtPendek.length));
cek('  dan tetap lunas Rp0', rtPendek[rtPendek.length - 1].balance === 0);

console.log('\n=== 5. Ringkasan per tahun & gaji minimum ===');

const tahunan = K.buildYearlySummary(rows);
cek('20 tahun untuk tenor 240 bulan', tahunan.length === 20, String(tahunan.length));
cek('angsuran/bulan = angsuran BULAN PERTAMA tahun itu, bukan rata-rata',
    Math.abs(tahunan[0].firstMonthPayment - rows[0].payment) < 1e-9);
/* Pembeda "bulan pertama vs rata-rata" HANYA ada bila tarifnya berubah DI TENGAH tahun.
   Pada skema fixed 3 tahun perubahannya jatuh tepat di batas tahun, sehingga rata-rata
   setahun sama persis dengan angsuran bulan pertama — fikstur seperti itu tidak menguji
   apa pun. Karena itu di sini fixed-nya 2,5 tahun: tahun ke-3 berisi 6 bulan fixed lalu
   6 bulan floating. */
const tengahTahun = K.buildYearlySummary(
    K.buildAmortization({ id: 'ff2', type: 'fixed_floating', label: 'FF', fixedYears: 2.5, fixedRatePercent: 3.65, floatingRatePercent: 13 }, 500000000, 240, 0, 12));
cek('  pada tahun yang tarifnya berubah di tengah, rata-rata memang BERBEDA',
    Math.abs(tengahTahun[2].totalPayment / 12 - tengahTahun[2].firstMonthPayment) > 1,
    'bulan1=' + tengahTahun[2].firstMonthPayment.toFixed(2) + ' rata2=' + (tengahTahun[2].totalPayment / 12).toFixed(2));
cek('total setahun = jumlah 12 baris bulanan',
    Math.abs(tahunan[0].totalPayment - rows.slice(0, 12).reduce((s, r) => s + r.payment, 0)) < 1e-9);
cek('sisa pinjaman akhir tahun = saldo bulan ke-12', Math.abs(tahunan[0].endBalance - rows[11].balance) < 1e-9);
cek('sisa pinjaman tahun terakhir = 0', tahunan[tahunan.length - 1].endBalance === 0);

const gaji = K.minSalaryFor(rows[rows.length - 1].payment);
cek('gaji minimum = angsuran BARIS TERAKHIR × 2', Math.abs(gaji - rows[rows.length - 1].payment * 2) < 1e-9);
// Baris terakhir ≠ angsuran pertama pada skema berjenjang — kalau dipakai angsuran pertama,
// gaji minimum akan jauh lebih kecil dan nasabah disarankan mengambil KPR yang tak sanggup.
cek('  dan itu BUKAN angsuran pertama', Math.abs(gaji - rows[0].payment * 2) > 1,
    'pertama=' + rows[0].payment.toFixed(2) + ' terakhir=' + rows[rows.length - 1].payment.toFixed(2));

console.log('\n=== 6. Anuitas tetap & penjaga tepi ===');

const anu = K.buildAmortization({ id: 'a', type: 'anuitas', label: 'A', ratePercent: 9.5 }, 500000000, 120, 0, 12);
cek('anuitas: angsuran tetap sepanjang tenor',
    anu.slice(0, -1).every((r) => Math.abs(r.payment - anu[0].payment) < 1e-6), 'ada yang berubah di tengah');
cek('anuitas: quickSummary menandai TIDAK berubah', K.quickSummary({ id: 'a', type: 'anuitas', ratePercent: 9.5 }, 500000000, 120, 0, 12).changesOverTime === false);
cek('fixed→floating: quickSummary menandai BERUBAH', K.quickSummary(skemaFF, 500000000, 240, 0, 12).changesOverTime === true);

cek('bunga 0% → angsuran = pokok/tenor', Math.abs(K.annuityPayment(120000000, 0, 120) - 1000000) < 1e-9);
cek('tenor 0 bulan → 0', K.annuityPayment(100000000, 0.01, 0) === 0);
cek('skema efektif tidak melewati mesin periode',
    K.schemeToPeriods({ type: 'efektif', ratePercent: 9 }) === null);
const ef = K.buildAmortization({ type: 'efektif', ratePercent: 9 }, 120000000, 120, 0, 12);
cek('efektif: pokok rata tiap bulan', ef.every((r) => Math.abs(r.principal - 1000000) < 1e-9));
cek('efektif: angsuran MENURUN', ef[0].payment > ef[ef.length - 1].payment);

console.log('\n=== 7. Format rupiah ===');

cek('idr membulatkan & memakai titik ribuan', K.idr(1234567.89) === 'Rp1.234.568', K.idr(1234567.89));
cek('idr(0) = Rp0', K.idr(0) === 'Rp0', K.idr(0));
cek('idr tanpa desimal', ! K.idr(1234.56).includes(','), K.idr(1234.56));

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
