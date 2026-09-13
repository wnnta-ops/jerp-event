// Layar Aging Hutang Supplier — Top 20 + Others + TOTAL.
//
// KENAPA PERLU UJI:
//  · Baris Others & TOTAL menjawab "berapa SELURUHNYA". Bila keduanya ikut tersaring kotak
//    pencarian, jumlahnya berubah-ubah mengikuti apa yang sedang diketik — dan angka yang
//    berubah saat mencari adalah angka yang tidak bisa dipercaya.
//  · Rupiah disingkat ke M / jt. Pembagi yang keliru menggeser angkanya seribu kali lipat
//    sambil tetap terlihat wajar; angka penuhnya dijaga tetap ada di atribut title.
//  · Others hanya boleh muncul bila memang ada supplier di luar Top 20.
//
// Jalankan:  node tests/Manual/uji-aging-hutang-supplier-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/AgingSupplier.vue');
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

const rows = [
    { name: 'PT Mitra Usaha Konstruksi', b0: 8_170_000_000, b31: 734_000_000, b60: 2, total: 8_910_000_000 },
    { name: 'Pengembalian PPN DTP', b0: 1_070_000_000, b31: 0, b60: 0, total: 1_070_000_000 },
    { name: 'LAC', b0: 0, b31: 0, b60: 575_000_000, total: 575_000_000 },
];
const others = { jumlah: 60, b0: 1_950_000_000, b31: 231_300_000, b60: 545_000_000, total: 2_715_617_641 };
const total = { jumlah: 63, b0: 11_190_000_000, b31: 965_300_000, b60: 1_120_000_000, total: 13_273_617_641 };

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'ags-' + tag + '.bundle.tmp.cjs');
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

function barisTbody(html) {
    const tbody = (html.match(/<tbody[\s\S]*?<\/tbody>/) || [''])[0];

    return [...tbody.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) =>
        [...m[0].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((c) => c[1].replace(/<[^>]+>/g, '').trim()));
}

(async () => {
    const props = { rows, others, total, dominan: rows[0], topN: 20, dateFrom: null, dateTo: '2026-09-13' };
    const html = await render(props, 'penuh');

    console.log('=== 1. BARIS SUPPLIER + OTHERS ===');

    const b = barisTbody(html);
    cek('tiga supplier + satu baris Others', b.length === 4, String(b.length));
    cek('baris Others menyebut jumlah supplier yang dirangkum',
        (b[3] || [])[0] === 'Others (60 supplier lainnya)', JSON.stringify((b[3] || [])[0]));

    console.log('\n=== 2. RUPIAH DISINGKAT, ANGKA PENUH TETAP ADA ===');

    cek('miliar disingkat M', (b[0] || [])[1] === 'Rp 8,91 M', JSON.stringify((b[0] || [])[1]));
    cek('juta disingkat jt', (b[0] || [])[2] === 'Rp 8,17 M' && (b[1] || [])[2] === 'Rp 1,07 M',
        JSON.stringify([(b[0] || [])[2], (b[1] || [])[2]]));
    cek('nol berpenanda pisah', (b[1] || [])[3] === '—' && (b[1] || [])[4] === '—',
        JSON.stringify([(b[1] || [])[3], (b[1] || [])[4]]));
    /* Angka penuhnya harus tetap terjangkau — penyingkatan tidak boleh membuang ketelitian. */
    cek('angka penuh disimpan di title', html.includes('Rp 8.910.000.000'), 'title angka penuh tidak ada');

    console.log('\n=== 3. BARIS TOTAL ===');

    const tfoot = (html.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    const t = [...tfoot.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('TOTAL menyebut jumlah seluruh supplier', t[0] === 'TOTAL (63 supplier)', JSON.stringify(t[0]));
    cek('TOTAL 13,27 M', t[1] === 'Rp 13,27 M', JSON.stringify(t[1]));

    console.log('\n=== 4. KETERANGAN KAKI ===');

    cek('menyebut total seluruh supplier dengan angka penuh', html.includes('Rp 13.273.617.641'), 'tidak disebutkan');
    /* Diperiksa pada KALIMATNYA, bukan sekadar "namanya ada di halaman": nama yang sama juga
       muncul sebagai baris tabel, sehingga pemeriksaan longgar tetap lulus walau kalimat
       dominannya dihapus seluruhnya. */
    cek('menyebut supplier dominan beserta nilainya',
        /Didominasi[\s\S]{0,80}PT Mitra Usaha Konstruksi[\s\S]{0,40}Rp 8,91 M/.test(html), 'kalimat dominan tidak ada');
    /* Tanpa kalimat ini, orang akan mengira kolom 0–30 hanya berisi yang sudah jatuh tempo. */
    cek('menerangkan 0–30 sudah termasuk yang belum jatuh tempo',
        /0–30<\/b> sudah termasuk[\s\S]{0,80}belum jatuh tempo/.test(html), 'tidak diterangkan');
    cek('menerangkan angkanya dihitung, tidak perlu diunggah',
        html.includes('tidak perlu diunggah'), 'tidak diterangkan');

    console.log('\n=== 5. PENCARIAN TIDAK MENGUBAH OTHERS & TOTAL ===');

    /* Diperiksa di SUMBER: pencarian hanya menyaring props.rows. Others & TOTAL datang dari prop
       terpisah, sehingga jumlahnya tidak ikut berubah mengikuti apa yang diketik. */
    const src = fs.readFileSync(HALAMAN, 'utf8');
    const badan = (src.match(/const tampil = computed\([\s\S]*?\}\);/) || [''])[0];
    cek('pencarian hanya menyaring props.rows', badan.includes('props.rows.filter'), badan.slice(0, 120));
    cek('baris Others TIDAK ikut disaring', ! badan.includes('others'), badan.slice(0, 120));
    cek('baris Others dirender di luar perulangan supplier',
        /<tr v-if="others"/.test(src), 'Others ikut di dalam v-for');

    console.log('\n=== 6. OTHERS TIDAK MUNCUL BILA NIHIL ===');

    const tanpa = await render({ ...props, others: null, total: { ...total, jumlah: 3 } }, 'tanpa');
    const b2 = barisTbody(tanpa);
    cek('hanya tiga baris supplier', b2.length === 3, String(b2.length));
    cek('tidak ada baris Others', ! b2.some((r) => (r[0] || '').startsWith('Others')), JSON.stringify(b2.map((r) => r[0])));

    console.log('\n=== 7. TIDAK ADA HUTANG SAMA SEKALI ===');

    const kosong = await render({ ...props, rows: [], others: null, dominan: null, total: { jumlah: 0, b0: 0, b31: 0, b60: 0, total: 0 } }, 'kosong');
    cek('layar menerangkan keadaannya', kosong.includes('Tidak ada hutang outstanding'), 'tidak ada EmptyState');
    cek('tabelnya tidak dirender', ! /<tbody/.test(kosong), 'tabel tetap dirender');

    console.log('\n=== 8. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const sesudah = (nav.match(/\{ label: 'Aging Hutang', href: 'bs\.ap\.aging'[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Aging Hutang Supplier tepat di bawah Aging Hutang',
        sesudah === 'Aging Hutang Supplier', sesudah ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute yang benar', nav.includes("href: 'bs.ap.aging-supplier'"));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
