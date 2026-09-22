// Reject BI Checking — tombol Memo Rekom Batal (per-baris & massal), di-render SUNGGUHAN via SSR.
//
// Permintaannya "sama persis dengan Reject Proses KPR". Yang dijaga di sini justru kesamaan
// itu, bukan sekadar ada-tidaknya tombol:
//
//  1. TOMBOL PER-BARIS menunjuk rute memo dengan id BARISNYA SENDIRI. Kalau id-nya terlepas
//     (mis. tertinggal memakai r.id milik baris lain, atau tanpa ids sama sekali), memonya
//     tetap terbit — hanya saja untuk orang yang salah, dan itu dokumen resmi.
//  2. TOMBOL MASSAL MATI selama belum ada yang dicentang. Hidup, ia membuka memo tanpa isi
//     dan operator menyangka datanya yang hilang.
//  3. KEDUANYA MEMAKAI SATU RUTE YANG SAMA — pola sub-modul sebelahnya. Dua rute terpisah
//     adalah cara paling mudah hasil massal dan hasil per-baris perlahan berbeda.
//  4. TOMBOL "CETAK" LAMA SUDAH TIDAK ADA DI DAFTAR INI (tugasnya digantikan), sementara
//     tombol Report tetap berdiri — permintaannya mengganti satu tombol, bukan dua.
//  5. Lembar A4 "Berita Acara" yang dulu dibuka tombol Cetak TIDAK dihapus: rutenya masih
//     terdaftar dan halaman detail masih memanggilnya. Kalau ikut terhapus, satu-satunya
//     cara mencetak berita acara lenyap tanpa ada yang meminta.
//  6. Label & bentuk tombolnya sama dengan yang di Reject Proses KPR.
//
// Jalankan:  node tests/Manual/uji-memo-rekom-batal-bi-checking-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HAL_BI = 'BankLoan/Database/RejectBiChecking';
const HAL_KPR = 'BankLoan/Database/RejectProcess';
const SRC_BI = path.join(ROOT, 'resources/js/Pages', HAL_BI + '.vue');
const SRC_KPR = path.join(ROOT, 'resources/js/Pages', HAL_KPR + '.vue');
const SRC_DETAIL = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RejectBiCheckingDetail.vue');
const SRC_MEMO = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RekomBatal.vue');
const RUTE = path.join(ROOT, 'routes/web.php');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){} };'
                  + ' export const useForm = (d) => ({ ...(d || {}), errors: {}, processing: false, reset(){}, post(){}, put(){} });'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href, 'data-inertia': '1' }, slots.default ? slots.default() : []) };"
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

