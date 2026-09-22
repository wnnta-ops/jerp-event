// Tombol "Kembali ke Java Core" pada halaman login Java Sales Hub & Legal.
//
// Kedua modul itu aplikasi Next.js terpisah yang disajikan di bawah basePath ("/sales" dan
// "/legal") oleh nginx, pada host yang sama dengan Java Core. Satu jebakan menentukan
// berhasil-tidaknya tombol ini:
//
//   <Link href="/dashboard"> milik next/link akan DIBERI basePath otomatis dan menjadi
//   "/sales/dashboard" atau "/legal/dashboard" — halaman yang tidak ada. Tombolnya tetap
//   terlihat normal, tetap bisa diklik, dan baru gagal saat ditekan. Karena itu tautan
//   KELUAR modul harus memakai <a> polos, sementara tautan ke halaman DI DALAM modul
//   (mis. Daftar) justru harus tetap memakai Link supaya ikut ber-basePath.
//
// Uji ini juga memastikan tujuannya BENAR-BENAR ADA: rute bernama 'dashboard' pada aplikasi
// Java Core, bukan URL yang diketik dari ingatan.
//
// Jalankan:  node tests/Manual/uji-kembali-java-core-login.cjs .
const fs = require('fs');
const path = require('path');

// Berkasnya ada di AKAR repo (dua tingkat di atas aplikasi Back Office ini).
const BO = path.resolve(process.argv[2] || '.');
const AKAR = path.resolve(BO, '../../..');

const SALES = path.join(AKAR, 'app/sales/src/app/login/page.tsx');
const LEGAL = path.join(AKAR, 'app/legal/app/login/page.tsx');
const SALES_CFG = path.join(AKAR, 'app/sales/next.config.mjs');
const LEGAL_CFG = path.join(AKAR, 'app/legal/next.config.mjs');
const SALES_CSS = path.join(AKAR, 'app/sales/src/app/globals.css');
const RUTE_CORE = path.join(AKAR, 'routes/web.php');
const SHELL = path.join(AKAR, 'app/sales/src/components/Shell.tsx');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

