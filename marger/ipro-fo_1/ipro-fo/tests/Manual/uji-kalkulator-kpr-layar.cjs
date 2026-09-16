// Kalkulator KPR & Kategori Perhitungan KPR — sisi layar + rumus DP/plafon.
//
// Mesin angsurannya dijaga uji sidik jari terpisah (uji-kalkulator-kpr-mesin.cjs). Di sini
// yang dijaga adalah rumus DP/plafon — yang di aplikasi aslinya hidup di dalam app.js tanpa
// satu pun uji — beserta hal-hal yang membuat kedua layar ini benar-benar bisa dibuka.
//
// Rumus DP punya dua jebakan yang tidak memunculkan galat apa pun:
//   (a) Pembulatan tiga termin. t1 dan t2 dibulatkan lalu SISANYA diberikan ke t3. Membagi
//       rata bertiga membuat jumlah ketiga termin meleset 1–2 rupiah dari DP yang harus
//       dibayar — selisih yang tidak akan pernah dilihat siapa pun sampai ada yang
//       menjumlahkan slipnya.
//   (b) Free DP MENGUBAH rumus plafon, bukan sekadar menyembunyikan barisnya: DP penuh
//       dipotong dari plafon, bukan DP setelah subsidi. Menyamakan keduanya menghasilkan
//       plafon yang terlalu besar dan angsuran yang terlalu kecil.
//
// Jalankan:  node tests/Manual/uji-kalkulator-kpr-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HAL_KALK = 'BankLoan/Kalkulator/Index';
const HAL_SETUP = 'BankLoan/Setup/PerhitunganKpr/Index';
const MESIN = path.join(ROOT, 'resources/js/Pages/BankLoan/Kalkulator/kprCalc.ts');
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');
const RUTE = path.join(ROOT, 'routes/web.php');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BankLoan/KprCalculatorController.php');
const KONFIG = path.join(ROOT, 'database/seeders/data/kpr-calculator-default.json');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){} };'
                  + ' export const useForm = (d) => ({ ...d, errors: {}, processing: false, reset(){}, post(){}, put(){} });'
                  + ' export const usePage = () => ({ props: {} });'
                  + " export const Link = { setup: (p, { slots }) => () => h('a', {}, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                : 'module.exports = new Proxy({}, { get: () => ({ render: () => null }) });',
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            return {
                contents: sfc.compileScript(descriptor, { id, inlineTemplate: true }).content,
                loader: 'ts', resolveDir: path.dirname(a.path),
            };
        });
    },
};

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

/** Cocokkan path HURUF PER HURUF lewat isi direktori (fs.existsSync tidak peka huruf di Windows). */
function adaPersis(akar, relatif) {
    let kini = akar;
    for (const bagian of relatif.split('/')) {
        let isi;
        try { isi = fs.readdirSync(kini); } catch { return false; }
        if (! isi.includes(bagian)) return false;
        kini = path.join(kini, bagian);
    }

    return true;
}

function anakMenu(src, label) {
    const i = src.indexOf("label: '" + label + "'");
    if (i < 0) return null;
    const mulai = src.indexOf('children: [', i);
    if (mulai < 0) return null;
    let j = src.indexOf('[', mulai), dalam = 0, akhir = j;
    for (; akhir < src.length; akhir++) {
        if (src[akhir] === '[') dalam++;
        else if (src[akhir] === ']') { dalam--; if (dalam === 0) break; }
    }

    return [...src.slice(j, akhir).matchAll(/\{\s*label: '([^']+)'[^}]*?href: '([^']+)'[^}]*?permission: '([^']+)'/g)]
        .map((m) => ({ label: m[1], href: m[2], permission: m[3] }));
}

