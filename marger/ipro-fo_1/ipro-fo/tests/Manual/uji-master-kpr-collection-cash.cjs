// Master Data KPR — kelompok kolom "Collection Cash" di sisi LAYAR.
//
// Pasangan uji sisi servernya ada di tests/Manual/uji-master-kpr-collection-cash.php — itu yang
// membuktikan angkanya benar. Berkas ini menutup ujung yang tidak terlihat dari sana: kolomnya
// benar-benar dirender, tiap slot menunjuk indeks yang BERBEDA, dan jumlah slot di layar sama
// dengan CASH_SLOTS di controller.
//
// Slot yang semuanya menunjuk indeks yang sama adalah kegagalan paling berbahaya di sini:
// ketiga kolom menampilkan angka yang sama, tabelnya tetap rapi, dan tidak ada satu pun galat.
//
// Jalankan:  node tests/Manual/uji-master-kpr-collection-cash.cjs .
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.argv[2] || '.');
const V = path.join(ROOT, 'resources/js/Pages/BankLoan/MasterData/Index.vue');
const C = path.join(ROOT, 'app/Http/Controllers/Web/BankLoan/MasterDataKprController.php');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const src = fs.readFileSync(V, 'utf8');
const ctl = fs.readFileSync(C, 'utf8');
const kode = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

console.log('\n=== 1. Kelompok Collection Cash ada di layar ===');

const mGrup = /label: 'Collection Cash',[\s\S]{0,400}?\n    \},/.exec(kode);
cek('kelompok Collection Cash ditemukan', !! mGrup, 'kolomnya tidak dirender sama sekali');
const grup = mGrup ? mGrup[0] : '';

cek('isinya dibangun dari cashLabels', /items: cashLabels\.map\(/.test(grup), grup.slice(0, 120));
cek('tiap kolom berpasangan Tanggal + Nominal', /pairTN\(/.test(grup), 'bukan pasangan tanggal & nominal');
cek('membaca angsuran_cash dari baris', /r\.angsuran_cash\?\.\[/.test(grup), grup.slice(0, 160));

console.log('\n=== 2. Tiga slot, masing-masing indeks BERBEDA ===');

const mLabel = /const cashLabels = \[([^\]]*)\];/.exec(kode);
cek('daftar cashLabels ditemukan', !! mLabel);
const labels = mLabel ? (mLabel[1].match(/'([^']+)'/g) || []).map((x) => x.slice(1, -1)) : [];
cek('tiga slot di layar', labels.length === 3, JSON.stringify(labels));
cek('namanya Angsuran Cash 1..3',
    labels.join(' | ') === 'ANGSURAN CASH 1 | ANGSURAN CASH 2 | ANGSURAN CASH 3', JSON.stringify(labels));

// Indeksnya harus ikut i, bukan angka tetap — kalau tetap, ketiga kolom menampilkan angka
// yang sama dan tabelnya tetap terlihat wajar.
cek('indeks slot mengikuti urutan kolom (bukan angka tetap)',
    /r\.angsuran_cash\?\.\[i\]/.test(grup), 'ketiga kolom akan menampilkan angka yang sama');

console.log('\n=== 3. Jumlah slot layar = jumlah slot server ===');

const mSlot = /private const CASH_SLOTS = (\d+);/.exec(ctl);
cek('CASH_SLOTS ditemukan di controller', !! mSlot);
cek('jumlahnya sama dengan cashLabels', mSlot && Number(mSlot[1]) === labels.length,
    'server=' + (mSlot ? mSlot[1] : '?') + ' layar=' + labels.length);

console.log('\n=== 4. Tidak mengganggu kelompok lain ===');

for (const g of ['Collection UTJ & DP', 'Collection Retensi', 'Master Unit', 'Proses KPR']) {
    cek('kelompok "' + g + '" tetap ada', kode.includes("label: '" + g + "'"));
}
cek('Collection Cash duduk antara UTJ & DP dan Retensi',
    kode.indexOf("label: 'Collection UTJ & DP'") < kode.indexOf("label: 'Collection Cash'")
    && kode.indexOf("label: 'Collection Cash'") < kode.indexOf("label: 'Collection Retensi'"),
    'urutan kelompoknya tidak seperti yang dimaksud');
cek('keterangan halaman menyebut Collection Cash', /Collection Cash/.test(src));

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