/** Elemen <a ...>…</a> yang teks isinya memuat label. */
function anchor(src, label) {
    return [...src.matchAll(/<a\b([^>]*)>([\s\S]*?)<\/a>/g)]
        .filter((m) => m[2].replace(/\{[^}]*\}/g, ' ').includes(label));
}
/** Elemen <Link …> (next/link) yang href-nya persis ini. */
function linkKe(src, href) {
    return [...src.matchAll(/<Link\b([^>]*)>/g)].filter((m) => m[1].includes(`href="${href}"`));
}
/** Buang komentar JSX & blok komentar supaya penjaga tidak cocok dengan komentarnya sendiri. */
const bersih = (s) => s.replace(/\{\s*\/\*[\s\S]*?\*\/\s*\}/g, '').replace(/\/\*[\s\S]*?\*\//g, '');

const sales = bersih(fs.readFileSync(SALES, 'utf8'));
const legal = bersih(fs.readFileSync(LEGAL, 'utf8'));
const salesCfg = fs.readFileSync(SALES_CFG, 'utf8');
const legalCfg = fs.readFileSync(LEGAL_CFG, 'utf8');
const ruteCore = fs.readFileSync(RUTE_CORE, 'utf8');

const LABEL = 'Kembali ke Java Core';
const TUJUAN = '/dashboard';

console.log('\n=== 1. Tujuannya benar-benar ada di Java Core ===');

// Tanpa ini, tombolnya bisa menunjuk URL yang diketik dari ingatan dan baru gagal saat ditekan.
cek('rute /dashboard terdaftar di aplikasi Java Core',
    /Route::get\('\/dashboard',[\s\S]{0,120}?->name\('dashboard'\)/.test(ruteCore),
    'tujuan tombolnya tidak ada di routes/web.php akar');

console.log('\n=== 2. Java Sales Hub ===');

const aSales = anchor(sales, LABEL);
cek('tombolnya ada di halaman login', aSales.length === 1, 'ditemukan ' + aSales.length + ' tautan');
cek('  menuju ' + TUJUAN, aSales.length === 1 && aSales[0][1].includes(`href="${TUJUAN}"`), aSales[0]?.[1] ?? '-');
// <a> polos: basePath TIDAK disisipkan. <Link> akan menjadi /sales/dashboard.
cek('  memakai <a> polos, bukan <Link> next/link', linkKe(sales, TUJUAN).length === 0,
    'basePath akan mengubahnya menjadi /sales' + TUJUAN + ' — halaman yang tidak ada');
cek('  bergaya tombol (btn ghost sm)', aSales.length === 1 && /className="btn ghost sm"/.test(aSales[0][1]));
cek('  punya title yang menjelaskan tujuannya', aSales.length === 1 && /title="Kembali ke Dashboard Java Core"/.test(aSales[0][1]));

console.log('\n=== 3. Legal · MUP ===');

const aLegal = anchor(legal, LABEL);
cek('tombolnya ada di halaman login', aLegal.length === 1, 'ditemukan ' + aLegal.length + ' tautan');
cek('  menuju ' + TUJUAN, aLegal.length === 1 && aLegal[0][1].includes(`href="${TUJUAN}"`), aLegal[0]?.[1] ?? '-');
cek('  memakai <a> polos, bukan <Link> next/link', linkKe(legal, TUJUAN).length === 0,
    'basePath akan mengubahnya menjadi /legal' + TUJUAN + ' — halaman yang tidak ada');
cek('  punya title yang menjelaskan tujuannya', aLegal.length === 1 && /title="Kembali ke Dashboard Java Core"/.test(aLegal[0][1]));

// Pembanding yang membuat penjaga di atas bermakna: tautan ke halaman DI DALAM modul harus
// TETAP memakai Link, supaya ikut diberi basePath. Kalau ikut diubah jadi <a>, tombol
// Daftar akan keluar dari modul dan berakhir di 404.
cek('  tautan Daftar (di dalam modul) TETAP memakai Link', linkKe(legal, '/register').length === 1,
    'tautan di dalam modul kehilangan basePath-nya');

console.log('\n=== 4. Jebakan basePath-nya memang nyata ===');

// Kalau basePath-nya hilang, penjaga "<a> polos" di atas kehilangan alasannya — dan
// seseorang akan "merapikan"-nya menjadi Link.
cek('sales ber-basePath /sales', /basePath:\s*"\/sales"/.test(salesCfg), salesCfg.slice(0, 200));
cek('legal ber-basePath /legal', /basePath:\s*"\/legal"/.test(legalCfg), legalCfg.slice(0, 200));

console.log('\n=== 5. Konsisten dengan pola yang sudah ada ===');

if (fs.existsSync(SHELL)) {
    const shell = fs.readFileSync(SHELL, 'utf8');
    cek('Shell (pengguna yang sudah masuk) memakai pola yang sama',
        /<a[^>]*href="\/dashboard"/.test(shell),
        'ada dua cara berbeda kembali ke Java Core di satu aplikasi');
} else {
    cek('Shell ditemukan', false, SHELL);
}

if (fs.existsSync(SALES_CSS)) {
    const css = fs.readFileSync(SALES_CSS, 'utf8');
    cek('kelas .btn, .btn.ghost, .btn.sm ada di gaya Sales',
        /\.btn\s*\{/.test(css) && /\.btn\.ghost\s*\{/.test(css) && /\.btn\.sm\s*\{/.test(css),
        'tombolnya dirender tanpa gaya dan tampak seperti teks biasa');
} else {
    cek('globals.css Sales ditemukan', false, SALES_CSS);
}

console.log('\n==================================================');
console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
if (gagal === 0) console.log('  SEMUA UJI LULUS');
console.log('==================================================');
process.exit(gagal ? 1 : 0);
