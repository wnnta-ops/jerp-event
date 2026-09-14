// Layar Realisasi vs Budget (KPR) — target akad per bulan vs realisasinya.
//
// KENAPA PERLU UJI:
//  · Baris YTD disisipkan DI TENGAH tabel, tepat sesudah bulan terakhir yang ada realisasinya.
//    Gerbang yang salah membuatnya muncul di setiap baris, atau tidak muncul sama sekali —
//    keduanya tidak menimbulkan galat apa pun.
//  · Nilai disimpan rupiah penuh dan disingkat ke "Rp M" hanya saat ditampilkan. Pembagi yang
//    keliru menggeser angkanya seribu kali lipat sambil tetap terlihat wajar.
//  · Bulan yang belum terjadi harus berpenanda pisah, bukan 0% — nol terbaca sebagai gagal
//    total padahal bulannya memang belum dilewati.
//
// Jalankan:  node tests/Manual/uji-realisasi-vs-budget-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RealisasiVsBudget.vue');
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

const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/** Angka mirip lampiran: realisasi berhenti di Agustus. */
const T_UNIT = [93, 52, 42, 100, 81, 62, 30, 74, 60, 60, 56, 31];
const R_UNIT = [33, 39, 27, 65, 33, 50, 32, 36, 0, 0, 0, 0];
const T_VAL = [50.6, 28.9, 23.9, 56.2, 43.5, 35.0, 17.0, 35.3, 31.1, 31.1, 28.9, 15.5];
const R_VAL = [13.5, 42.7, 36.1, 21.7, 19.9, 23.8, 21.5, 20.0, 0, 0, 0, 0];

const rows = BULAN.map((label, i) => ({
    month: i + 1, label,
    target_unit: T_UNIT[i], realisasi_unit: R_UNIT[i],
    target_value: T_VAL[i] * 1e9, realisasi_value: R_VAL[i] * 1e9,
    pencapaian: T_UNIT[i] > 0 && R_UNIT[i] > 0 ? Math.round(R_UNIT[i] / T_UNIT[i] * 100) : null,
    ada_realisasi: R_UNIT[i] > 0,
}));

const jml = (a, n) => a.slice(0, n).reduce((x, y) => x + y, 0);

const props = {
    year: 2026, month: 0, years: [2027, 2026, 2025, 2024, 2023],
    rows,
    ytd: {
        sampai: 8, label: 'YTD Jan–Agu',
        target_unit: jml(T_UNIT, 8), realisasi_unit: jml(R_UNIT, 8),
        target_value: jml(T_VAL, 8) * 1e9, realisasi_value: jml(R_VAL, 8) * 1e9,
        pencapaian: Math.round(jml(R_UNIT, 8) / jml(T_UNIT, 8) * 100),
    },
    total: {
        target_unit: jml(T_UNIT, 12), realisasi_unit: jml(R_UNIT, 12),
        target_value: jml(T_VAL, 12) * 1e9, realisasi_value: jml(R_VAL, 12) * 1e9,
        pencapaian: Math.round(jml(R_UNIT, 12) / jml(T_UNIT, 12) * 100),
        pencapaian_value: Math.round(jml(R_VAL, 12) / jml(T_VAL, 12) * 100),
    },
    tanpaTanggal: 0,
};

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'rvb-' + tag + '.bundle.tmp.cjs');
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

/** Isi sel tiap baris <tbody>, berurutan. */
function barisTbody(html) {
    const tbody = (html.match(/<tbody[\s\S]*?<\/tbody>/) || [''])[0];

    return [...tbody.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) =>
        [...m[0].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((c) => c[1].replace(/<[^>]+>/g, '').trim()));
}

