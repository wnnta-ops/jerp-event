// Layar Realisasi KPR per Cluster — dua tabel Exsum (Status Unit & Outstanding).
//
// KENAPA PERLU UJI:
//  · Dua tabel berdampingan dengan tombol Import masing-masing. Tombol yang tertukar membuat
//    orang mengganti isi tabel yang SALAH — dan importnya mengganti seluruh isi, jadi data
//    tabel lain lenyap tanpa bisa dibatalkan.
//  · Nilai Outstanding sengaja TEKS ("Rp 1,2 M (2 unit RUHA)"). Bila layarnya memaksanya jadi
//    angka, keterangan unitnya hilang tanpa satu galat pun.
//  · Nol pada tabel status ditampilkan sebagai tanda pisah, persis seperti Exsum.
//
// Jalankan:  node tests/Manual/uji-realisasi-per-cluster-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RealisasiPerCluster.vue');
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => {
            const dasar = path.join(ROOT, 'resources/js', a.path.slice(2));
            for (const p of [dasar, dasar + '.ts', dasar + '.js', dasar + '.vue',
                path.join(dasar, 'index.ts'), path.join(dasar, 'index.js')]) {
                if (fs.existsSync(p) && fs.statSync(p).isFile()) return { path: p };
            }

            return { path: dasar };
        });
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h, reactive } from 'vue';"
                  + ' export const router = { get(){}, post(){}, delete(){}, visit(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {}, post(){}, put(){}, delete(){}, reset(){} });'
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

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

const props = {
    status: [
        { label: 'Cluster 2.2 (JRK2 T2)', total_unit: 169, realisasi: 157, kpr: 158, cash: 9, proses: 0, open_stock: 2, hold: 0, escrow: 'Escrow 132 jt' },
        { label: 'Cluster 3.4 (JRK3 T4)', total_unit: 222, realisasi: 146, kpr: 163, cash: 16, proses: 17, open_stock: 43, hold: 0, escrow: 'Sisa Retensi 13,1 M' },
    ],
    statusTotal: { total_unit: 391, realisasi: 303, kpr: 321, cash: 25, proses: 17, open_stock: 45, hold: 0 },
    statusCatatan: 'Sumber: diagram Proses KPR per cluster (Exsum Collection Agustus 2026).',
    os: [
        { label: 'JRK 2 — Tahap 1', retensi: null, proses: null, open_stok: null, lain_lain: 'Clear' },
        { label: 'JRK 3 — Tahap 4', retensi: 'Rp 13,1 M', proses: 'Rp 9,6 M (17 unit)', open_stok: 'Rp 24,7 M (43 unit)', lain_lain: 'Retensi TO + BAST' },
    ],
    osCatatan: 'Unit per cluster: JRK2 273 · JRK3 331.',
};

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'rpc-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    if (! Komponen) {
        await esbuild.build({
            entryPoints: [HALAMAN], bundle: true, format: 'cjs', platform: 'node',
            outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
        });
        delete require.cache[out];
        Komponen = require(out).default;
    }
    const rute = (n, q) => '/' + n + (q ? '?' + new URLSearchParams(q).toString() : '');
    globalThis.route = rute;
    const app = createSSRApp({ render: () => h(Komponen, p) });
    app.config.globalProperties.route = rute;

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

/** Sel tiap baris tbody dari tabel ke-i. */
function baris(html, iTabel) {
    const tbody = [...html.matchAll(/<tbody[\s\S]*?<\/tbody>/g)].map((m) => m[0])[iTabel] || '';

    return [...tbody.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) =>
        [...m[0].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((c) => c[1].replace(/<[^>]+>/g, '').trim()));
}

