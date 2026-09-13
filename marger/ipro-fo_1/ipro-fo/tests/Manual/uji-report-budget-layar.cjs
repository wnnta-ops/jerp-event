// Layar Report Budget — blok per cost center, tabel per akun, batang absorpsi.
//
// KENAPA PERLU UJI:
//  · Batang absorpsi ditulis BULAT tapi warnanya memakai angka aslinya. 69,6% yang tertulis
//    "70%" tidak boleh ikut berpindah ke pita hijau hanya karena pembulatan tampilan —
//    warnanya yang dibaca sekilas, angkanya yang dibaca teliti.
//  · Tanpa anggaran, absorpsi harus "—". Batang kosong terbaca "0% terpakai, aman" padahal
//    justru tidak ada anggarannya sama sekali.
//  · Rupiah di tabel ditulis penuh; di kepala blok disingkat ke M/jt dengan angka penuh
//    disimpan di title. Pembagi yang keliru menggeser angkanya seribu kali lipat sambil
//    tetap terlihat wajar.
//  · Blok lintas cost center wajib menerangkan dirinya. Tanpa keterangan itu, pembaca
//    menjumlahkan sendiri seluruh blok dan hasilnya tidak akan cocok dengan totalnya.
//  · Layar TIDAK menghitung ulang ringkasan — ia menampilkan apa yang dikirim controller.
//
// Jalankan:  node tests/Manual/uji-report-budget-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Budget/Query/Report.vue');
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

/* Angkanya sengaja memakai bentuk yang nyata dari layar aslinya: ada yang tanpa anggaran,
   ada yang melewati anggaran, dan ada 69,6% yang membulat ke "70%". */
const barisCM = [
    { code: '10103010101', name: 'PIUTANG LAINNYA', real: 310404, budget: 2674985, sisa: 2364581, pct_sisa: 88.4, absorpsi: 11.6 },
    { code: '20102020201', name: 'UTANG BANK JANGKA PENDEK - BCA', real: 2608105088, budget: 3725864411, sisa: 1117759323, pct_sisa: 30.0, absorpsi: 70.0 },
    { code: '50302020202', name: 'JASA APPRAISAL', real: 33513514, budget: 0, sisa: -33513514, pct_sisa: -100.0, absorpsi: null },
    { code: '50302020210', name: 'BIAYA PERLENGKAPAN KANTOR', real: 38404815, budget: 40005016, sisa: 1600201, pct_sisa: 4.0, absorpsi: 96.0 },
    { code: '70101010101', name: 'BIAYA BUNGA PINJAMAN', real: 36400316, budget: 34910025, sisa: -1490291, pct_sisa: -4.3, absorpsi: 104.3 },
];
/* Ringkasan sengaja BUKAN jumlah barisnya: yang diuji di sini adalah bahwa layar menampilkan
   ringkasan kiriman controller apa adanya, bukan menghitungnya ulang sendiri. Kecocokan
   ringkasan dengan barisnya sudah dijaga uji PHP (uji-report-budget.php bagian 7). */
const ringkasCM = { akun: 5, real: 43610000000, budget: 62650000000, sisa: 19040000000, pct_sisa: 30.4, absorpsi: 69.6 };
const barisLintas = [
    { code: '10186030322', name: 'PREPAID PROMOSI DAN KOMISI - JRK C.3', real: 9913420324, budget: 14233832705, sisa: 4320412381, pct_sisa: 30.4, absorpsi: 69.7 },
];

const props = {
    filters: { year: 2047, cost_center_id: null, sd_bulan: 12 },
    sections: [
        { key: '2', name: 'Cash Management', code: 'CC-CM', lintas: false, rows: barisCM, summary: ringkasCM },
        {
            key: 'semua', name: 'Semua Cost Center', code: null, lintas: true, rows: barisLintas,
            summary: { akun: 1, real: 9913420324, budget: 14233832705, sisa: 4320412381, pct_sisa: 30.4, absorpsi: 69.7 },
        },
    ],
    total: { akun: 5, cost_center: 1, lintas: 1, real: 43610000000, budget: 62650000000, sisa: 19040000000, pct_sisa: 30.4, absorpsi: 69.6 },
    costCenters: [{ id: 2, code: 'CC-CM', name: 'Cash Management' }],
    monthLabels: ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
};

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'rb-' + tag + '.bundle.tmp.cjs');
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

/* Halaman dipotong per blok: <h2> hanya dipakai judul blok (judul halaman memakai <h1>),
   jadi tiap potongan berisi SATU blok — kepala, batang, keterangan, dan tabelnya. Tanpa
   pemotongan ini, pemeriksaan "ada teks X" bisa lulus karena teks itu ada di blok lain. */
