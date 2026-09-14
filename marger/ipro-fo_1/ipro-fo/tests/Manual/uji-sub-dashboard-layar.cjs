// Layar Dashboard Back Office — empat sub-dashboard (KPR & Collection, Cash Management,
// Accounting Tax & Budget, Project).
//
// KENAPA PERLU UJI:
//  · Halaman yang sama melayani lima keadaan (gabungan + empat kelompok). Kartu yang tidak
//    digerbangi akan menyentuh prop yang memang tidak dikirim, dan halamannya jatuh seluruhnya —
//    bukan sekadar kehilangan satu kartu.
//  · Sebaliknya, gerbang yang kelewat longgar membuat kartu kelompok lain ikut tampil; angkanya
//    benar, hanya berada di halaman yang salah, dan tidak ada galat apa pun.
//  · Item sidebar ber-children dirender sebagai tombol buka/tutup, BUKAN tautan. Karena itu
//    induknya tidak boleh lagi punya href yang menyesatkan, dan keempat anaknya harus ada —
//    kalau satu hilang, grafiknya tidak bisa dibuka dari mana pun.
//
// Jalankan:  node tests/Manual/uji-sub-dashboard-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/Dashboard/Index.vue');
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

/* Bentuk data terkecil yang membuat tiap kartu bisa dirender. Isinya tidak penting — yang diuji
   kartu mana yang MUNCUL, bukan angkanya. */
const bulanan = { labels: ['Jan'], series: [{ n: 'A', c: 0, v: [1] }] };
const tumpuk = { rows: [{ n: 'A', v: { x: 1 } }], keys: [{ k: 'x', n: 'X', c: 0 }] };
const barisRows = { rows: [{ n: 'A', v: 1 }] };

const CHARTS = {
    inisiasi_kpr: barisRows,
    proses_kpr: barisRows,
    penolakan: bulanan,
    rekening: barisRows,
    kasbon: bulanan,
    aging: barisRows,
    neraca: tumpuk,
    laba_rugi: { labels: ['Jan'], vals: [1] },
    aset_tetap: tumpuk,
    budget: { rows: [{ n: 'A', real: 1, plafon: 2 }] },
    spk: { rows: [{ n: 'A', jml: 1, nilai: 2 }] },
    progress: tumpuk,
    retensi: bulanan,
    bast_ajb_br: { labels: ['Jan'], series: [{ n: 'BAST', c: 0, unit: [1], nilai: 1, unit_tahunan: 1 }] },
    piutang: tumpuk,
    hpp: tumpuk,
};

const GRUP = {
    kpr: ['inisiasi_kpr', 'proses_kpr', 'penolakan'],
    cash: ['rekening', 'kasbon', 'aging'],
    accounting: ['neraca', 'laba_rugi', 'aset_tetap', 'budget', 'bast_ajb_br', 'piutang', 'hpp'],
    project: ['spk', 'progress', 'retensi'],
};
const JUDUL = {
    kpr: 'KPR & Collection',
    cash: 'Cash Management',
    accounting: 'Accounting, Tax & Budget',
    project: 'Project',
};
/** Judul kartu yang tampil di layar untuk tiap kunci grafik. */
const KARTU = {
    inisiasi_kpr: '1 · Inisiasi KPR',
    proses_kpr: '2 · Proses KPR',
    penolakan: '3 · Penolakan BI Checking',
    rekening: '4 · Summary Rekening Ledger',
    kasbon: '5 · Bon Sementara',
    aging: '6 · Aging Hutang',
    neraca: '7 · Summary Neraca',
    laba_rugi: '8 · Summary Laba Rugi',
    aset_tetap: '9 · Overview Aset Tetap',
    budget: '10 · Realisasi Budget',
    spk: '11 · SPK per Cluster',
    progress: '12 · Progress Lapangan',
    retensi: '13 · Summary Retensi',
    bast_ajb_br: '14–16 · BAST',
    piutang: '17 · Rekonsiliasi Piutang',
    hpp: '18 · Summary HPP',
};

let Komponen = null;

