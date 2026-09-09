// Layar Outstanding Kasbon — Summary (seluruh karyawan) & Detail (satu karyawan).
//
// KENAPA PERLU UJI:
//  · Dua tampilan berbagi PROP YANG SAMA. Salah satu bisa diam-diam menampilkan data yang lain
//    (mis. detail ikut memuat tabel summary), dan keduanya tetap terlihat seperti tabel yang wajar.
//  · Kelima kolomnya mudah tertukar urutannya; angka yang bergeser satu kolom tidak menimbulkan
//    galat apa pun, hanya laporan yang salah.
//  · Nilai saringan tanggal harus dikirim balik dari server. Kalau tidak, kotak tanggalnya
//    tampak kosong sesudah "Tampilkan" ditekan dan orang mengira saringannya tidak tersimpan.
//
// Jalankan:  node tests/Manual/uji-outstanding-kasbon-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/OutstandingKasbon.vue');
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

async function render(props, tag) {
    const out = path.join(ROOT, 'outstanding-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [HALAMAN], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    globalThis.route = (n, p) => '/' + n + (p != null ? '/' + (p?.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

function judulKolom(html, i = 0) {
    const theads = [...html.matchAll(/<thead[^>]*>([\s\S]*?)<\/thead>/g)].map((m) => m[1]);
    const trPertama = ((theads[i] ?? '').match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];

    return [...trPertama.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
        .map((m) => m[1].replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim());
}
/** Sel <td> sebuah baris <tr>. */
function sel(baris) {
    return [...baris.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim());
}

const DASAR = {
    rows: [
        { holder: 'Budi Santoso', saldo_awal: 0, penambahan: 500000, realisasi: 0, outstanding: 500000 },
        { holder: 'Eko Prasetyo', saldo_awal: 100000, penambahan: 800000, realisasi: 600000, outstanding: 300000 },
    ],
    detail: [],
    holders: ['Budi Santoso', 'Eko Prasetyo'],
    totals: { saldo_awal: 100000, penambahan: 1300000, realisasi: 600000, outstanding: 800000, karyawan: 2 },
    filters: { from: '2033-04-01', to: '2033-04-30', mode: 'summary', holder: '' },
    account: { code: '10107030303', name: 'UANG MUKA OPERASIONAL' },
};

(async () => {
    console.log('\n=== 1. SARINGAN PERIODE ===');

    const summary = await render(DASAR, 'summary');

    cek('ada saringan Tanggal Periode Awal', summary.includes('Tanggal Periode Awal'));
    cek('ada saringan Tanggal Periode Akhir', summary.includes('Tanggal Periode Akhir'));
    cek('nilai saringan dikirim balik dari server',
        /value="2033-04-01"/.test(summary) && /value="2033-04-30"/.test(summary));
    cek('dijelaskan bahwa Saldo Awal mengikuti tanggal periode awal',
        /Saldo Awal Kasbon<\/b> mengikuti tanggal periode awal/.test(summary));
    cek('akun sumbernya disebutkan', summary.includes('10107030303') && summary.includes('UANG MUKA OPERASIONAL'));

    console.log('\n=== 2. SUMMARY — SELURUH KARYAWAN ===');

    const judul = judulKolom(summary, 0);
    cek('kolomnya: Nama Karyawan, Saldo Awal, Penambahan, Realisasi, Outstanding',
        JSON.stringify(judul) === JSON.stringify(['Nama Karyawan', 'Saldo Awal Kasbon', 'Penambahan Kasbon', 'Realisasi Kasbon', 'Outstanding Kasbon']),
        JSON.stringify(judul));

    const tbody = (summary.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/) || ['', ''])[1];
    const barisSummary = [...tbody.matchAll(/<tr[^>]*>[\s\S]*?<\/tr>/g)].map((m) => m[0]);
    cek('kedua karyawan ter-render', barisSummary.length === 2, barisSummary.length + ' baris');

    const eko = barisSummary.find((b) => b.includes('Eko Prasetyo'));
    const selEko = sel(eko);
    cek('nilai Saldo Awal di kolomnya sendiri', selEko[1] === 'Rp 100.000', selEko[1]);
    cek('nilai Penambahan di kolomnya sendiri', selEko[2] === 'Rp 800.000', selEko[2]);
    cek('nilai Realisasi di kolomnya sendiri', selEko[3] === 'Rp 600.000', selEko[3]);
    cek('nilai Outstanding di kolomnya sendiri', selEko[4] === 'Rp 300.000', selEko[4]);

    cek('baris total menyebut jumlah karyawannya', summary.includes('Total (2 karyawan)'));
    cek('kartu ringkasan menampilkan keempat angkanya',
        summary.includes('Saldo Awal Kasbon') && summary.includes('Penambahan Kasbon')
        && summary.includes('Realisasi Kasbon') && summary.includes('Outstanding Kasbon'));
    cek('ada tombol Summary & Detail', summary.includes('Summary') && summary.includes('Detail'));
    cek('pemilih karyawan TIDAK tampil pada Summary', ! />Karyawan<\/label>/.test(summary));
    cek('Summary tidak menampilkan kolom Saldo Berjalan', ! summary.includes('Saldo Berjalan'));

    console.log('\n=== 3. DETAIL — SATU KARYAWAN ===');

    const detail = await render({
        ...DASAR,
        detail: [
            { date: '2033-04-10', journal_id: 21, journal_number: 'JKB/3304/0001', reference: 'BON/3304/0001', description: 'Alokasi kasbon — Beli ATK', debit: 500000, credit: 0, saldo: 600000 },
            { date: '2033-04-20', journal_id: 22, journal_number: 'JKB/3304/0002', reference: 'BON/3304/0001', description: 'Realisasi panjar', debit: 0, credit: 300000, saldo: 300000 },
        ],
        filters: { from: '2033-04-01', to: '2033-04-30', mode: 'detail', holder: 'Eko Prasetyo' },
    }, 'detail');

    cek('judulnya menyebut karyawan yang dipilih', detail.includes('Detail — Eko Prasetyo'));
    cek('pemilih karyawan tampil pada Detail', />Karyawan<\/label>/.test(detail));
    const judulDetail = judulKolom(detail, 0);
    cek('kolomnya: Tanggal, No. Jurnal, Keterangan, Penambahan, Realisasi, Saldo Berjalan',
        JSON.stringify(judulDetail) === JSON.stringify(['Tanggal', 'No. Jurnal', 'Keterangan', 'Penambahan', 'Realisasi', 'Saldo Berjalan']),
        JSON.stringify(judulDetail));
    cek('ringkasan rumusnya ditampilkan di kepala tabel',
        /Saldo Awal Rp 100\.000 \+ Penambahan Rp 800\.000/.test(detail.replace(/\s+/g, ' ')));
    cek('baris Saldo Awal ditampilkan lebih dulu', /Saldo Awal per/.test(detail));
    cek('nomor jurnal tiap baris bertaut ke jurnalnya',
        /<a href="\/bs\.gl\.journal\.show\/21"/.test(detail) && /<a href="\/bs\.gl\.journal\.show\/22"/.test(detail));
    cek('referensi bonnya ikut ditampilkan', detail.includes('BON/3304/0001'));
    cek('saldo berjalan tiap baris ditampilkan', detail.includes('Rp 600.000') && detail.includes('Rp 300.000'));
    cek('sisi yang kosong ditulis "—", bukan Rp 0',
        (detail.match(/—/g) || []).length > 0 && ! /Rp 0<\/td>/.test(detail));
    cek('Detail TIDAK ikut menampilkan tabel Summary', ! detail.includes('Total (2 karyawan)'));

    const kosong = await render({
        ...DASAR, detail: [],
        filters: { from: '2033-04-01', to: '2033-04-30', mode: 'detail', holder: '' },
    }, 'detail-kosong');
    cek('tanpa karyawan terpilih, layar meminta memilih dulu', kosong.includes('Pilih karyawan terlebih dahulu'));

    console.log('\n=== 4. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const master = (nav.match(/\{ label: 'Realisasi Bon Sementara', href: 'bs\.advance\.index' \},\s*\n\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Outstanding Kasbon berada TEPAT di bawah Realisasi Bon Sementara',
        master === 'Outstanding Kasbon', master ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute Outstanding Kasbon', nav.includes("href: 'bs.cashbook.outstanding-kasbon'"));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
