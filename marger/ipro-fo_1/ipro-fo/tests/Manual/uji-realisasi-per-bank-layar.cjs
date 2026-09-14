// Layar Realisasi KPR per Bank — tabel silang cluster × bank + kartu komposisi.
//
// KENAPA PERLU UJI:
//  · Kolom banknya DINAMIS (dibentuk dari data). Urutan sel yang bergeser satu kolom membuat
//    angka bank A tampil di bawah judul bank B — tanpa galat apa pun.
//  · Bar komposisi diskalakan terhadap bank TERBESAR, bukan 100%. Diskalakan ke 100, bank 0,8%
//    menghasilkan garis yang tak terlihat sama sekali — dan garis yang tak terlihat terbaca
//    sebagai "tidak ada", padahal angkanya tertulis di sebelahnya.
//  · Nol ditampilkan sebagai tanda pisah; deretan "0" pada tabel silang menenggelamkan sel yang
//    benar-benar berisi.
//
// Jalankan:  node tests/Manual/uji-realisasi-per-bank-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RealisasiPerBank.vue');
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

/* Angka mirip lampiran: 915 total, Mandiri 7 unit (0,8%). */
const banks = ['BNI', 'BRI', 'BTN', 'BTN SYARIAH', 'MANDIRI'];
const rows = [
    { cluster: 'JRK 2 (Cluster 2)', total: 250, bank: { BNI: 7, BRI: 39, BTN: 183, 'BTN SYARIAH': 20, MANDIRI: 1 } },
    { cluster: 'JRK 3 (Cluster 3)', total: 455, bank: { BNI: 2, BRI: 47, BTN: 289, 'BTN SYARIAH': 111, MANDIRI: 6 } },
    { cluster: 'JRS 5 (Cluster 5)', total: 210, bank: { BNI: 0, BRI: 0, BTN: 166, 'BTN SYARIAH': 44, MANDIRI: 0 } },
];
const totalBank = { BNI: 9, BRI: 86, BTN: 638, 'BTN SYARIAH': 175, MANDIRI: 7 };
const grand = 915;
const komposisi = [
    { bank: 'BTN', unit: 638, persen: 69.7 },
    { bank: 'BTN SYARIAH', unit: 175, persen: 19.1 },
    { bank: 'BRI', unit: 86, persen: 9.4 },
    { bank: 'BNI', unit: 9, persen: 1 },
    { bank: 'MANDIRI', unit: 7, persen: 0.8 },
];

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'rpb-' + tag + '.bundle.tmp.cjs');
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

function selBaris(html, i) {
    const tbody = (html.match(/<tbody[\s\S]*?<\/tbody>/) || [''])[0];
    const tr = [...tbody.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) => m[0])[i] || '';

    return [...tr.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((c) => c[1].replace(/<[^>]+>/g, '').trim());
}