function blok(html) { return html.split('<h2').slice(1); }
function barisTabel(fragmen) {
    const tbody = (fragmen.match(/<tbody[\s\S]*?<\/tbody>/) || [''])[0];

    return [...tbody.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) => ({
        html: m[0],
        sel: [...m[0].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((c) => c[1].replace(/<[^>]+>/g, '').trim()),
    }));
}
/** Kelas warna batang absorpsi di dalam satu potongan html. */
function pita(fragmen) { return (fragmen.match(/class="h-full rounded-full ([^"]*)"/) || [])[1]; }

(async () => {
    const html = await render(props, 'penuh');
    const b = blok(html);

    console.log('=== 1. SATU BLOK PER COST CENTER ===');

    cek('dua blok dirender', b.length === 2, String(b.length));
    cek('blok pertama menyebut nama, kode & jumlah akunnya',
        /Cash Management/.test(b[0]) && /CC-CM/.test(b[0]) && /5 akun/.test(b[0]),
        b[0].slice(0, 200));
    cek('blok kedua adalah blok lintas cost center', /Semua Cost Center/.test(b[1]) && /1 akun/.test(b[1]), b[1].slice(0, 160));
    /* Kode cost center hanya milik cost center sungguhan; blok "Semua" tidak punya. */
    cek('blok lintas tidak menampilkan kode cost center', ! /CC-CM/.test(b[1]), 'kode ikut muncul');

    console.log('\n=== 2. BARIS AKUN ===');

    const r = barisTabel(b[0]);
    cek('lima baris akun', r.length === 5, String(r.length));
    cek('kolomnya: kode, nama, realisasi, budget, sisa, % sisa, absorpsi',
        (r[0].sel || []).length === 7, String((r[0].sel || []).length));
    cek('kode & nama akun ditampilkan',
        r[0].sel[0] === '10103010101' && r[0].sel[1] === 'PIUTANG LAINNYA', JSON.stringify(r[0].sel.slice(0, 2)));

    console.log('\n=== 3. RUPIAH PENUH DI TABEL, NEGATIF DALAM KURUNG ===');

    cek('rupiah ditulis penuh, bukan disingkat',
        r[0].sel[2] === 'Rp 310.404' && r[0].sel[3] === 'Rp 2.674.985', JSON.stringify(r[0].sel.slice(2, 4)));
    cek('miliaran pun ditulis penuh', r[1].sel[2] === 'Rp 2.608.105.088', JSON.stringify(r[1].sel[2]));
    /* Kurung, bukan tanda minus — kebiasaan laporan keuangan, dan minus mudah terlewat. */
    cek('sisa negatif ditulis dalam kurung', r[2].sel[4] === '(Rp 33.513.514)', JSON.stringify(r[2].sel[4]));
    cek('sisa positif tanpa kurung', r[0].sel[4] === 'Rp 2.364.581', JSON.stringify(r[0].sel[4]));

    console.log('\n=== 4. PERSENTASE ===');

    cek('% sisa satu angka di belakang koma', r[0].sel[5] === '88,4%', JSON.stringify(r[0].sel[5]));
    cek('% sisa bulat pun tetap berkoma satu angka', r[1].sel[5] === '30,0%', JSON.stringify(r[1].sel[5]));
    cek('tanpa anggaran → −100,0%', r[2].sel[5] === '-100,0%', JSON.stringify(r[2].sel[5]));
    cek('melewati anggaran → % sisa negatif', r[4].sel[5] === '-4,3%', JSON.stringify(r[4].sel[5]));

    console.log('\n=== 5. ABSORPSI: ANGKA BULAT, WARNA DARI ANGKA ASLINYA ===');

    cek('absorpsi ditulis bulat', r[0].sel[6] === '12%' && r[3].sel[6] === '96%', JSON.stringify([r[0].sel[6], r[3].sel[6]]));
    cek('di atas 100% tetap ditulis apa adanya', r[4].sel[6] === '104%', JSON.stringify(r[4].sel[6]));
    /* Tanpa anggaran, "0%" berbohong: batang kosong terbaca aman padahal tidak ada plafonnya. */
    cek('tanpa anggaran absorpsi ditulis "—", bukan 0%', r[2].sel[6] === '—', JSON.stringify(r[2].sel[6]));
    cek('tanpa anggaran batangnya netral, bukan warna kondisi',
        pita(r[2].html) === 'bg-neutral-300 dark:bg-neutral-700', String(pita(r[2].html)));
    cek('di bawah 70% berpita biru', pita(r[0].html) === 'bg-primary-500', String(pita(r[0].html)));
    cek('tepat 70% sudah berpita hijau', pita(r[1].html) === 'bg-success-500', String(pita(r[1].html)));
    cek('95% ke atas berpita kuning', pita(r[3].html) === 'bg-amber-500', String(pita(r[3].html)));
    cek('di atas 100% berpita merah', pita(r[4].html) === 'bg-danger-500', String(pita(r[4].html)));
    /* Inti ujinya: kepala blok menulis "70%" (dari 69,6) tetapi pitanya HARUS masih biru.
       Membulatkan dulu baru mewarnai membuatnya hijau — terbaca sehat sebelum waktunya. */
    const kepala = b[0].split('<table')[0];
    cek('kepala blok menulis 69,6% sebagai "70% terpakai"', /70% terpakai/.test(kepala), 'teks tidak ada');
    cek('tapi pitanya masih biru karena aslinya 69,6', pita(kepala) === 'bg-primary-500', String(pita(kepala)));

    console.log('\n=== 6. BARIS YANG MELEWATI ANGGARAN DITANDAI ===');

    cek('baris bersisa negatif diberi latar merah',
        /bg-danger-50/.test(r[2].html) && /bg-danger-50/.test(r[4].html), 'tidak ditandai');
    cek('baris bersisa positif TIDAK ikut ditandai',
        ! /bg-danger-50/.test(r[0].html) && ! /bg-danger-50/.test(r[1].html), 'ikut ditandai');

    console.log('\n=== 7. KEPALA BLOK: SINGKAT DI LAYAR, PENUH DI TITLE ===');

    cek('miliaran disingkat M di kepala blok', /Rp 43,61 M/.test(kepala) && /Rp 62,65 M/.test(kepala), 'tidak disingkat');
    cek('sisa pun disingkat', /Rp 19,04 M/.test(kepala), 'tidak disingkat');
    cek('% sisa blok ditampilkan', /30,4%/.test(kepala), 'tidak ada');
    /* Penyingkatan tidak boleh membuang ketelitian — angka penuhnya tetap terjangkau. */
    cek('angka penuh disimpan di title', /title="Rp 43\.610\.000\.000"/.test(kepala), 'title angka penuh tidak ada');

    console.log('\n=== 8. BLOK LINTAS COST CENTER MENERANGKAN DIRINYA ===');

    cek('blok lintas menerangkan anggarannya berlaku lintas cost center',
        /tanpa cost center<\/b>, artinya plafonnya berlaku lintas cost center/.test(b[1]), 'keterangan tidak ada');
    cek('blok lintas menyatakan dirinya tidak ikut dijumlahkan',
        /tidak ikut dijumlahkan<\/b> ke total keseluruhan/.test(b[1]), 'tidak dinyatakan');
    /* Diperiksa per blok: keterangan yang sama tidak boleh menempel di blok cost center biasa,
       karena di sana pernyataannya justru terbalik. */
    cek('blok cost center biasa TIDAK memuat keterangan itu',
        ! /tidak ikut dijumlahkan/.test(b[0]), 'keterangan ikut menempel di blok biasa');
    cek('kaki halaman menyebut berapa blok yang dilewati',
        /sengaja melewati[\s\S]{0,40}<b>1<\/b>[\s\S]{0,40}blok lintas cost center/.test(html), 'tidak disebutkan');

    console.log('\n=== 9. TOTAL KESELURUHAN ===');

    const atas = html.split('<h2')[0];
    cek('kartu total menampilkan realisasi, budget, sisa & % sisa',
        /Rp 43,61 M/.test(atas) && /Rp 62,65 M/.test(atas) && /Rp 19,04 M/.test(atas) && /30,4%/.test(atas), 'ada yang tidak tampil');
    cek('menyebut cakupan blok & akunnya',
        /<b>1 blok<\/b>[\s\S]{0,20}<b>5 akun<\/b>/.test(atas), 'tidak disebutkan');

    console.log('\n=== 10. PEMOTONGAN BULAN DIBERI TAHU ===');

    cek('tanpa pemotongan, tidak ada peringatan', ! /Dipotong s\/d/.test(html), 'peringatan muncul padahal s/d Des');
    const hPotong = await render({ ...props, filters: { ...props.filters, sd_bulan: 8 } }, 'potong');
    cek('dipotong s/d Agu → peringatannya menyebut bulannya',
        /Dipotong s\/d <b>Agu 2047<\/b>/.test(hPotong), 'peringatan tidak menyebut bulan');
    /* Kalimat ini yang mencegah salah baca: anggaran yang dibandingkan bukan setahun penuh. */
    cek('peringatan menerangkan anggarannya ikut dipotong',
        /anggaran\s+bulan Jan s\/d Agu, bukan anggaran setahun penuh/.test(hPotong), 'tidak diterangkan');

    console.log('\n=== 11. TIDAK ADA DATA ===');

    const kosong = await render({ ...props, sections: [], total: { akun: 0, cost_center: 0, lintas: 0, real: 0, budget: 0, sisa: 0, pct_sisa: 0, absorpsi: null } }, 'kosong');
    cek('layar menerangkan keadaannya', /Tidak ada data/.test(kosong), 'tidak ada EmptyState');
    cek('menyebut tahun yang sedang dilihat', /tahun 2047/.test(kosong), 'tahun tidak disebut');
    cek('tabelnya tidak dirender', ! /<tbody/.test(kosong), 'tabel tetap dirender');

    console.log('\n=== 12. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const sesudah = (nav.match(/\{ label: 'Alokasi Budget', href: 'bs\.budget\.query\.allocation'[^\n]*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Report Budget tepat di bawah Alokasi Budget',
        sesudah === 'Report Budget', sesudah ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute yang benar', nav.includes("href: 'bs.budget.query.report'"));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
