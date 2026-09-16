// Kolom "Jumlah" pada Alokasi Kasbon memakai pemisah ribuan, dan MoneyInput mendapat opsi
// desimal tanpa menggeser satu pun pemakaian lamanya.
//
// DUA HAL YANG DIJAGA, keduanya tidak memunculkan galat apa pun kalau salah:
//
//  1. SEN TIDAK BOLEH HILANG DIAM-DIAM. Kolom bs_cash_advance_allocations.amount bertipe
//     decimal(20,2) dan CashAdvanceAllocationService::tambah membulatkan ke 2 angka. MoneyInput
//     bawaannya membuang SEMUA non-digit, jadi memakainya apa adanya akan mengubah "1.000.000,50"
//     menjadi 100000050 — seratus kali lipat, dan jurnalnya tetap balance.
//  2. PEMAKAIAN LAMA TIDAK BOLEH BERGESER. MoneyInput dipakai di belasan layar lain dengan
//     rupiah penuh. Jalur decimals = 0 karena itu diuji berdampingan pada setiap kasus.
//
// MoneyInput dipanggil pada tingkat setup() — yang menentukan benar-tidaknya adalah nilai yang
// DIPANCARKAN balik, dan itu tidak terlihat dari HTML hasil render.
//
// Jalankan:  node tests/Manual/uji-alokasi-kasbon-ribuan.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');

const ROOT = path.resolve(process.argv[2] || '.');
const MONEY = path.join(ROOT, 'resources/js/Components/UI/MoneyInput.vue');
const SHOW = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Show.vue');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

/** Pasang MoneyInput dengan props tertentu; kembalikan binding + jejak yang dipancarkan. */
function pasang(Komponen, props) {
    const dipancar = [];
    const bind = Komponen.setup(
        Object.assign({ modelValue: null, placeholder: '0', min: 0, disabled: false, decimals: 0 }, props),
        { emit: (e, v) => dipancar.push(v), attrs: {}, slots: {}, expose() {} },
    );

    return { bind, dipancar, ketik: (teks) => { bind.onInput({ target: { value: teks } }); return dipancar[dipancar.length - 1]; } };
}

