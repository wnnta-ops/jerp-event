// Set Up COGS — SISI LAYAR, di-render SUNGGUHAN lewat SSR dengan PROPS ASLI dari pengendali.
//
// Pasangan dari uji-setup-cogs.php, yang menguji sisi server. Yang ini menutup satu-satunya
// kelas kesalahan yang tidak bisa dilihat uji sisi server maupun vue-tsc: nama prop yang meleset
// antara pengendali dan layar. Gejalanya adalah kolom yang diam-diam kosong, bukan galat.
//
// Props-nya TIDAK ditulis tangan: tests/Manual/_props-setup-cogs.php menyemai satu Set Up COGS
// beserta unit fikstur di basis data Front Office, menulis props Inertia sungguhan, lalu
// membatalkan seluruh tulisannya.
//
// Yang dijaga:
//  1. Kedua layar dirender tanpa peringatan Vue (peringatan DITANGKAP, bukan dibungkam).
//  2. Angka server benar-benar sampai ke layar — bukan sekadar merender tanpa galat.
//  3. Tidak ada NaN, Infinity, maupun "undefined" yang lolos.
//  4. Blok rekonsiliasi, tabel budget per unit, dan kolom per pos biaya benar-benar ada.
//
// Jalankan:  node tests/Manual/uji-setup-cogs-layar.cjs .
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const PROPS = path.join(ROOT, 'storage/app/_props-setup-cogs.json');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const plugin = {
    name: 'stub',
    setup(build) {
        // Ekstensi dilengkapi di sini: esbuild tidak melakukannya untuk jalur yang dipulangkan plugin.
        build.onResolve({ filter: /^@\// }, (a) => {
            const dasar = path.join(ROOT, 'resources/js', a.path.slice(2));
            for (const akhiran of ['', '.ts', '.js', '.vue', '/index.ts', '/index.js']) {
                const coba = dasar + akhiran;
                if (fs.existsSync(coba) && fs.statSync(coba).isFile()) return { path: coba };
            }

            return { path: dasar };
        });
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
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

async function render(halaman, props) {
    const berkas = path.join(ROOT, 'resources/js/Pages', halaman + '.vue');
    const hasil = await esbuild.build({
        entryPoints: [berkas], bundle: true, write: false, format: 'cjs', platform: 'node',
        external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const peringatan = [];
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = (msg) => { peringatan.push(String(msg)); };
    // route() dipasang sebagai global sungguhan: di peramban Ziggy menaruhnya di window, dan
    // layar memanggilnya dari dalam <script setup>, yang tidak melihat globalProperties.
    const rute = (n, p) => '/' + n + (p !== undefined ? '/' + (Array.isArray(p) ? p.join('/') : p) : '');
    globalThis.route = rute;
    app.config.globalProperties.route = rute;
    const html = await renderToString(app);

    return {
        html,
        teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ')
            .replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/\s+/g, ' '),
        peringatan,
    };
}

const rp = (n) => 'Rp ' + Math.round(Number(n)).toLocaleString('id-ID');
const m2 = (n) => Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function main() {
    console.log('  … menyemai & mengambil props asli dari pengendali');
    try {
        const keluar = execSync(
            'docker exec ipro-fo-app php artisan tinker --execute="require \'/var/www/html/tests/Manual/_props-setup-cogs.php\';"',
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        );
        if (! /PROPS DITULIS/.test(keluar)) {
            console.log('  GAGAL mengambil props — ' + keluar.trim().split('\n').slice(-3).join(' | '));
            gagal++;
        }
    } catch (e) {
        console.log('  GAGAL menjalankan _props-setup-cogs.php — ' + String(e.message).split('\n')[0]);
        gagal++;
    }

    if (! fs.existsSync(PROPS)) {
        console.log('\n  Props tidak tersedia; uji layar tidak bisa dijalankan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + (gagal + 1) + '\nADA YANG GAGAL');
        process.exit(1);
    }
    const semua = JSON.parse(fs.readFileSync(PROPS, 'utf8'));

    console.log('\n=== 1. Kedua layar dirender tanpa peringatan Vue ===');
    const hasil = {};
    for (const [nama, halaman] of Object.entries({
        Index: 'BusinessSupport/Cogs/SetupCogs/Index',
        Show: 'BusinessSupport/Cogs/SetupCogs/Show',
    })) {
        try {
            const r = await render(halaman, semua[nama]);
            hasil[nama] = r;
            const parah = r.peringatan.filter((w) =>
                /Missing required prop|was accessed during render|Invalid prop|Property .* is not defined/i.test(w));
            cek(nama.padEnd(6) + ' dirender', parah.length === 0, parah.slice(0, 2).join(' | '));
        } catch (e) {
            cek(nama.padEnd(6) + ' dirender', false, String(e.message).split('\n')[0]);
        }
    }
    if (Object.keys(hasil).length !== 2) {
        console.log('\n  Ada layar yang tidak berhasil dirender — pemeriksaan isi dihentikan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + gagal + '\nADA YANG GAGAL');
        process.exit(1);
    }

    console.log('\n=== 2. Tidak ada galat hitung yang lolos ke layar ===');
    for (const [nama, r] of Object.entries(hasil)) {
        const kotor = ['NaN', 'Infinity', 'undefined'].filter((k) => r.teks.includes(k));
        cek(nama.padEnd(6) + ' bersih', kotor.length === 0, kotor.join(', '));
    }

    console.log('\n=== 3. Angka server sampai ke layar ===');
    const S = semua.Show;
    const t = hasil.Show.teks;
    cek('TOTAL COGS ' + rp(S.hitung.total.total_cogs), t.includes(rp(S.hitung.total.total_cogs)));
    cek('  Luas Lahan ' + m2(S.hitung.lahan.luas_lahan), t.includes(m2(S.hitung.lahan.luas_lahan)));
    cek('  Jalan turunan ' + m2(S.hitung.lahan.jalan), t.includes(m2(S.hitung.lahan.jalan)));
    cek('  COGS GROSS menyebut ÷ Luas Lahan', /÷ Luas Lahan/.test(t));
    cek('  COGS Saleable menyebut ÷ Saleable', /÷ Saleable/.test(t));
    cek('  blok Rekonsiliasi ada', /Rekonsiliasi/.test(t));
    cek('  Σ LT unit ' + m2(S.hitung.unitTotal.lt), t.includes(m2(S.hitung.unitTotal.lt)));
    cek('  unit BATAL tidak ikut (' + S.hitung.unitTotal.jumlah + ' unit)',
        S.hitung.unitTotal.jumlah === 4 && t.includes(S.hitung.unitTotal.jumlah + ' unit'));

    console.log('\n=== 4. Kedua dasar pembagi tampil ===');
    const tanah = S.hitung.komponen.find((k) => k.dasar === 'tanah');
    const bangunan = S.hitung.komponen.find((k) => k.dasar === 'bangunan');
    cek('pos berdasar tanah "' + tanah.nama + '" tampil beserta tarifnya',
        t.includes(tanah.nama) && tanah.tarif !== null);
    cek('pos berdasar bangunan "' + bangunan.nama + '" dibagi luas bangunan',
        bangunan !== undefined && Math.abs(bangunan.pembagi - S.hitung.unitTotal.lb) < 0.005
        && t.includes(bangunan.nama),
        'pembagi=' + bangunan.pembagi + ' ΣLB=' + S.hitung.unitTotal.lb);
    cek('  satu kolom per pos biaya di tabel unit',
        S.hitung.komponen.every((k) => t.includes(k.nama)));
    cek('  jumlah bagian = TOTAL COGS, persis',
        Math.abs(S.hitung.unitTotal.total - S.hitung.total.total_cogs) < 0.005
        && t.includes(rp(S.hitung.unitTotal.total)));

    console.log('\n=== 5. Daftar Set Up ===');
    cek('Index memuat Set Up yang disemai', hasil.Index.teks.includes('LOKASI UJI LAYAR'));
    cek('  beserta TOTAL COGS-nya', hasil.Index.teks.includes(rp(semua.Index.rows[0].total_cogs)));

    try { fs.unlinkSync(PROPS); } catch { /* tidak apa-apa */ }

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exit(gagal === 0 ? 0 : 1);
}

main().catch((e) => { console.error(e); process.exit(1); });
