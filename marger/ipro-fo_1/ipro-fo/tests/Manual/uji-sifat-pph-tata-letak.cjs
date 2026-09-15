// Blok "Sifat PPh" pada Registrasi Invoice Hutang (BusinessSupport/Payable/Form.vue):
// dropdown Akun PPh dihilangkan, dan tarif pindah berdampingan dengan jenis PPh.
//
// Yang dijaga bukan sekadar tata letaknya. Menghapus dropdown Akun PPh memindahkan SELURUH
// tanggung jawab pengisian form.pph_account_id ke satu watcher, dan watcher itu sebelumnya
// sengaja TIDAK mengosongkan akun saat jenis PPh dikosongkan — dulu aman karena pengguna masih
// bisa membetulkannya sendiri lewat dropdown, sekarang tidak bisa lagi. Akun yang tertinggal
// tidak cocok tidak sekadar salah muara: keterangan baris di buku besar dibaca dari NAMA AKUN
// (pasalPph), sehingga bisa tertulis "PPh 21" padahal jenisnya PPh 23 — dan jurnalnya tetap
// balance, jadi tidak ada satu pun galat yang memberi tahu.
//
// Jalankan:  node tests/Manual/uji-sifat-pph-tata-letak.cjs .
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(process.argv[2] || '.');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Form.vue');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const src = fs.readFileSync(FORM, 'utf8');
const tanpaKomentar = src.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

/** Tag pembuka pada posisi tertentu, dibaca sambil MELEWATI isi tanda kutip (atribut bisa memuat '>'). */
function tagDi(s, awal) {
    let i = awal, kutip = '';
    while (i < s.length) {
        const c = s[i];
        if (kutip) { if (c === kutip) kutip = ''; }
        else if (c === '"' || c === "'") kutip = c;
        else if (c === '>') break;
        i++;
    }

    return s.slice(awal, i);
}
const kelasDari = (tag) => (/class="([^"]*)"/.exec(tag) || [, ''])[1];
/** Bobot flex sebuah kelas: flex-[N] → N, flex-1 → 1, selain itu null. */
function bobotFlex(kelas) {
    const k = /(?:^|\s)flex-\[(\d+)\]/.exec(kelas);
    if (k) return Number(k[1]);

    return /(?:^|\s)flex-1(?:\s|$)/.test(kelas) ? 1 : null;
}

console.log('\n=== 1. Dropdown Akun PPh benar-benar hilang ===');

cek('tidak ada lagi select untuk form.pph_account_id',
    ! /<select[^>]*v-model="form\.pph_account_id"/.test(tanpaKomentar),
    'akunnya masih bisa dipilih sendiri dan bisa tidak cocok dengan jenis PPh');
cek('tidak ada lagi pilihan berlabel "Akun PPh"', ! /Akun PPh…|Akun PPh\.\.\./.test(tanpaKomentar));
cek('daftar pphAccounts tidak lagi dirender jadi <option>',
    ! /v-for="a in pphAccounts"/.test(tanpaKomentar), 'masih ada dropdown akun');

// Propnya TETAP dibutuhkan: pasalPph membaca NAMA akun dari sana untuk keterangan baris jurnal.
// Menghapusnya sekalian membuat keterangan PPN/PPh di buku besar kehilangan nama pasalnya.
cek('prop pphAccounts tetap dideklarasikan', /pphAccounts:\s*Array<any>/.test(src),
    'pasalPph kehilangan sumber nama akun');
cek('pphAccounts masih dipakai pasalPph', /props\.pphAccounts\s*\?\?\s*\[\]/.test(tanpaKomentar));

console.log('\n=== 2. Jenis PPh & tarif berdampingan dalam satu baris ===');

const iJenis = tanpaKomentar.indexOf('<select v-model="form.pph_type"');
const iTarif = tanpaKomentar.indexOf('<input v-model.number="form.pph_pct"');
cek('kedua kontrolnya ada', iJenis >= 0 && iTarif >= 0, 'jenis=' + iJenis + ' tarif=' + iTarif);
cek('tarif berada SESUDAH jenis PPh (jenis di kiri)', iJenis < iTarif,
    'tarifnya mendahului jenis PPh');

// "Satu baris" = tidak ada </div> di antara keduanya; kalau ada, mereka di baris berbeda.
const antara = tanpaKomentar.slice(iJenis, iTarif);
cek('tidak ada penutup <div> di antara keduanya (benar-benar satu baris)',
    ! /<\/div>/.test(antara), 'keduanya masih berada di baris yang berbeda');