(async () => {
    console.log('=== 1. TABEL 12 BULAN + BARIS YTD DI TENGAH ===');

    const html = await render(props, 'penuh');
    const baris = barisTbody(html);

    cek('ada 13 baris — 12 bulan + 1 baris YTD', baris.length === 13, String(baris.length));
    cek('baris ke-9 adalah YTD (tepat sesudah Agustus)',
        (baris[8] || [])[0] === 'YTD Jan–Agu', JSON.stringify((baris[8] || [])[0]));
    cek('baris sesudahnya kembali ke September',
        (baris[9] || [])[0] === 'September', JSON.stringify((baris[9] || [])[0]));

    console.log('\n=== 2. ANGKANYA SESUAI ===');

    const jan = baris[0];
    cek('Januari: target 93 · realisasi 33 · 35%',
        jan[1] === '93' && jan[2] === '33' && jan[3] === '35%', JSON.stringify(jan));
    /* Nilai disimpan rupiah PENUH; layar menyingkatnya ke miliar. Pembagi yang keliru menggeser
       angkanya seribu kali lipat sambil tetap terlihat wajar. */
    cek('Januari: target value tampil 50,6 (dari 50.600.000.000)', jan[4] === '50,6', JSON.stringify(jan[4]));
    cek('Januari: realisasi value tampil 13,5', jan[5] === '13,5', JSON.stringify(jan[5]));

    const ytd = baris[8];
    cek('YTD: 534 unit target · 315 realisasi · 59%',
        ytd[1] === '534' && ytd[2] === '315' && ytd[3] === '59%', JSON.stringify(ytd));

    console.log('\n=== 3. BULAN YANG BELUM TERJADI ===');

    const sep = baris[9];
    cek('September: target tetap tampil', sep[1] === '60', JSON.stringify(sep[1]));
    cek('September: realisasi & pencapaian berpenanda pisah, BUKAN 0%',
        sep[2] === '—' && sep[3] === '—', JSON.stringify([sep[2], sep[3]]));
    cek('September: realisasi value juga berpenanda pisah', sep[5] === '—', JSON.stringify(sep[5]));

    console.log('\n=== 4. BARIS TOTAL ===');

    const tfoot = (html.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    const sel = [...tfoot.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('TOTAL menyebut tahunnya', sel[0] === 'TOTAL 2026', JSON.stringify(sel[0]));
    cek('TOTAL unit 741 target · 315 realisasi', sel[1] === '741' && sel[2] === '315', JSON.stringify(sel));
    cek('TOTAL value 397 (dibulatkan satu desimal: 397,0)', sel[4] === '397,0', JSON.stringify(sel[4]));

    console.log('\n=== 5. SATU BULAN TERPILIH ===');

    const satu = await render({ ...props, month: 2 }, 'satu');
    const b2 = barisTbody(satu);
    cek('hanya satu baris bulan yang tampil', b2.length === 1, String(b2.length));
    cek('yang tampil Februari', (b2[0] || [])[0] === 'Februari', JSON.stringify((b2[0] || [])[0]));
    /* TOTAL tetap dihitung dari seluruh tahun — itulah gunanya baris itu. */
    const tfoot2 = (satu.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    cek('TOTAL tetap 741 walau hanya satu bulan ditampilkan', tfoot2.includes('741'), tfoot2.slice(0, 200));
    /* Diperiksa pada BARIS TABELNYA, bukan pada seluruh halaman: label yang sama juga dipakai
       paragraf ringkasan di atas tabel, sehingga pemeriksaan yang longgar akan lulus walau baris
       YTD-nya benar-benar tersisip. */
    cek('baris YTD tidak disisipkan saat satu bulan dipilih',
        ! b2.some((r) => (r[0] || '').startsWith('YTD')), JSON.stringify(b2.map((r) => r[0])));

    console.log('\n=== 6. AKAD TANPA TANGGAL DISEBUTKAN ===');

    const tanpa = await render({ ...props, tanpaTanggal: 3 }, 'tanpa');
    cek('peringatan muncul saat ada akad tanpa tanggal', /3 akad sudah terjadi/.test(tanpa), 'tidak disebutkan');
    cek('peringatan TIDAK muncul saat nihil', ! /akad sudah terjadi/.test(html));

    console.log('\n=== 7. TARGET BELUM DIISI ===');

    const kosong = await render({
        ...props,
        rows: rows.map((r) => ({ ...r, target_unit: 0, target_value: 0 })),
        total: { ...props.total, target_unit: 0, target_value: 0, pencapaian: null, pencapaian_value: null },
    }, 'kosong');
    cek('layar mengajak mengisi target, bukan diam', kosong.includes('Target belum diisi'), 'tidak ada EmptyState');

    console.log('\n=== 8. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    /* Diperiksa pada TETANGGA LANGSUNGNYA: namanya juga muncul sebagai nama rute, sehingga
       pemeriksaan yang longgar tetap lulus walau menunya pindah grup. */
    const sesudah = (nav.match(/\{ label: 'Reject Proses KPR',[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Realisasi vs Budget tepat di bawah Reject Proses KPR',
        sesudah === 'Realisasi vs Budget', sesudah ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute yang benar', nav.includes("href: 'kpr.database.realisasi-budget'"));
    cek('izinnya sama dengan tetangganya di Query/Report',
        /\{ label: 'Realisasi vs Budget'[^\n]*permission: 'bankloan\.application\.view'/.test(nav));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