(async () => {
    const html = await render(props, 'penuh');

    console.log('=== 1. DUA TABEL, MASING-MASING BERJUDUL SENDIRI ===');

    cek('judul tabel status ada', /Proses KPR per Cluster/.test(html));
    cek('judul tabel outstanding ada', /Outstanding \(O\/S\) KPR per Cluster/.test(html));
    cek('ada dua tabel', (html.match(/<tbody/g) || []).length === 2, String((html.match(/<tbody/g) || []).length));

    console.log('\n=== 2. TABEL STATUS UNIT ===');

    const s0 = baris(html, 0)[0];
    cek('baris 1 lengkap 9 sel', s0.length === 9, JSON.stringify(s0));
    cek('angkanya sejajar dengan judul kolomnya',
        s0.slice(0, 5).join('|') === 'Cluster 2.2 (JRK2 T2)|169|157|158|9', JSON.stringify(s0.slice(0, 5)));
    /* Nol ditampilkan sebagai tanda pisah, persis seperti Exsum. */
    cek('nol berpenanda pisah (Proses & Hold)', s0[5] === '—' && s0[7] === '—', JSON.stringify([s0[5], s0[7]]));
    cek('kolom escrow teks apa adanya', s0[8] === 'Escrow 132 jt', JSON.stringify(s0[8]));

    const tfoot0 = [...html.matchAll(/<tfoot[\s\S]*?<\/tfoot>/g)].map((m) => m[0])[0] || '';
    const t0 = [...tfoot0.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('baris TOTAL menjumlah', t0.slice(0, 4).join('|') === 'TOTAL|391|303|321', JSON.stringify(t0));

    console.log('\n=== 3. TABEL OUTSTANDING — NILAINYA TEKS ===');

    const o1 = baris(html, 1)[1];
    cek('nilai berketerangan unit tetap utuh',
        o1[2] === 'Rp 9,6 M (17 unit)' && o1[3] === 'Rp 24,7 M (43 unit)', JSON.stringify(o1));
    const o0 = baris(html, 1)[0];
    cek('kolom kosong berpenanda pisah', o0[1] === '—' && o0[2] === '—', JSON.stringify(o0));
    cek('teks bebas "Clear" tampil', o0[4] === 'Clear', JSON.stringify(o0[4]));

    console.log('\n=== 4. CATATAN KAKI TIAP TABEL TERPISAH ===');

    cek('catatan tabel status tampil', html.includes('Exsum Collection Agustus 2026'), 'tidak tampil');
    cek('catatan tabel outstanding tampil', html.includes('JRK2 273'), 'tidak tampil');

    console.log('\n=== 5. TOMBOL IMPORT TERPISAH UNTUK TIAP TABEL ===');

    /* Tombol yang tertukar membuat orang mengganti isi tabel yang SALAH — dan importnya
       mengganti seluruh isi, jadi data tabel lain lenyap tanpa bisa dibatalkan. */
    const src = fs.readFileSync(HALAMAN, 'utf8');
    cek("tombol tabel status memanggil buka = 'status'", src.includes(`@click="buka = 'status'"`), 'tidak ada');
    cek("tombol tabel outstanding memanggil buka = 'os'", src.includes(`@click="buka = 'os'"`), 'tidak ada');
    cek('jenis yang dikirim mengikuti tombol yang ditekan',
        src.includes("form.jenis = buka.value ?? 'status'"), 'jenis dipatok, bukan mengikuti tombol');
    cek('template diunduh sesuai jenisnya',
        src.includes("route('kpr.database.realisasi-cluster.template', { jenis: buka })"), 'jenis tidak diteruskan');

    console.log('\n=== 6. PERINGATAN BAHWA IMPORT MENGGANTI SELURUH ISI ===');

    cek('disebutkan di modal', /mengganti <b>seluruh isi tabel ini<\/b>|mengganti seluruh isi tabel ini/.test(src), 'tidak disebutkan');
    cek('disebutkan juga di kaki halaman', html.includes('mengganti seluruh isi tabelnya'), 'tidak disebutkan');

    console.log('\n=== 7. BELUM ADA DATA ===');

    const kosong = await render({ status: [], statusTotal: props.statusTotal, statusCatatan: null, os: [], osCatatan: null }, 'kosong');
    cek('kedua tabel punya keterangan kosongnya sendiri',
        kosong.includes('Belum ada data Proses KPR per Cluster') && kosong.includes('Belum ada data Outstanding KPR per Cluster'),
        'salah satu tidak ada');

    console.log('\n=== 8. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const sesudah = (nav.match(/\{ label: 'Realisasi KPR per Bank',[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Realisasi KPR per Cluster ada di grup Query/Report KPR',
        sesudah === 'Realisasi KPR per Cluster', sesudah ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute yang benar', nav.includes("href: 'kpr.database.realisasi-cluster'"));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
