// Master Data KPR — urutan kolom kelompok "Master Unit": Nama Customer duduk TEPAT setelah
// No. SPR, bukan di belakang Kode Unit.
//
// KENAPA URUTANNYA PENTING, bukan sekadar selera: tabel ini membekukan DUA kolom pertama saja
// saat digulir ke kanan (freezeCols: 2 pada MasterDataKprController). Kolom ketiga dan seterusnya
// ikut bergeser keluar layar, jadi nama customer yang duduk di posisi ketiga hilang persis ketika
// pembacaan berpindah ke kolom-kolom jauh — saat itulah justru paling dibutuhkan untuk tahu baris
// siapa yang sedang dibaca.
//
// Urutan di sini juga menentukan urutan UNDUHAN EXCEL: export() di controller menyusun berkasnya
// dari payload yang dikirim layar, bukan dari daftar kolomnya sendiri. Satu daftar, dua keluaran.
//
// Jalankan:  node tests/Manual/uji-master-kpr-urutan-kolom.cjs .
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

/** Daftar kolom satu kelompok, apa adanya dari definisinya. */
function kolomKelompok(label) {
    const i = kode.indexOf("label: '" + label + "'");
    if (i < 0) return null;
    const mulai = kode.indexOf('items: [', i);
    if (mulai < 0) return null;
    let j = kode.indexOf('[', mulai), dalam = 0, akhir = j;
    for (; akhir < kode.length; akhir++) {
        if (kode[akhir] === '[') dalam++;
        else if (kode[akhir] === ']') { dalam--; if (dalam === 0) break; }
    }

    return [...kode.slice(j, akhir).matchAll(/one\('([^']+)'/g)].map((m) => m[1]);
}

console.log('\n=== 1. Nama Customer tepat setelah No. SPR ===');

const mu = kolomKelompok('Master Unit');
cek('kelompok Master Unit terbaca', !! mu && mu.length > 3, JSON.stringify(mu));
cek('tiga kolom pertamanya: No. SPR → Nama Customer → Kode Unit',
    mu.slice(0, 3).join(' → ') === 'No. SPR → Nama Customer → Kode Unit',
    JSON.stringify(mu.slice(0, 4)));

const iSpr = mu.indexOf('No. SPR');
const iCust = mu.indexOf('Nama Customer');
const iUnit = mu.indexOf('Kode Unit');
cek('Nama Customer TEPAT di sebelah kanan No. SPR', iCust === iSpr + 1, 'spr=' + iSpr + ' customer=' + iCust);
cek('Kode Unit bergeser ke kanan Nama Customer', iUnit === iCust + 1, 'unit=' + iUnit);
cek('tidak ada kolom yang hilang saat dipindah', mu.length >= 11, String(mu.length));
cek('Tipe Rumah tetap mengikuti Kode Unit', mu[iUnit + 1] === 'Tipe Rumah', String(mu[iUnit + 1]));

console.log('\n=== 2. Kolom yang dibekukan memang dua ===');

// Kalau freezeCols berubah, alasan urutan ini ikut berubah — dan komentarnya jadi menyesatkan.
const mFreeze = /freezeCols:\s*(\d+)/.exec(ctl);
cek('freezeCols ditemukan di controller', !! mFreeze);
cek('dua kolom pertama yang dibekukan (No & No. SPR)', mFreeze && Number(mFreeze[1]) === 2,
    mFreeze ? mFreeze[1] : '(tidak ada)');

console.log('\n=== 3. Unduhan Excel ikut urutan yang sama ===');

// export() menyusun berkasnya dari payload layar; kalau ia punya daftar kolomnya sendiri,
// urutan di layar dan di Excel bisa berbeda tanpa satu pun tanda.
cek('export memakai headers kiriman layar', /'headers'\s*=>\s*\['required', 'array'/.test(ctl),
    'controller menyusun kolomnya sendiri — urutan layar & Excel bisa berbeda');
cek('controller tidak punya daftar nama kolom Master Unit sendiri',
    ! /'Nama Customer'/.test(ctl) && ! /'Kode Unit'/.test(ctl),
    'ada dua sumber urutan kolom');

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
