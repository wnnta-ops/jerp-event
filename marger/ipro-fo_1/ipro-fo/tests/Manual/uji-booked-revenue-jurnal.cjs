// Render BookedRevenue/Index.vue yang SEBENARNYA dan periksa kolom No. Jurnal + tombol Excel.
//
// Satu Booked Revenue bisa melahirkan DUA jurnal bernomor berbeda: jurnal Booking (saat BR
// dibuat) dan jurnal Pengakuan (saat pendapatannya diakui). Menampilkan salah satunya saja
// membuat yang lain seolah tidak pernah ada — dan pertanyaan "jurnal mana yang terbit dari
// BR ini" justru terjawab keliru.
//
// Jalankan:  node tests/Manual/uji-booked-revenue-jurnal.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/BookedRevenue/Index.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
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

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const br = (n, journals) => ({
    id: n, br_number: 'BR/2607/' + String(n).padStart(4, '0'), booking_date: '2026-07-19',
    customer: { code: 'CUST-2026-00017', name: 'Doni Firmansyah' },
    spr: 'SPR-2026-00017', unit: 'JRK3-004-XVI-15', description: null,
    dpp: 455855856, ppn: 50144144, total: 506000000, journals,
});

const BARIS = [
    br(4, [{ id: 91, number: 'JM/2607/0021', jenis: 'Booking' }]),
    br(3, [
        { id: 92, number: 'JM/2607/0022', jenis: 'Booking' },
        { id: 93, number: 'JM/2612/0140', jenis: 'Pengakuan' },
    ]),
    br(2, []),   // belum berjurnal
];

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const app = createSSRApp({
        render: () => h(modul.exports.default, {
            rows: { data: BARIS, links: [], total: 3 },
            filters: {},
            summary: { count: 3, total: 1367567568 },
        }),
    });
    app.config.globalProperties.route = (nama, p) => '/' + nama + (p ? '/' + JSON.stringify(p) : '');
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    const tabel = (html.match(/<table[\s\S]*?<\/table>/) || [''])[0];

    console.log('\n=== 1. Kolom No. Jurnal ===');

    const th = [...tabel.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1].trim());
    cek('judul kolomnya ada', th.includes('No. Jurnal'), th.join('|'));
    // Ditaruh sesudah Unit dan sebelum Keterangan, mengikuti urutan yang diminta.
    cek('berdiri antara Unit dan Keterangan',
        th.indexOf('No. Jurnal') === th.indexOf('Unit') + 1
        && th.indexOf('Keterangan') === th.indexOf('No. Jurnal') + 1, th.join('|'));

    console.log('\n=== 2. Keutuhan tabel — judul dan sel sama banyak ===');

    // Menambah kolom di salah satu tempat saja menggeser seluruh kolom di kanannya; tabelnya
    // tetap tergambar dan angkanya duduk di bawah judul yang bukan miliknya.
    const barisIsi = (tabel.split('<tbody')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];
    cek('tiga baris data', barisIsi.length === 3, String(barisIsi.length));
    const sel = barisIsi.map((t) => (t.match(/<td/g) || []).length);
    cek('tiap baris sebanyak judulnya', sel.every((n) => n === th.length),
        th.length + ' judul vs sel ' + sel.join(','));

    console.log('\n=== 3. Nomor jurnalnya benar-benar tampil ===');

    cek('nomor jurnal booking tampil', teks.includes('JM/2607/0021'), 'tidak ditemukan');
    // Inilah yang hilang kalau hanya satu jurnal yang ditampilkan.
    cek('BR dengan dua jurnal menampilkan KEDUANYA',
        teks.includes('JM/2607/0022') && teks.includes('JM/2612/0140'), 'tidak lengkap');
    cek('jenisnya disebut, jadi dua nomor itu bisa dibedakan',
        teks.includes('Booking') && teks.includes('Pengakuan'), 'tidak ditemukan');
    cek('BR yang belum berjurnal ditandai em dash, bukan dibiarkan kosong',
        barisIsi[2].includes('—'), 'tidak ditemukan');

    console.log('\n=== 4. Nomornya menaut ke jurnalnya ===');

    // Pertanyaan "jurnal mana yang terbit dari BR ini" hampir selalu berlanjut jadi
    // "tunjukkan isinya".
    cek('nomor jurnal berupa tautan ke detail jurnal',
        html.includes('bs.gl.journal.show'), 'tidak ditemukan');
    const tautan = [...html.matchAll(/href="[^"]*bs\.gl\.journal\.show[^"]*"/g)].length;
    cek('tiga tautan untuk tiga jurnal', tautan === 3, String(tautan));

    console.log('\n=== 5. Tombol Excel ===');

    cek('tombol Excel ada', teks.includes('Excel'), 'tidak ditemukan');
    cek('memanggil rute ekspor Booked Revenue',
        fs.readFileSync(BERKAS, 'utf8').includes("route('bs.booked-revenue.export'"), 'tidak ditemukan');
    // Berkas yang mengabaikan pencarian akan berbeda isi dari yang sedang dilihat operator.
    cek('ekspornya membawa kata pencarian yang sedang berlaku',
        fs.readFileSync(BERKAS, 'utf8').includes('search: f.search'), 'tidak ditemukan');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
