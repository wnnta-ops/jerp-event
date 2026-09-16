// Registrasi Pengeluaran Kas & Bank (AP) — "Kuitansi Hutang": kolom Dari Kas/Bank tidak lagi
// punya bawaan, dan tombol "Simpan & Buat Jurnal" terkunci sampai rekeningnya dipilih.
//
// KENAPA BAWAANNYA DIHILANGKAN. Sebelumnya terisi otomatis dari config gl_accounts.cash, dan itu
// keliru di dua sisi:
//
//  1. UANGNYA KELUAR DARI REKENING YANG TIDAK PERNAH DIPILIH SIAPA PUN. Kuitansi yang disimpan
//     tergesa akan mendebet rekening bawaan; jurnalnya tetap balance, jadi tidak ada satu pun
//     galat yang memberi tahu — yang ketahuan belakangan hanya saldo bank yang tidak cocok.
//  2. BAWAANNYA BAHKAN TIDAK SELALU SAH. Alat transaksi berbawaan 'transfer', yang daftar
//     pilihannya HANYA rekening bank, sedangkan gl_accounts.cash menunjuk akun KAS. watch pada
//     alat_transaksi hanya berjalan saat alatnya DIUBAH, jadi nilai yang bahkan tidak ada di
//     daftar pilihannya itu bertahan sampai ditolak server.
//
// Servernya memang sudah menolak (cash_account_id => required), tetapi penolakan itu baru terjadi
// SESUDAH tombol ditekan. Yang diuji di sini: layarnya tidak lagi menawarkan jalan ke sana.
//
// Jalankan:  node tests/Manual/uji-kas-bank-ap-wajib.cjs .
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.argv[2] || '.');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Kuitansi/Form.vue');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BusinessSupport/Payable/ApKuitansiController.php');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const src = fs.readFileSync(FORM, 'utf8');
const ctl = fs.readFileSync(CTL, 'utf8');
const bersih = (s) => s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
const kode = bersih(src);
const kodeCtl = bersih(ctl);

console.log('\n=== 1. Kas/Bank tidak lagi punya bawaan ===');

cek('nilai awal cash_account_id kosong', /cash_account_id:\s*''/.test(kode),
    (/cash_account_id:\s*[^,]+/.exec(kode) || [])[0]);
cek('tidak lagi membaca props.defaultCash', ! /props\.defaultCash/.test(kode),
    'masih terisi otomatis');
cek('prop defaultCash ikut dihapus dari layar', ! /defaultCash/.test(kode),
    'prop menganggur — akan menggoda diisikan lagi');
cek('controller tidak lagi mengirim defaultCash ke layar ini', ! /'defaultCash'/.test(kodeCtl),
    'propnya masih dikirim');

console.log('\n=== 2. Tombol Simpan terkunci sampai Kas/Bank dipilih ===');

const mBtn = /<Button type="submit" :disabled="([^"]+)"/.exec(kode);
cek('tombol Simpan & Buat Jurnal ditemukan', !! mBtn);
const syarat = mBtn ? mBtn[1] : '';
cek('syarat matinya menyebut cash_account_id', /cash_account_id/.test(syarat), syarat);

/* Syaratnya DIJALANKAN, bukan dicocokkan polanya: yang menentukan benar-tidaknya adalah
   tombolnya mati atau tidak pada keadaan tertentu, bukan bentuk tulisannya. */
const mati = (form, total) => {
    try {
        return !! new Function('form', 'total', 'return (' + syarat + ');')(form, total);
    } catch (e) {
        return 'galat: ' + e.message;
    }
};
const F = (ubah) => Object.assign({ processing: false, cash_account_id: '' }, ubah);

