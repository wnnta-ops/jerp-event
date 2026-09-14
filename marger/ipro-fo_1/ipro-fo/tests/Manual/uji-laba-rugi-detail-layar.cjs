// Layar Laporan Laba Rugi — mode Ringkas | Detail.
//
// KENAPA PERLU UJI:
//  · Rantai v-if/v-else-if di halaman ini tidak dijaga tipe apa pun (kind bertipe string bebas).
//    Sebuah jenis baris yang belum punya cabang akan HILANG dari laporan tanpa error, tanpa
//    baris kosong, tanpa apa pun — angka subtotalnya tetap benar, jadi kerusakannya tidak
//    mungkin terlihat. Itulah kerusakan yang paling mahal, dan uji ini yang menjaganya.
//  · Mode wajib ikut ke tombol Excel. Kalau tidak, orang melihat layar detail lalu mengunduh
//    berkas ringkas — dan tidak ada satu pun tanda bahwa keduanya berbeda.
//  · Rincian harus terbedakan dari induknya. Bila keduanya sama jorok & sama warna, laporan
//    terbaca sebagai daftar datar yang angkanya seolah dobel.
//
// Jalankan:  node tests/Manual/uji-laba-rugi-detail-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/IncomeStatement.vue');

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

const R = (kind, label, amount, code = null) => ({ kind, code, label, amount });
const barisRingkas = [
    R('header', 'BEBAN UMUM & ADMINISTRASI (BUA)', null),
    R('line', 'BEBAN KARYAWAN', 42000000, '50301'),
    R('subtotal', 'JUMLAH BEBAN UMUM & ADMINISTRASI', 42000000),
    R('emphasis', 'EBITDA', -42000000),
    R('total', 'LABA RUGI NETTO', -42000000),
];
const barisDetail = [
    R('header', 'BEBAN UMUM & ADMINISTRASI (BUA)', null),
    R('detail', 'BIAYA GAJI, UPAH DAN TUNJANGAN KARYAWAN', 30000000, '50301010101'),
    R('detail', 'BIAYA TUNJANGAN - BPJS KESEHATAN', 12000000, '50301010102'),
    R('line', 'BEBAN KARYAWAN', 42000000, '50301'),
    R('subtotal', 'JUMLAH BEBAN UMUM & ADMINISTRASI', 42000000),
    R('emphasis', 'EBITDA', -42000000),
    R('total', 'LABA RUGI NETTO', -42000000),
];

const dasar = {
    company: 'PT Mitra Usaha Properti',
    as_of: '31 Desember 2049',
    periode: '01 Januari 2049 s/d 31 Desember 2049',
    date_from: '2049-01-01',
    date_to: '2049-12-31',
    net_profit: -42000000,
};
const propsRingkas = { ...dasar, rows: barisRingkas, view: 'summary', detail: { tampil: 0, total: 70 } };
const propsDetail = { ...dasar, rows: barisDetail, view: 'detail', detail: { tampil: 2, total: 70 } };