async function render(props, tag) {
    const out = path.join(ROOT, 'subdash-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    if (! Komponen) {
        await esbuild.build({
            entryPoints: [HALAMAN], bundle: true, format: 'cjs', platform: 'node',
            outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
        });
        delete require.cache[out];
        Komponen = require(out).default;
    }
    const rute = (n, p) => '/' + n + (p != null ? '?' + new URLSearchParams(p).toString() : '');
    globalThis.route = rute;
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = rute;

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

const periode = {
    label: 'Jan–Ags 2026', project: 'Semua', awal: '2026-01-01', akhir: '2026-08-31',
    bulan: 8, dipotong: false, maks_bulan: 36,
};

(async () => {
    console.log('=== 1. HALAMAN GABUNGAN TETAP MENAMPILKAN SELURUH KARTU ===');

    const penuh = await render({ periode, charts: CHARTS, grup: null, grupJudul: null }, 'penuh');
    const kurang = Object.entries(KARTU).filter(([, judul]) => ! penuh.includes(judul)).map(([k]) => k);
    cek('keenam belas kartu tampil', kurang.length === 0, 'tidak tampil: ' + kurang.join(', '));
    cek('judulnya Dashboard Back Office', /<h1[^>]*>\s*Dashboard Back Office\s*<\/h1>/.test(penuh));

    console.log('\n=== 2. TIAP SUB HANYA MENAMPILKAN KARTU KELOMPOKNYA ===');

    for (const [g, kunci] of Object.entries(GRUP)) {
        const charts = {};
        for (const k of kunci) charts[k] = CHARTS[k];
        const html = await render({ periode, charts, grup: g, grupJudul: JUDUL[g] }, g);

        const adaSeharusnya = kunci.filter((k) => html.includes(KARTU[k]));
        cek(`'${g}' menampilkan ${kunci.length} kartu kelompoknya`,
            adaSeharusnya.length === kunci.length, 'tampil: ' + adaSeharusnya.join(', '));

        /* Gerbang yang longgar akan menampilkan kartu kelompok lain — inilah yang paling mudah
           lolos tanpa terlihat, sebab angkanya tetap benar. */
        const bocor = Object.keys(KARTU).filter((k) => ! kunci.includes(k) && html.includes(KARTU[k]));
        cek(`'${g}' TIDAK menampilkan kartu kelompok lain`, bocor.length === 0, 'bocor: ' + bocor.join(', '));

        /* Judul dibandingkan dalam bentuk TER-ESCAPE: "KPR & Collection" keluar sebagai
           "KPR &amp; Collection" di HTML, dan pembandingan mentah akan gagal walau layarnya benar. */
        const judulHtml = JUDUL[g].replace(/&/g, '&amp;');
        cek(`'${g}' memakai judul kelompoknya di kepala halaman`,
            new RegExp('<h1[^>]*>\\s*' + judulHtml.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*</h1>').test(html),
            (html.match(/<h1[^>]*>([\s\S]*?)<\/h1>/) || [])[1]);
    }

    console.log('\n=== 3. SARINGAN PERIODE IKUT MEMBAWA KELOMPOKNYA ===');

    const src = fs.readFileSync(HALAMAN, 'utf8');
    const badanMuat = (src.match(/function muat\([\s\S]*?\n\}/) || [''])[0];
    cek('muat() membawa grup saat bernavigasi', badanMuat.includes('q.grup = props.grup'),
        'tanpa ini, menerapkan tanggal melempar balik ke halaman gabungan');

    console.log('\n=== 4. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const blok = (nav.match(/\{ label: 'Dashboard Back Office'[\s\S]*?\]\}/) || [''])[0];
    cek('item Dashboard Back Office punya children', blok.includes('children:'), blok.slice(0, 120));

    for (const [g, judul] of Object.entries(JUDUL)) {
        cek(`sub '${judul}' ada di sidebar`,
            blok.includes(`label: '${judul}'`) && blok.includes(`grup: '${g}'`), blok.slice(0, 200));
    }

    /* Item ber-children dirender sebagai tombol buka/tutup — href pada induknya tidak pernah
       dipakai, dan membiarkannya di sana hanya menyesatkan pembaca berikutnya. */
    const induk = blok.split('children:')[0];
    cek('induknya tidak lagi memakai href yang tidak pernah terpakai',
        ! induk.includes("href: 'dashboard'"), induk);

    cek('keempat sub menunjuk rute dashboard',
        (blok.match(/href: 'dashboard'/g) || []).length === 4,
        String((blok.match(/href: 'dashboard'/g) || []).length));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