// Pembungkusnya harus flex, kalau tidak rasio lebarnya tidak berlaku sama sekali.
const iBungkus = tanpaKomentar.lastIndexOf('<div', iJenis);
const kelasBungkus = kelasDari(tagDi(tanpaKomentar, iBungkus));
cek('pembungkusnya memakai flex', /(?:^|\s)flex(?:\s|$)/.test(kelasBungkus), kelasBungkus);

console.log('\n=== 3. Rasio lebarnya benar-benar diatur ===');

const kelasJenis = kelasDari(tagDi(tanpaKomentar, iJenis));
const kelasTarif = kelasDari(tagDi(tanpaKomentar, iTarif));
const bJenis = bobotFlex(kelasJenis);
const bTarif = bobotFlex(kelasTarif);

cek('jenis PPh memakai bobot flex', bJenis !== null, kelasJenis);
cek('tarif memakai bobot flex', bTarif !== null, kelasTarif);
cek('jenis PPh LEBIH LEBAR daripada tarif', bJenis !== null && bTarif !== null && bJenis > bTarif,
    'jenis=' + bJenis + ' tarif=' + bTarif);
// Tanpa min-w-0, lebar minimum bawaan select/input menahan keduanya dan rasionya tidak pernah
// benar-benar berlaku pada kolom sempit — tampak benar di layar lebar, meleset di layar sempit.
cek('jenis PPh boleh menciut (min-w-0)', /min-w-0/.test(kelasJenis), kelasJenis);
cek('tarif boleh menciut (min-w-0)', /min-w-0/.test(kelasTarif), kelasTarif);
cek('tarif tidak lagi berlebar mati w-16', ! /(?:^|\s)w-16(?:\s|$)/.test(kelasTarif), kelasTarif);
cek('jenis PPh tidak lagi selebar penuh sendirian', ! /(?:^|\s)w-full(?:\s|$)/.test(kelasJenis), kelasJenis);
cek('ketiga jenis PPh tetap bisa dipilih',
    ['pph21', 'pph23', 'pph4_2'].every((j) => new RegExp('value="' + j + '"').test(tanpaKomentar)));
cek('tarif tetap bisa disunting manual', /v-model\.number="form\.pph_pct"/.test(tanpaKomentar));

console.log('\n=== 4. Akun PPh sepenuhnya turunan jenis PPh ===');

// Badan watcher dijalankan SUNGGUHAN, bukan dicocokkan polanya: yang menentukan benar-tidaknya
// adalah nilai yang tertulis ke form, bukan bentuk kodenya.
const mWatch = /watch\(\(\) => form\.pph_type, \(t\) => \{([\s\S]*?)\n\}\);/.exec(tanpaKomentar);
cek('watcher jenis PPh ditemukan', !! mWatch);

const PPH_RATES = { pph21: 2.5, pph23: 2.0, pph4_2: 1.75 };
const PETA = { pph21: 122, pph23: 123, pph4_2: 121 };
const jalankan = new Function('t', 'form', 'props', 'PPH_RATES', mWatch[1]);
const pakai = (t, peta = PETA, awal = {}) => {
    const form = Object.assign({ pph_type: t, pph_pct: 0, pph_account_id: '' }, awal);
    jalankan(t, form, { pphDefaults: peta }, PPH_RATES);

    return form;
};

cek('PPh 21 → akun Hutang PPh 21', pakai('pph21').pph_account_id === 122);
cek('PPh 23 → akun Hutang PPh 23', pakai('pph23').pph_account_id === 123);
cek('PPh Pasal 4(2) → akun Hutang PPh 4(2)', pakai('pph4_2').pph_account_id === 121);
cek('tarifnya ikut terisi otomatis', pakai('pph23').pph_pct === 2, String(pakai('pph23').pph_pct));

// Inti perubahan ini: tanpa dropdown, akun yang tertinggal tidak bisa lagi dibetulkan tangan.
cek('ganti jenis → akun ikut berganti, bukan bertahan',
    pakai('pph23', PETA, { pph_account_id: 122 }).pph_account_id === 123,
    'akun jenis sebelumnya masih bertahan');
cek('jenis dikosongkan → akun ikut dikosongkan',
    pakai('', PETA, { pph_account_id: 122 }).pph_account_id === '',
    'akun jenis sebelumnya tertinggal padahal jenisnya kosong');
cek('jenis yang akunnya belum ada di CoA → akun dikosongkan, bukan memakai akun jenis lama',
    pakai('pph23', { pph23: null }, { pph_account_id: 122 }).pph_account_id === '',
    'memakai akun jenis sebelumnya — salah pasal tanpa satu pun tanda');
cek('tarif TIDAK ikut dikosongkan saat jenis dikosongkan (tetap bisa manual)',
    pakai('', PETA, { pph_pct: 5 }).pph_pct === 5, String(pakai('', PETA, { pph_pct: 5 }).pph_pct));

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