cek('kosong + ada invoice dicentang → tombol MATI', mati(F({}), 1000) === true);
cek('terisi + ada invoice dicentang → tombol HIDUP', mati(F({ cash_account_id: 7 }), 1000) === false);
cek('terisi tapi belum ada invoice → tetap MATI', mati(F({ cash_account_id: 7 }), 0) === true);
cek('sedang menyimpan → tetap MATI', mati(F({ cash_account_id: 7, processing: true }), 1000) === true);
// Nilai 0 bukan id akun yang sah; kalau ia lolos, tombolnya hidup tanpa rekening.
cek('id akun 0 tidak dianggap terisi', mati(F({ cash_account_id: 0 }), 1000) === true);

console.log('\n=== 3. Alasan matinya tombol disebutkan, bukan dibiarkan ditebak ===');

cek('ada keterangan "Pilih Kas/Bank dulu"', /Pilih Kas\/Bank dulu/.test(src));
cek('keterangannya muncul TEPAT saat kas/bank kosong',
    /v-if="!form\.cash_account_id"[^>]*>\s*Pilih Kas\/Bank dulu/.test(src)
    || /v-if="!form\.cash_account_id"/.test(src) && /Pilih Kas\/Bank dulu/.test(src),
    'keterangannya tidak terikat pada keadaan kosong');
cek('keadaan "belum centang invoice" juga dijelaskan', /Centang minimal satu invoice/.test(src));

console.log('\n=== 4. Kolomnya tetap ada, wajib, dan tersaring alat transaksi ===');

cek('kolom Dari Kas/Bank tetap ada & bertanda wajib', /Dari Kas\/Bank \*/.test(src));
cek('pilihannya tetap tersaring lewat filteredCash',
    /<SearchSelect v-model="form\.cash_account_id" :options="filteredCash"/.test(kode));
cek('placeholder mengajak memilih', /placeholder="— pilih kas \/ bank —"/.test(src));
// Gerbang v-if DAN isinya diperiksa terpisah: keduanya menyebut nama yang sama, jadi memeriksa
// "namanya ada di suatu tempat" tetap hijau walau salah satunya diarahkan ke kolom lain —
// hasilnya pesan galat yang tidak pernah muncul, atau kotak kosong yang muncul tanpa isi.
cek('galat server untuk kolom ini punya gerbang tampil',
    /v-if="form\.errors\.cash_account_id"/.test(kode), 'pesan galatnya tidak pernah muncul');
cek('isi pesan galatnya memang milik kolom ini',
    /\{\{\s*form\.errors\.cash_account_id\s*\}\}/.test(kode), 'kotaknya muncul tanpa isi yang benar');
// Penyaring tunai vs transfer/giro tidak boleh ikut hilang saat bawaannya dicabut.
cek('tunai → hanya akun kas', /alat_transaksi === 'tunai'[\s\S]{0,120}filter\(\(a: any\) => a\.is_kas\)/.test(kode));
cek('selain tunai → hanya rekening bank', /filter\(\(a: any\) => !a\.is_kas\)/.test(kode));
cek('ganti alat transaksi mengosongkan akun yang tidak lagi sah',
    /watch\(\(\) => form\.alat_transaksi[\s\S]{0,200}form\.cash_account_id = ''/.test(kode));

console.log('\n=== 5. Server tetap menjadi penjaga terakhir ===');

// Gerbang layar boleh saja dilewati (payload dibuat tangan); servernya yang menentukan.
cek('cash_account_id wajib di server',
    /'cash_account_id'\s*=>\s*\[[^\]]*'required'/.test(kodeCtl), 'server menerima kuitansi tanpa rekening');
cek('akunnya harus benar-benar ada', /'cash_account_id'\s*=>\s*\[[^\]]*'exists:bs_accounts,id'/.test(kodeCtl));
cek('tunai wajib dari akun kas (dijaga server, bukan hanya layar)',
    /Alat transaksi Tunai hanya boleh dari Kas Operasional atau Kas Kecil/.test(ctl));
cek('transfer/giro wajib dari rekening bank (dijaga server)',
    /Alat transaksi Transfer \/ Cek-Giro harus dari rekening bank/.test(ctl));

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
