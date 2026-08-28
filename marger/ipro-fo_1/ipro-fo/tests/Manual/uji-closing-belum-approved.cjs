// Render TutupBuku.vue (Closing Fiskal) yang SEBENARNYA dan periksa penghalang closing-nya.
//
// Aturannya: periode tidak boleh dikunci selama masih ada jurnal yang belum Approved. Server
// yang menegakkannya (PeriodClosingService::closePeriod); layar ini mendahuluinya supaya
// penghalangnya terbaca SEBELUM tombolnya ditekan — penolakan yang baru muncul sesudah diklik
// memaksa operator menebak bulan mana yang bermasalah dan berapa banyak sisanya.
//
// Jalankan:  node tests/Manual/uji-closing-belum-approved.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/TutupBuku.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
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

const bulan = (m, extra) => ({
    month: m, income: 0, expense: 0, profit: 0, status: 'open', closed_at: null,
    baru: 0, final: 0, ...extra,
});

// Agustus (8) meniru tangkapan layar General Function: 31 Baru, 22 Final.
const BULAN = [
    bulan(1, { status: 'closed' }),
    bulan(2), bulan(3), bulan(4), bulan(5),
    bulan(6, { baru: 2, final: 1 }),
    bulan(7, { final: 57 }),
    bulan(8, { baru: 31, final: 22 }),
    bulan(9), bulan(10), bulan(11), bulan(12),
];

const PROPS = () => ({
    year: 2026, months: BULAN,
    ytd: { income: 1305855856, expense: 157393352, profit: 1148462504 },
    yearClosed: false, closingJournalId: null, allClosed: false,
});

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const Comp = modul.exports.default;

    // closeM default = bulan berjalan; supaya hasilnya tidak bergantung pada tanggal mesin,
    // yang diperiksa BARIS per bulan (selalu ke-12-duanya) dan keadaan bilah atas diperiksa
    // lewat properti komponennya, bukan lewat tanggal hari ini.
    const app = createSSRApp({ render: () => h(Comp, PROPS()) });
    app.config.globalProperties.route = () => '#';
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    console.log('\n=== 1. Kolom "Belum Approved" ada di tabel bulanan ===');

    cek('judul kolomnya ada', teks.includes('Belum Approved'), 'tidak ditemukan');
    // Inilah yang diminta: sebutkan JUMLAHNYA, bukan sekadar "ada jurnal belum approved".
    cek('Agustus menyebut 31 Jurnal Baru dan 22 Jurnal Final',
        teks.includes('31 Jurnal Baru dan 22 Jurnal Final'), 'tidak ditemukan');
    cek('Juli yang hanya punya Final tidak menyebut "0 Jurnal Baru"',
        teks.includes('57 Jurnal Final') && ! teks.includes('0 Jurnal Baru'), 'menyebut nol');
    cek('Juni menyebut keduanya', teks.includes('2 Jurnal Baru dan 1 Jurnal Final'), 'tidak ditemukan');
    cek('bulan yang bersih ditandai em dash, bukan angka nol',
        (teks.match(/—/g) || []).length >= 5, String((teks.match(/—/g) || []).length));

    console.log('\n=== 2. Tombol Tutup per baris mati saat masih ada yang belum Approved ===');

    // Tiap baris punya tombol Tutup; yang bulannya masih menyimpan jurnal belum-approved
    // harus mati, dan yang bersih harus tetap hidup.
    const baris = (html.split('<tbody')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];
    cek('dua belas baris bulan', baris.length === 12, String(baris.length));

    const tombolTutup = (tr) => (tr.match(/<button[^>]*>[\s\S]*?<\/button>/g) || [])[0] ?? '';
    // Atribut class memuat utilitas 'disabled:cursor-not-allowed', jadi class-nya dibuang dulu —
    // kalau tidak, setiap tombol akan terbaca mati dan pemeriksaan ini tidak menguji apa pun.
    const mati = (tr) => tombolTutup(tr).replace(/ class="[^"]*"/g, '').includes(' disabled');
    cek('Agustus (31 Baru, 22 Final) tombol Tutup-nya mati', mati(baris[7]));
    cek('Juli (57 Final) tombol Tutup-nya mati', mati(baris[6]));
    cek('Juni (2 Baru, 1 Final) tombol Tutup-nya mati', mati(baris[5]));
    // Yang bersih TIDAK boleh ikut terkunci — penjaga yang mematikan semuanya sama saja
    // dengan mematikan fiturnya.
    cek('Mei yang bersih tombol Tutup-nya tetap hidup', ! mati(baris[4]));
    cek('September yang bersih tombol Tutup-nya tetap hidup', ! mati(baris[8]));
    cek('Januari yang sudah terkunci tetap mati', mati(baris[0]));

    console.log('\n=== 3. Alasannya dikatakan, bukan hanya tombol mati ===');

    // Tombol mati tanpa keterangan membuat orang mengira wewenangnya yang kurang.
    cek('judul bantuan menyebutkan syaratnya',
        html.includes('harus menjadi Jurnal Approved'), 'tidak ditemukan');
    cek('disebut bahwa closing mengunci jurnalnya dari pengeditan',
        teks.includes('mengunci jurnalnya dari pengeditan') || teks.includes('menolak jurnal baru'),
        'tidak ditemukan');

    console.log('\n=== 4. Bulan bersih tidak ikut dihalangi ===');

    const bersih = [bulan(1), bulan(2), bulan(3), bulan(4), bulan(5), bulan(6),
        bulan(7), bulan(8), bulan(9), bulan(10), bulan(11), bulan(12)];
    const app2 = createSSRApp({ render: () => h(Comp, { ...PROPS(), months: bersih }) });
    app2.config.globalProperties.route = () => '#';
    const html2 = await renderToString(app2);
    const teks2 = html2.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    const baris2 = (html2.split('<tbody')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];

    cek('tidak ada tombol Tutup yang mati saat semuanya bersih',
        baris2.every((tr) => ! mati(tr)), 'masih ada yang mati');
    cek('tidak ada peringatan yang muncul saat semuanya bersih',
        ! teks2.includes('belum bisa ditutup'), 'peringatan muncul tanpa sebab');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