(async () => {
    const props = { banks, rows, totalBank, grand, komposisi };
    const html = await render(props, 'penuh');

    console.log('=== 1. JUDUL KOLOM BANK MENGIKUTI DATA ===');

    const thead = (html.match(/<thead[\s\S]*?<\/thead>/) || [''])[0];
    const judul = [...thead.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('urutannya Cluster · Total · lalu kelima bank',
        judul.join(' | ') === ['Cluster', 'Total', ...banks].join(' | '), judul.join(' | '));

    console.log('\n=== 2. SEL TIAP BARIS SEJAJAR DENGAN JUDUL KOLOMNYA ===');

    /* Inilah yang paling mudah salah tanpa terlihat: angka bank A tampil di bawah judul bank B.
       Diperiksa pada URUTAN selnya, bukan sekadar "angkanya ada di halaman". */
    const b0 = selBaris(html, 0);
    cek('baris 1: cluster & total', b0[0] === 'JRK 2 (Cluster 2)' && b0[1] === '250', JSON.stringify(b0.slice(0, 2)));
    cek('baris 1: BNI 7 · BRI 39 · BTN 183 · BTN SYARIAH 20 · MANDIRI 1',
        b0.slice(2).join('|') === '7|39|183|20|1', JSON.stringify(b0.slice(2)));

    const b2 = selBaris(html, 2);
    cek('baris 3: bank tanpa akad berpenanda pisah, bukan 0',
        b2.slice(2).join('|') === '—|—|166|44|—', JSON.stringify(b2.slice(2)));

    console.log('\n=== 3. BARIS TOTAL ===');

    const tfoot = (html.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    const t = [...tfoot.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('TOTAL 915 dan kelima kolom banknya',
        t.join('|') === 'TOTAL|915|9|86|638|175|7', JSON.stringify(t));

    console.log('\n=== 4. KOMPOSISI BANK ===');

    cek('BTN disebut dengan 638 KPR', /BTN[\s\S]{0,200}?638 KPR/.test(html), 'tidak ditemukan');
    /* Sesuai lampiran: bulat untuk yang ≥ 1%, satu desimal untuk yang di bawahnya. */
    cek('persentase di bawah 1% tetap berdesimal (0,8%)', html.includes('0,8%'), 'tidak ada 0,8%');
    cek('persentase besar ditulis bulat (70%, bukan 69,7%)',
        html.includes('70%') && ! html.includes('69,7%'), 'tidak ada 70%');
    cek('19% & 9% juga bulat', html.includes('19%') && html.includes('9%'), 'tidak bulat');
    cek('1% tidak ditulis 1,0%', html.includes('1%') && ! html.includes('1,0%'), 'ditulis 1,0%');

    /* Bar diskalakan terhadap bank TERBESAR: BTN (638) harus 100%, dan MANDIRI (7) harus lebih
       dari 0% supaya garisnya tetap terlihat. */
    const lebar = [...html.matchAll(/width:\s*([0-9]+)%/g)].map((m) => Number(m[1]));
    cek('ada 5 bar', lebar.length === 5, JSON.stringify(lebar));
    cek('bar terbesar 100%', Math.max(...lebar) === 100, JSON.stringify(lebar));

    /* Bank yang rasionya MEMBULAT KE NOL — 2 dari 638 = 0,3%. Tanpa penjagaan Math.max(1, …)
       batangnya hilang sama sekali, dan batang yang hilang terbaca "tidak ada" padahal
       angkanya tertulis di sebelahnya. Fikstur di atas (7 dari 638) membulat ke 1 sehingga
       penjagaannya tidak pernah teruji. */
    const kecil = await render({
        ...props,
        komposisi: [
            { bank: 'BTN', unit: 638, persen: 99.7 },
            { bank: 'MANDIRI', unit: 2, persen: 0.3 },
        ],
    }, 'kecil');
    const lebarKecil = [...kecil.matchAll(/width:\s*([0-9]+)%/g)].map((m) => Number(m[1]));
    cek('bar bank sangat kecil tetap terlihat (> 0%)',
        lebarKecil.length === 2 && Math.min(...lebarKecil) > 0, JSON.stringify(lebarKecil));

    console.log('\n=== 5. BELUM ADA AKAD ===');

    const kosong = await render({ banks: [], rows: [], totalBank: {}, grand: 0, komposisi: [] }, 'kosong');
    /* KEDUA kartu harus punya keterangan kosongnya sendiri. Judulnya sama persis, jadi
       memeriksa "ada di halaman" akan tetap lulus walau salah satu kartunya dibiarkan kosong
       melompong — dan kartu kosong tanpa keterangan terbaca sebagai layar yang rusak. */
    cek('kedua kartu punya keterangan kosong', (kosong.match(/Belum ada akad KPR/g) || []).length === 2,
        String((kosong.match(/Belum ada akad KPR/g) || []).length));
    cek('keterangan tabel menyebut terisi sendiri', kosong.includes('terisi sendiri'), 'tidak disebutkan');
    cek('keterangan komposisi menyebut sesudah ada akad', kosong.includes('setelah ada akad'), 'tidak disebutkan');
    cek('tidak menjanjikan unggahan — halaman ini dihitung otomatis',
        ! /Import/i.test(kosong), 'ada kata Import padahal halaman ini tidak mengimpor apa pun');

    console.log('\n=== 6. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const sesudah = (nav.match(/\{ label: 'Realisasi vs Budget',[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Realisasi KPR per Bank ada di grup Query/Report KPR',
        sesudah === 'Realisasi KPR per Bank', sesudah ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute yang benar', nav.includes("href: 'kpr.database.realisasi-bank'"));
    cek('izinnya sama dengan tetangganya',
        /\{ label: 'Realisasi KPR per Bank'[^\n]*permission: 'bankloan\.application\.view'/.test(nav));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