async function main() {
    const { descriptor } = sfc.parse(fs.readFileSync(MONEY, 'utf8'), { filename: MONEY });
    const kode = esbuild.transformSync(sfc.compileScript(descriptor, { id: 'm' }).content, { loader: 'ts', format: 'cjs' }).code;
    const m = { exports: {} };
    new Function('module', 'exports', 'require', kode)(m, m.exports, require);
    const M = m.exports.default;

    console.log('\n=== 1. Rupiah penuh (bawaan) — TIDAK BOLEH BERGESER ===');

    const bulat = pasang(M, { modelValue: 1000000 });
    cek('1000000 tampil 1.000.000', bulat.bind.display.value === '1.000.000', String(bulat.bind.display.value));
    cek('mengetik 1234567 → 1.234.567', bulat.ketik('1234567') === 1234567 && bulat.bind.display.value === '1.234.567',
        bulat.bind.display.value);
    cek('titik ribuan tidak menggandakan nilai', bulat.ketik('1.234.5678') === 12345678);
    cek('koma diabaikan pada mode rupiah penuh', bulat.ketik('1000,50') === 100050, String(bulat.ketik('1000,50')));
    cek('dikosongkan → 0', bulat.ketik('') === 0);
    cek('negatif ditolak', bulat.ketik('-500') === 500);
    cek('nilai 0 tampil kosong (pakai placeholder)', pasang(M, { modelValue: 0 }).bind.display.value === '');

    console.log('\n=== 2. Mode desimal — sen bisa diketik dan tidak salah baca ===');

    const des = pasang(M, { modelValue: 1000000.5, decimals: 2 });
    cek('1000000.5 tampil 1.000.000,50', des.bind.display.value === '1.000.000,50', String(des.bind.display.value));
    cek('mengetik 1234567 → 1.234.567 (tanpa koma dulu)',
        des.ketik('1234567') === 1234567 && des.bind.display.value === '1.234.567', des.bind.display.value);
    // Inti mode ini: koma yang baru ditekan TIDAK boleh ditelan, kalau tidak sen tak pernah bisa diketik.
    cek('koma yang baru ditekan bertahan', des.ketik('1.234.567,') === 1234567 && des.bind.display.value === '1.234.567,',
        des.bind.display.value);
    cek('mengetik sen → nilainya ikut', des.ketik('1.234.567,5') === 1234567.5);
    cek('dua angka sen', des.ketik('1.234.567,50') === 1234567.5);
    cek('tampilannya tetap berpemisah ribuan saat mengetik sen', des.bind.display.value === '1.234.567,50',
        des.bind.display.value);
    cek('angka sen ketiga dipotong (bukan menggeser nilai)', des.ketik('1.000,555') === 1000.55, String(des.ketik('1.000,555')));
    // Tampilan HARUS ikut terpotong. Kalau hanya nilainya yang dibatasi, layar memampangkan
    // "1.000,555" sementara yang tersimpan 1.000,55 — dua angka berbeda pada satu kolom, dan
    // yang dipercaya pengguna justru yang di layar.
    cek('tampilannya ikut terpotong, tidak berbeda dari nilainya',
        des.bind.display.value === '1.000,55', des.bind.display.value);
    // Titik SELALU pemisah ribuan — kalau dianggap desimal, 1.000.000 terbaca 1.
    cek('titik tidak pernah dianggap desimal', des.ketik('1.000.000') === 1000000, String(des.ketik('1.000.000')));
    cek('koma kedua tidak membentuk angka baru', des.ketik('1.000,50,75') === 1000.5075 === false && des.ketik('1.000,50,75') === 1000.5,
        String(des.ketik('1.000,50,75')));
    cek('hanya koma → 0, tapi komanya bertahan agar bisa dilanjutkan',
        des.ketik(',') === 0 && des.bind.display.value === '0,', des.bind.display.value);
    cek('dikosongkan → 0 dan tampilan kosong', des.ketik('') === 0 && des.bind.display.value === '');
    cek('negatif tetap ditolak', des.ketik('-1.000,50') === 1000.5);

    console.log('\n=== 3. Nilai dari luar tidak menimpa yang sedang diketik ===');

    // watch hanya memformat ulang bila angkanya BERBEDA; tanpa itu koma yang sedang diketik
    // akan lenyap setiap kali induknya menulis balik nilai yang sama.
    const srcMoney = fs.readFileSync(MONEY, 'utf8');
    cek('watch membandingkan angka, bukan teks',
        /watch\(\(\) => props\.modelValue, \(v\) => \{ if \(parse\(display\.value\) !== \(Number\(v\) \|\| 0\)\) display\.value = fmt\(v\); \}\)/.test(srcMoney),
        'koma yang sedang diketik akan lenyap');
    cek('prop decimals berbawaan 0 (pemakaian lama tidak berubah)', /decimals:\s*0,/.test(srcMoney));
    cek('jalur rupiah penuh tetap memakai toLocaleString apa adanya',
        /props\.decimals > 0[\s\S]{0,200}: x\.toLocaleString\('id-ID'\)/.test(srcMoney));

    console.log('\n=== 4. Kolom Jumlah pada Alokasi Kasbon memakainya ===');

    const srcShow = fs.readFileSync(SHOW, 'utf8');
    const tanpaKomentar = srcShow.replace(/<!--[\s\S]*?-->/g, '');

    cek('MoneyInput diimpor', /import MoneyInput from '@\/Components\/UI\/MoneyInput\.vue'/.test(srcShow));
    cek('kolom Jumlah memakai MoneyInput', /<MoneyInput v-model="alokForm\.amount"/.test(tanpaKomentar),
        'masih input biasa');
    cek('tidak lagi input type=number', ! /v-model\.number="alokForm\.amount"[^>]*type="number"/.test(tanpaKomentar),
        'type=number tidak akan pernah menampilkan pemisah ribuan');
    cek('memakai 2 angka desimal, sesuai kolom decimal(20,2)', /<MoneyInput v-model="alokForm\.amount" :decimals="2"/.test(tanpaKomentar),
        'sen akan hilang diam-diam');
    // Penanda "belum diisi" tidak boleh ikut hilang — ia bagian dari petunjuk "Lengkapi dulu".
    // Dipasang sebagai CINCIN pembungkus, bukan dengan menimpa border & background MoneyInput
    // lewat kelas ber-awalan !. Cara itu sudah dicoba dan setengah gagal: varian gelapnya
    // (dark:!bg-amber-900/10) tidak ikut terbentuk Tailwind, sehingga latar krem terang menembus
    // ke mode gelap — hanya ketahuan setelah CSS hasil build benar-benar diperiksa.
    cek('penanda saat belum diisi tetap ada', /ring-1 ring-amber-400/.test(tanpaKomentar),
        'petunjuk "Lengkapi dulu" jadi tak berpasangan');
    cek('penandanya membungkus MoneyInput, bukan menimpa kelasnya',
        /ring-amber-400['"][\s\S]{0,120}<MoneyInput v-model="alokForm\.amount"/.test(tanpaKomentar),
        'penandanya tidak membungkus kolomnya');
    cek('tidak memakai kelas ber-awalan ! yang varian gelapnya tidak terbentuk',
        ! /!border-amber|!bg-amber/.test(tanpaKomentar), 'mode gelap akan berlatar krem terang');
    cek('penandanya terikat pada nilai > 0', /Number\(alokForm\.amount\) > 0 \? '' :/.test(tanpaKomentar));
    cek('gerbang Tambah Alokasi tetap ada', /:disabled="alokForm\.processing \|\| alokKurang\.length > 0"/.test(tanpaKomentar));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
