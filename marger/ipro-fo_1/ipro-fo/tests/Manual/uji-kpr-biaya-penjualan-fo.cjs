// Sisi FO — stempel SPR & kunci kolom pada antrean BLT/Komisi.
//
// KENAPA TERPISAH DARI uji-kpr-biaya-penjualan.php: aplikasi FO berada di ROOT repo, di luar
// folder BO, dan kontainer BO tidak bisa membacanya. Bagian itu akan selamanya "dilewati" bila
// dipaksakan di sana — jadi diperiksa dari host, tempat kedua aplikasi sama-sama terjangkau.
//
// YANG DIJAGA: tanpa booking_id, baris BLT/Komisi tetap terbit sebagai hutang AP tetapi kolom
// Rincian Biaya Penjualan di Master Data KPR TIDAK PERNAH terisi — tanpa galat apa pun yang
// menandainya. Dan tanpa kpr_biaya_key, BO harus menebak jenjangnya dari teks keterangan.
//
// Jalankan:  node tests/Manual/uji-kpr-biaya-penjualan-fo.cjs .
const fs = require('fs');
const path = require('path');

const ROOT_BO = path.resolve(process.argv[2] || '.');
// BO ada di <repo>/marger/ipro-fo_1/ipro-fo — naik tiga tingkat untuk sampai ke root repo (FO).
const ROOT_FO = path.resolve(ROOT_BO, '../../..');

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

function baca(rel) {
    const p = path.join(ROOT_FO, rel);

    return fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : null;
}

const poster = baca('app/Modules/BO/IproBridge/Services/IproApPoster.php');
const blt = baca('app/Filament/Fo/Pages/RealisasiBlt.php');
const komisi = baca('app/Filament/Fo/Pages/RealisasiKomisi.php');

console.log('\n=== 0. Berkas FO terjangkau ===');
cek('IproApPoster.php ditemukan', poster !== null, path.join(ROOT_FO, 'app/Modules/BO/...'));
cek('RealisasiBlt.php ditemukan', blt !== null);
cek('RealisasiKomisi.php ditemukan', komisi !== null);
if (! poster || ! blt || ! komisi) {
    console.log('\nBerkas FO tidak lengkap — uji dihentikan.');
    process.exitCode = 1;

    return;
}

console.log('\n=== 1. queueInsentifRow membawa SPR & kunci kolom ===');

cek('menerima parameter noKontrak', poster.includes('?string $noKontrak = null,'));
cek('menerima parameter biayaKey', poster.includes('?string $biayaKey = null,'));

/* Diperiksa pada BADAN queueInsentifRow saja. queueGmc punya baris booking_id yang identik
   kata demi kata, jadi mencarinya se-berkas tetap lulus meski baris itu dicabut dari jalur
   BLT/Komisi — uji mutasi menemukan celah ini. */
const iIns = poster.indexOf('private function queueInsentifRow(');
const iAkhir = poster.indexOf('private function pembeliUnitBatch(');
const badanIns = iIns >= 0 && iAkhir > iIns ? poster.slice(iIns, iAkhir) : '';
cek('badan queueInsentifRow terbaca', badanIns !== '');
cek('booking_id ikut diantrekan (di jalur BLT/Komisi)',
    /'booking_id'\s*=>\s*\$noKontrak \? \$this->iproBookingIdByNoKontrak\(\$noKontrak\) : null,/.test(badanIns),
    'tidak ada di badan queueInsentifRow');
cek('kpr_biaya_key ikut diantrekan', /'kpr_biaya_key'\s*=>\s*\$biayaKey,/.test(badanIns));

console.log('\n=== 2. Jenjang BLT dipetakan ke kolomnya ===');

// Diperiksa pada badan queueBltRow saja — "blt_sales" dsb. juga muncul di komentar.
const iBlt = poster.indexOf('public function queueBltRow(');
const iKom = poster.indexOf('public function queueKomisiRow(');
const badanBlt = iBlt >= 0 && iKom > iBlt ? poster.slice(iBlt, iKom) : '';
cek('badan queueBltRow terbaca', badanBlt !== '');
cek('KOORDINATOR → blt_koord', /'KOORDINATOR'\s*=>\s*'blt_koord'/.test(badanBlt));
cek('KANTOR → blt_kantor', /'KANTOR'\s*=>\s*'blt_kantor'/.test(badanBlt));
cek('jenjang lain → blt_sales', /default\s*=>\s*'blt_sales'/.test(badanBlt));
cek('kuncinya diteruskan ke queueInsentifRow', /\$tgl,\s*\$noKontrak,\s*\$biayaKey,/.test(badanBlt));

console.log('\n=== 3. Komisi selalu satu kolom ===');

const badanKom = iKom >= 0 ? poster.slice(iKom, iKom + 1200) : '';
cek('komisi distempel kolom komisi', /\$tgl,\s*\$noKontrak,\s*'komisi',/.test(badanKom));

console.log('\n=== 4. Kedua layar realisasi mengirim no. kontraknya ===');

// Inilah yang menautkan insentif ke SPR. Tanpa argumen ini, seluruh rantai di atas tetap diam.
cek('Realisasi BLT mengirim no_kontrak', /\$b->kontrak\?->no_kontrak,/.test(blt));
cek('Realisasi Komisi mengirim no_kontrak', /\$k->kontrak\?->no_kontrak,/.test(komisi));

// Argumennya harus berada di dalam pemanggilan queue-nya, bukan sekadar ada di berkas.
const panggilBlt = (blt.match(/queueBltRow\([\s\S]*?\);/) || [''])[0];
const panggilKom = (komisi.match(/queueKomisiRow\([\s\S]*?\);/) || [''])[0];
cek('no_kontrak berada di dalam panggilan queueBltRow', panggilBlt.includes('no_kontrak'), panggilBlt.slice(0, 80));
cek('no_kontrak berada di dalam panggilan queueKomisiRow', panggilKom.includes('no_kontrak'), panggilKom.slice(0, 80));

// Urutannya: no_kontrak harus SESUDAH tanggal — parameter posisional, tertukar berarti tanggal
// terbaca sebagai no. kontrak dan booking-nya tidak akan pernah ketemu.
cek('no_kontrak dikirim setelah tanggal (BLT)',
    panggilBlt.indexOf('toDateString()') < panggilBlt.indexOf('no_kontrak'));
cek('no_kontrak dikirim setelah tanggal (Komisi)',
    panggilKom.indexOf('toDateString()') < panggilKom.indexOf('no_kontrak'));

console.log('\n=== HASIL ===');
console.log('  lulus: ' + lulus + ' · gagal: ' + gagal);
console.log(gagal === 0 ? '  SEMUA LULUS' : '  ADA YANG GAGAL');
process.exitCode = gagal === 0 ? 0 : 1;