async function render(halaman, props) {
    const hasil = await esbuild.build({
        entryPoints: [path.join(ROOT, 'resources/js/Pages', halaman + '.vue')],
        bundle: true, write: false, format: 'cjs', platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = () => {};
    // Parameternya IKUT DISERIALKAN — tanpa itu route('…', {ids: 5}) menjadi "[object Object]"
    // dan uji "tombol membawa id barisnya" akan lulus untuk id apa pun.
    app.config.globalProperties.route = (n, p) => '/' + n
        + (p === undefined ? '' : '?' + new URLSearchParams(p && typeof p === 'object' ? p : { _: String(p) }).toString());
    const html = await renderToString(app);

    return { html, teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/\s+/g, ' ') };
}

/** Elemen <a> yang teksnya memuat label — beserta atributnya. */
function anchor(html, label) {
    return [...html.matchAll(/<a\b([^>]*)>([\s\S]*?)<\/a>/g)]
        .filter((m) => m[2].replace(/<[^>]+>/g, ' ').includes(label));
}
/** Ada tombol <button> berlabel ini? Nonaktif? */
function tombol(html, label) {
    const semua = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
        .filter((m) => m[2].replace(/<[^>]+>/g, ' ').includes(label));

    return { jumlah: semua.length, mati: semua.some((m) => /(?:^|\s)disabled(?:=|\s|$)/.test(m[1])) };
}

const BARIS = [
    { id: 31, number: 'INIT/26/09/0031', customer: 'DENA MIGITA PUTRI HARSANA', tts: 'TA-19', unit: 'JRK3-003-XVII-11', bi_result: 'clear', bi_result_pasangan: null, reject_type: 'batal_sepihak', reason: 'mundur', rejected_at: '2026-09-15', rejected_by: 'Super Admin' },
    { id: 8, number: 'INIT/26/07/0008', customer: 'Arie Yogi', tts: 'TTS-202607-00023', unit: 'JRK3-004-XVIII-18', bi_result: 'kol2', bi_result_pasangan: null, reject_type: 'bi_checking', reason: 'KOL 2', rejected_at: '2026-07-03', rejected_by: 'Super Admin' },
];
const BARIS_KPR = [
    { id: 7, number: 'KPR/JRES/2608/00007', customer_id: 1, customer: 'ADAM ARDIANSYAH', bank: 'Bank Mandiri', unit: 'JRK3-004-XVII-23', stage: 'Submitted', reason: 'KOL 5', rejected_at: '2026-09-22' },
];

async function main() {
    const srcBi = fs.readFileSync(SRC_BI, 'utf8');
    const srcKpr = fs.readFileSync(SRC_KPR, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');

    const bi = await render(HAL_BI, { records: BARIS });
    const kpr = await render(HAL_KPR, { records: BARIS_KPR });

    console.log('\n=== 1. Tombol per-baris: label & tujuannya ===');

    const memo = anchor(bi.html, 'Memo Rekom Batal');
    cek('ada tombol Memo Rekom Batal di tiap baris', memo.length === BARIS.length, 'ditemukan ' + memo.length);
    cek('  menuju rute memo Reject BI Checking',
        memo.every((m) => m[1].includes('/kpr.database.reject-bi.memo-form')), memo[0]?.[1] ?? '-');
    // Tiap tombol membawa id BARISNYA SENDIRI, bukan id yang sama berulang.
    const idTerbawa = memo.map((m) => (m[1].match(/ids=(\d+)/) || [])[1]);
    cek('  masing-masing membawa id barisnya sendiri',
        JSON.stringify(idTerbawa) === JSON.stringify(BARIS.map((r) => String(r.id))), JSON.stringify(idTerbawa));
    cek('  dibuka di tab baru (rel noopener)',
        memo.every((m) => /target="_blank"/.test(m[1]) && /rel="noopener"/.test(m[1])));

    console.log('\n=== 2. Tombol lama & tetangganya ===');

    // Tugas tombol Cetak digantikan; yang tersisa di daftar hanyalah Report + Memo.
    cek('tombol "Cetak" sudah tidak ada di daftar ini', ! />\s*Cetak\s*</.test(bi.html) && ! bi.teks.includes(' Cetak '), bi.teks.slice(0, 200));
    // Komentar dibuang dulu: berkas itu MENYEBUT rute lama dalam catatan yang menjelaskan
    // kenapa ia tidak lagi dipakai, dan pencarian mentah akan tertipu oleh penjelasannya sendiri.
    const biTanpaKomentar = srcBi.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*$/gm, '');
    cek('  rute cetak lama tidak lagi dipanggil dari daftar ini', ! /reject-bi\.cetak/.test(biTanpaKomentar));
    cek('tombol Report tetap ada di tiap baris', anchor(bi.html, 'Report').length === BARIS.length);
    cek('  Report tetap menuju halaman detail',
        anchor(bi.html, 'Report').every((m) => m[1].includes('/kpr.database.reject-bi.detail')));

    console.log('\n=== 3. Lembar A4 "Berita Acara" tidak ikut dihapus ===');

    // Ia hanya berpindah pintu: tetap terjangkau dari halaman Report/detail.
    cek('rutenya masih terdaftar', /kpr\.database\.reject-bi\.cetak/.test(rute));
    cek('  halaman detail masih memanggilnya', /reject-bi\.cetak/.test(fs.readFileSync(SRC_DETAIL, 'utf8')));

    console.log('\n=== 4. Tombol massal ===');

    const massalBi = tombol(bi.html, 'Memo Rekom Batal (Massal)');
    cek('tombol Memo Rekom Batal (Massal) ada di kepala halaman', massalBi.jumlah === 1, 'ditemukan ' + massalBi.jumlah);
    // Belum ada centang pada render awal → harus mati.
    cek('  MATI selama belum ada baris tercentang', massalBi.mati);
    cek('  memakai rute yang SAMA dengan tombol per-baris',
        /route\('kpr\.database\.reject-bi\.memo-form'/.test(srcBi)
        && (srcBi.match(/kpr\.database\.reject-bi\.memo-form/g) || []).length === 2,
        'kemunculan: ' + (srcBi.match(/kpr\.database\.reject-bi\.memo-form/g) || []).length);
    cek('  dibuka di tab baru lewat window.open', /window\.open\(url, '_blank', 'noopener'\)/.test(srcBi));
    cek('tombol Download Excel tetap ada', tombol(bi.html, 'Download Excel').jumlah === 1);

    console.log('\n=== 5. Sama persis dengan Reject Proses KPR ===');

    const massalKpr = tombol(kpr.html, 'Memo Rekom Batal (Massal)');
    cek('sub-modul sebelahnya juga punya tombol massal yang mati saat kosong',
        massalKpr.jumlah === 1 && massalKpr.mati);
    // Label per-baris identik — kalau salah satu diubah, keduanya tak lagi "sama persis".
    cek('label tombol per-baris identik di kedua layar',
        anchor(kpr.html, 'Memo Rekom Batal').length === BARIS_KPR.length);
    // Pola pemanggilannya identik: satu rute, ids= untuk keduanya.
    cek('pola per-baris identik (ids: r.id)',
        /memo-form', \{ ids: r\.id \}/.test(srcBi) && /memo-form', \{ ids: r\.id \}/.test(srcKpr));
    cek('pola massal identik (ids: gabungan tercentang)',
        /ids: \[\.\.\.selected\.value\]\.join\(','\)/.test(srcBi) && /ids: \[\.\.\.selected\.value\]\.join\(','\)/.test(srcKpr));

    console.log('\n=== 6. Halaman memo dipakai berdua — tombol kembalinya ikut berpindah ===');

    const memoSrc = fs.readFileSync(SRC_MEMO, 'utf8');
    // Dipatok, operator dari BI Checking terlempar ke daftar yang salah & kehilangan filternya.
    cek('tombol kembali halaman memo TIDAK lagi dipatok ke Reject Proses KPR',
        ! /route\('kpr\.database\.reject-kpr'\)/.test(memoSrc));
    cek('  tujuannya datang dari server (prop back)',
        /back: \{ href: string; label: string \}/.test(memoSrc) && /props\.back\.href/.test(memoSrc));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