let Komponen = null;
async function render(p, tag) {
    const out = path.join(ROOT, 'lrd-' + tag + '.bundle.tmp.cjs');
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

/**
 * Label baris menurut kedalaman jorokannya — pl-6 = baris Ledger, pl-10 = rincian.
 *
 * Penutup </span> dicari dengan menghitung span bersarang, bukan dengan pencocokan malas:
 * label rincian membungkus kode akun di dalam span tersendiri, jadi pencocokan malas berhenti
 * di penutup span BAGIAN DALAM dan namanya ikut terpotong.
 */
function label(html, pl) {
    const out = [];
    const buka = new RegExp('<span class="' + pl + '[^"]*"[^>]*>', 'g');
    let m;
    while ((m = buka.exec(html)) !== null) {
        let i = m.index + m[0].length;
        let dalam = 1;
        const mulai = i;
        while (dalam > 0 && i < html.length) {
            const b = html.indexOf('<span', i);
            const t = html.indexOf('</span>', i);
            if (t === -1) break;
            if (b !== -1 && b < t) { dalam++; i = b + 5; } else { dalam--; i = t + 7; }
        }
        out.push(html.slice(mulai, i - 7).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim());
    }

    return out;
}

(async () => {
    const hR = await render(propsRingkas, 'ringkas');
    const hD = await render(propsDetail, 'detail');

    console.log('=== 1. MODE RINGKAS TIDAK BERUBAH ===');

    cek('baris Ledger tetap dirender', label(hR, 'pl-6').includes('BEBAN KARYAWAN'), JSON.stringify(label(hR, 'pl-6')));
    cek('tidak ada baris rincian', label(hR, 'pl-10').length === 0, JSON.stringify(label(hR, 'pl-10')));
    cek('subtotal, EBITDA & laba netto tetap tampil',
        hR.includes('JUMLAH BEBAN UMUM &amp; ADMINISTRASI') && hR.includes('EBITDA') && hR.includes('LABA RUGI NETTO'));
    cek('kartu tetap selebar semula', /class="card mx-auto w-full p-5 sm:p-7 max-w-3xl"/.test(hR), 'lebar kartu berubah');
    cek('tidak ada keterangan jumlah akun', ! /akun tingkat 6/.test(hR), 'keterangan muncul di mode ringkas');

    console.log('\n=== 2. MODE DETAIL MERENDER BARIS RINCIAN ===');

    /* Ini pemeriksaan terpenting di berkas ini. Tanpa cabang render untuk kind 'detail', kedua
       baris ini hilang tanpa error dan laporan tampak persis seperti mode ringkas. */
    const rincian = label(hD, 'pl-10');
    cek('kedua baris rincian benar-benar dirender', rincian.length === 2, JSON.stringify(rincian));
    cek('rincian memuat kode akun DAN nama akunnya',
        rincian[0] === '50301010101 BIAYA GAJI, UPAH DAN TUNJANGAN KARYAWAN', JSON.stringify(rincian[0]));
    cek('nilainya ikut dirender', hD.includes('30.000.000,00') && hD.includes('12.000.000,00'), 'nilai rincian tidak ada');
    cek('kode akun ditulis monospace agar sejajar terbaca',
        /<span class="font-mono text-\[10px\][^"]*">50301010101<\/span>/.test(hD), 'kode tidak monospace');

    console.log('\n=== 3. RINCIAN TERBEDAKAN DARI INDUKNYA ===');

    /* Tiga penanda sekaligus: lebih menjorok, lebih kecil, lebih redup. Bila salah satunya
       hilang, induk & anak terbaca sebagai satu daftar datar yang angkanya seolah dobel. */
    cek('rincian lebih menjorok daripada baris Ledger (pl-10 vs pl-6)',
        label(hD, 'pl-10').length === 2 && label(hD, 'pl-6').includes('BEBAN KARYAWAN'),
        JSON.stringify([label(hD, 'pl-10').length, label(hD, 'pl-6')]));
    cek('rincian bertulisan lebih kecil daripada baris Ledger',
        /<div class="flex justify-between gap-4 py-0\.5 text-xs">/.test(hD), 'ukuran teks rincian tidak dibedakan');
    cek('rincian berwarna lebih redup daripada baris Ledger',
        /<span class="pl-10 text-neutral-500/.test(hD), 'warna rincian tidak dibedakan');
    cek('baris Ledger muncul SESUDAH rinciannya, sebagai jumlahnya',
        hD.indexOf('50301010102') < hD.indexOf('>BEBAN KARYAWAN<'), 'urutannya terbalik');

    console.log('\n=== 4. PENANGKAP JENIS BARIS TAK DIKENAL ===');

    /* Kalau suatu hari ada kind baru yang lupa diberi cabang, ia harus TETAP tampil — bukan
       hilang diam-diam. Diuji dengan kind yang memang tidak ada cabangnya. */
    const hAsing = await render({ ...propsDetail, rows: [...barisDetail, R('kind-yang-belum-ada', 'BARIS ASING', 777)] }, 'asing');
    cek('baris berjenis asing tetap tampil, tidak hilang diam-diam',
        hAsing.includes('BARIS ASING'), 'baris hilang tanpa jejak — v-else penutup tidak ada');
    cek('nilainya pun ikut tampil', hAsing.includes('777,00'), 'nilai baris asing tidak dirender');

    console.log('\n=== 5. TOMBOL RINCIAN ===');

    cek('kedua pilihan tersedia', hD.includes('>Ringkas<') && hD.includes('>Detail<'));
    const aktifD = (hD.match(/<button type="button"[^>]*class="([^"]*)"[^>]*>Detail<\/button>/) || [])[1] || '';
    const aktifR = (hR.match(/<button type="button"[^>]*class="([^"]*)"[^>]*>Ringkas<\/button>/) || [])[1] || '';
    cek('pilihan yang sedang aktif ditandai — Detail', /bg-primary-600/.test(aktifD), aktifD);
    cek('pilihan yang sedang aktif ditandai — Ringkas', /bg-primary-600/.test(aktifR), aktifR);
    const diamD = (hD.match(/<button type="button"[^>]*class="([^"]*)"[^>]*>Ringkas<\/button>/) || [])[1] || '';
    cek('pilihan yang tidak aktif TIDAK ikut ditandai', ! /bg-primary-600/.test(diamD), diamD);

    console.log('\n=== 6. MODE IKUT KE DISPLAY & EXCEL ===');

    /* Diperiksa di SUMBER: satu fungsi params() dipakai bersama, supaya tidak mungkin yang satu
       membawa mode dan yang lain tidak. Layar detail + berkas ringkas adalah kesalahan yang
       tidak meninggalkan tanda apa pun pada berkasnya. */
    const src = fs.readFileSync(HALAMAN, 'utf8');
    cek('ada satu fungsi penyusun parameter', /function params\([^)]*\)\s*\{\s*return \{[^}]*view: v[^}]*\}/.test(src),
        'params() tidak menyusun view');
    for (const f of ['display', 'setView', 'excel']) {
        const badan = (src.match(new RegExp('function ' + f + '\\([^)]*\\) \\{[\\s\\S]*?\\n\\}|function ' + f + '\\([^)]*\\) \\{[^\\n]*\\}')) || [''])[0];
        cek(f + '() memakai params(), bukan menyusun parameternya sendiri',
            badan.includes('params('), badan.slice(0, 120));
    }
    cek('mode dibaca dari prop lewat computed, bukan disalin ke ref',
        /const mode = computed\(\(\) => \(props\.view === 'detail'/.test(src), 'mode bukan computed — akan basi setelah ganti mode');

    console.log('\n=== 7. KETERANGAN JUMLAH AKUN ===');

    /* Tanpa kalimat ini orang menghitung sendiri, menemukan lebih sedikit daripada yang ia kira,
       lalu menyimpulkan laporannya bocor. */
    cek('menyebut berapa akun tampil dari berapa seluruhnya',
        /<b>2<\/b> dari <b>70<\/b> akun tingkat 6/.test(hD), 'jumlahnya tidak disebutkan');
    cek('menerangkan akun tanpa mutasi memang tidak ditampilkan',
        /tidak bermutasi<\/b> pada periode ini tidak ditampilkan/.test(hD), 'tidak diterangkan');
    cek('menerangkan baris Ledger adalah jumlah rincian di atasnya',
        /baris Ledger adalah jumlah rincian tepat di atasnya/.test(hD), 'tidak diterangkan');

    console.log('\n=== 8. KARTU DILEBARKAN DI MODE DETAIL ===');

    cek('kartu mode detail lebih lebar', /class="card mx-auto w-full p-5 sm:p-7 max-w-4xl"/.test(hD), 'lebar kartu tidak berubah');

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