async function render(halaman, props) {
    const hasil = await esbuild.build({
        entryPoints: [path.join(ROOT, 'resources/js/Pages', halaman + '.vue')],
        bundle: true, write: false, format: 'cjs', platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.globalProperties.route = (n, p) => '/' + n + (p ? '/' + (p.berkas ?? p) : '');
    const html = await renderToString(app);

    return html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/\s+/g, ' ');
}

async function main() {
    const mesin = esbuild.buildSync({ entryPoints: [MESIN], bundle: true, write: false, format: 'cjs', platform: 'node' });
    const mm = { exports: {} };
    new Function('module', 'exports', 'require', mesin.outputFiles[0].text)(mm, mm.exports, require);
    const K = mm.exports;

    const cfg = JSON.parse(fs.readFileSync(KONFIG, 'utf8'));
    const nav = fs.readFileSync(NAV, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');
    const ctl = fs.readFileSync(CTL, 'utf8');
    const srcKalk = fs.readFileSync(path.join(ROOT, 'resources/js/Pages', HAL_KALK + '.vue'), 'utf8');
    const kodeKalk = srcKalk.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

    console.log('\n=== 1. Rumus DP, tiga termin, dan plafon ===');

    // harga 500 jt, DP 5% → 25 jt; subsidi 20% → 5 jt; sisa dibayar pembeli 20 jt.
    const d = K.hitungDp(500000000, 0, 5, false, 0);
    cek('DP penuh = 5% harga', d.dpFull === 25000000, String(d.dpFull));
    cek('subsidi DP 20% dari DP penuh', d.dpSubsidi === 5000000, String(d.dpSubsidi));
    cek('yang dibayar pembeli = DP − subsidi − UTJ', d.terminTotal === 20000000, String(d.terminTotal));
    cek('tiga termin berjumlah TEPAT sama dengan yang harus dibayar',
        d.termin1 + d.termin2 + d.termin3 === d.terminTotal,
        [d.termin1, d.termin2, d.termin3, d.terminTotal].join(' / '));
    cek('plafon = harga − UTJ − subsidi − termin', d.plafon === 475000000, String(d.plafon));

    // Pembulatan: terminTotal yang tidak habis dibagi 3 harus tetap berjumlah utuh.
    const ganjil = K.hitungDp(100000001, 0, 5, false, 0);
    cek('pembulatan jatuh di termin 3, jumlahnya tetap utuh',
        ganjil.termin1 + ganjil.termin2 + ganjil.termin3 === ganjil.terminTotal,
        [ganjil.termin1, ganjil.termin2, ganjil.termin3, ganjil.terminTotal].join(' / '));
    cek('  dan termin 3 memang berbeda dari termin 1 (fikstur ini membedakan)',
        ganjil.termin3 !== ganjil.termin1, [ganjil.termin1, ganjil.termin3].join(' / '));

    // Free DP: DP PENUH yang dipotong dari plafon, bukan DP setelah subsidi.
    const bebas = K.hitungDp(500000000, 0, 5, true, 0);
    cek('Free DP memotong DP PENUH dari plafon', bebas.plafon === 475000000, String(bebas.plafon));
    const bebasBeda = K.hitungDp(500000000, 10000000, 5, true, 0);
    const biasaBeda = K.hitungDp(500000000, 10000000, 5, false, 0);
    cek('Free DP & biasa memang menghasilkan plafon BERBEDA saat ada UTJ',
        bebasBeda.plafon !== biasaBeda.plafon, bebasBeda.plafon + ' vs ' + biasaBeda.plafon);

    const utjBesar = K.hitungDp(500000000, 30000000, 5, false, 0);
    cek('UTJ melebihi DP → termin tidak negatif', utjBesar.terminTotal === 0, String(utjBesar.terminTotal));
    const tambahan = K.hitungDp(500000000, 0, 5, false, 50000000);
    cek('DP tambahan mengurangi plafon', tambahan.plafon === d.plafon - 50000000, String(tambahan.plafon));

    console.log('\n=== 2. Halaman kalkulator ter-render ===');

    const props = { config: cfg };
    const tKalk = await render(HAL_KALK, props);
    // Urutan pembuka adalah yang pertama tampil — persis aplikasi aslinya.
    cek('urutan pembuka tampil lebih dulu', tKalk.includes('KPR Java Residence'), tKalk.slice(0, 200));
    cek('bulan berlaku ikut dirender', tKalk.includes(cfg.effectiveMonth), cfg.effectiveMonth);
    cek('urutan pembuka BISA dilewati', /Lewati/.test(tKalk), 'tidak ada jalan keluar dari intro 4,9 detik');

    console.log('\n=== 3. Layar tidak memaku angka apa pun ===');

    // Seluruh suku bunga, plafon, dan nama bank HARUS datang dari pengaturan. Satu angka
    // yang dipaku di layar akan tetap tampil benar hari ini dan diam-diam basi bulan depan.
    for (const angka of ['3.65', '13.00', '2000000000', '100000000']) {
        cek('tidak ada "' + angka + '" yang dipaku di layar kalkulator', ! kodeKalk.includes(angka));
    }
    for (const nama of ['BTN', 'BRI', 'BSN', 'Mandiri']) {
        cek('nama bank "' + nama + '" tidak dipaku di layar', ! new RegExp("'" + nama + "'").test(kodeKalk));
    }
    /* SATU-SATUNYA pengenal bank yang memang terikat di kode: aturan subsidi bunga 2% hanya
       berlaku untuk BTN, persis seperti aplikasi aslinya. Kalau muncul yang kedua, berarti
       ada perilaku bank lain yang mulai dipaku di layar alih-alih diatur lewat pengaturan. */
    cek("hanya SATU keterikatan id bank ('btn' untuk subsidi)",
        (kodeKalk.match(/'btn'/g) || []).length === 1,
        'ada ' + (kodeKalk.match(/'btn'/g) || []).length + ' — perilaku per-bank mulai dipaku di layar');

    cek('mesin hitung dipakai dari kprCalc, bukan disalin ulang',
        /from '\.\/kprCalc'/.test(kodeKalk) && ! /function annuityPayment/.test(kodeKalk),
        'ada mesin hitung kedua di dalam komponen');
    cek('teks promo di-escape sebelum jadi HTML',
        /replace\(\/&\/g, '&amp;'\)/.test(kodeKalk),
        'teks dari pengaturan langsung masuk v-html');

    console.log('\n=== 4. Halaman pengaturan ter-render ===');

    const tSetup = await render(HAL_SETUP, { config: cfg, bisaUbah: true, terakhir: null });
    for (const judul of ['Bank', 'Identitas Bank', 'Plafon', 'Skema Bunga', 'Slide Promo', 'Bulan Berlaku']) {
        cek('bagian "' + judul + '" ada', tSetup.includes(judul), tSetup.slice(0, 200));
    }
    cek('kelima bank tampil sebagai tab', cfg.banks.every((b) => tSetup.includes(b.name)));
    cek('tombol Unduh config.js ada', tSetup.includes('Unduh config.js'));
    cek('tombol Simpan ada saat boleh mengubah', tSetup.includes('Simpan Pengaturan'));

    const tBaca = await render(HAL_SETUP, { config: cfg, bisaUbah: false, terakhir: null });
    cek('tanpa izin ubah: tombol Simpan TIDAK ada', ! tBaca.includes('Simpan Pengaturan'));
    cek('tanpa izin ubah: alasannya dikatakan', tBaca.includes('bankloan.setup.manage'),
        'tombolnya hilang tanpa keterangan — orang mengira layarnya rusak');

    console.log('\n=== 5. Nama halaman Inertia = nama direktori, huruf per huruf ===');

    const disebut = [...ctl.matchAll(/Inertia::render\('([^']+)'/g)].map((m) => m[1]);
    cek('controller menyebut kedua halaman', disebut.includes(HAL_KALK) && disebut.includes(HAL_SETUP), JSON.stringify(disebut));
    for (const h of disebut) {
        cek('berkas .vue "' + h + '" ada dengan ejaan sama persis',
            adaPersis(path.join(ROOT, 'resources/js/Pages'), h + '.vue'));
    }

    console.log('\n=== 6. Nama rute yang dipakai layar terdaftar ===');

    const srcSetup = fs.readFileSync(path.join(ROOT, 'resources/js/Pages', HAL_SETUP + '.vue'), 'utf8');
    const dipakai = [...new Set([...(srcKalk + srcSetup).matchAll(/route\('([^']+)'/g)].map((m) => m[1]))];
    cek('layar memakai nama rute', dipakai.length > 0, JSON.stringify(dipakai));
    for (const n of dipakai) {
        cek('rute ' + n + ' terdaftar', rute.includes("->name('" + n + "')"));
    }

    console.log('\n=== 7. Kedua menu sidebar pada tempatnya ===');

    const setUp = anakMenu(nav, 'Set Up');
    const labelSetUp = (setUp ?? []).map((x) => x.label);
    const iBerkas = labelSetUp.indexOf('Kategori Berkas Tidak Lengkap');
    const iHitung = labelSetUp.indexOf('Kategori Perhitungan KPR');
    cek('Kategori Perhitungan KPR ada di menu Set Up', iHitung >= 0, JSON.stringify(labelSetUp));
    cek('  letaknya DI BAWAH Kategori Berkas Tidak Lengkap', iBerkas >= 0 && iHitung > iBerkas,
        'berkas=' + iBerkas + ' hitung=' + iHitung);
    const itemHitung = (setUp ?? []).find((x) => x.label === 'Kategori Perhitungan KPR');
    cek('  menunjuk kpr.setup.perhitungan.index', itemHitung?.href === 'kpr.setup.perhitungan.index', itemHitung?.href);
    cek('  memakai izin bankloan.setup.view', itemHitung?.permission === 'bankloan.setup.view', itemHitung?.permission);

    const qr = anakMenu(nav, 'Query/Report');
    const labelQr = (qr ?? []).map((x) => x.label);
    const iCluster = labelQr.indexOf('Realisasi KPR per Cluster');
    const iKalk = labelQr.indexOf('Kalkulator KPR');
    cek('Kalkulator KPR ada di menu Query/Report', iKalk >= 0, JSON.stringify(labelQr));
    cek('  letaknya TEPAT di bawah Realisasi KPR per Cluster', iCluster >= 0 && iKalk === iCluster + 1,
        'cluster=' + iCluster + ' kalkulator=' + iKalk);
    const itemKalk = (qr ?? []).find((x) => x.label === 'Kalkulator KPR');
    cek('  menunjuk kpr.kalkulator.index', itemKalk?.href === 'kpr.kalkulator.index', itemKalk?.href);
    cek('  memakai izin bankloan.application.view', itemKalk?.permission === 'bankloan.application.view', itemKalk?.permission);

    console.log('\n=== 8. Gambar bawaan benar-benar ada di repo ===');

    const jalurGambar = [cfg.brandLogo, cfg.introPhoto, cfg.introPhotoMobile,
        ...cfg.banks.map((b) => b.logoImage), ...cfg.banks.map((b) => b.promo?.slide2Image)].filter(Boolean);
    cek('konfigurasi bawaan menunjuk gambar', jalurGambar.length >= 8, String(jalurGambar.length));
    for (const g of jalurGambar) {
        cek('berkas ' + g + ' ada', fs.existsSync(path.join(ROOT, 'public', g.replace(/^\//, ''))));
    }
    cek('tidak ada base64 tersisa di konfigurasi bawaan',
        ! fs.readFileSync(KONFIG, 'utf8').includes('data:image'));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
