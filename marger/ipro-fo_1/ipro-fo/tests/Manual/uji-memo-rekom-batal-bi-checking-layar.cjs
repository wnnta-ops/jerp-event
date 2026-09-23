// Reject BI Checking & Reject Proses KPR — tombol Memo Rekom Batal beserta KONFIRMASI DUA TAHAP.
//
// Rekom batal bukan sekadar mencetak kertas: ia memberi instruksi ke admin sales untuk
// membatalkan SPR, dan membuka tombol "Ajukan Pembatalan" di aplikasi Front Office. Karena itu
// yang dijaga di sini bukan "apakah tombolnya ada", melainkan hal-hal yang kalau salah akan
// membatalkan SPR tanpa seorang pun sempat menyetujuinya:
//
//  1. TIDAK ADA PINTU YANG MELEWATI KONFIRMASI. Tombol per-baris harus <button> pembuka modal,
//     BUKAN <a href>: sebuah tautan sudah membuka tabnya (dan mencatat penandanya) sebelum
//     pertanyaan pertama sempat muncul.
//  2. DUA TAHAP, dengan kalimat persis seperti yang diminta pemilik proses.
//  3. TAHAP KEDUA MENGIRIM <form> SUNGGUHAN ber-target _blank. Peramban memblokir tab yang
//     dibuka dari callback asinkron — dan memblokirnya DIAM-DIAM: operator menekan "Ya", tidak
//     terjadi apa-apa, tidak ada galat. Hanya tombol submit yang benar-benar ditekan yang aman.
//  4. "Tidak" HANYA MENUTUP modal — tidak berpindah halaman. Daftar reject menyimpan filter &
//     centang di state lokal; "kembali" yang berpindah halaman membuang pekerjaan operator.
//  5. KEDUA LAYAR MEMAKAI KOMPONEN & KALIMAT YANG SAMA. Disalin dua kali, peringatan di salah
//     satunya akan berbeda satu kalimat tanpa ada yang menyadarinya.
//  6. Lembar A4 "Berita Acara" yang dulu dibuka tombol Cetak TIDAK ikut terhapus.
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
const SRC_MODAL = path.join(ROOT, 'resources/js/Components/UI/KonfirmasiRekomBatal.vue');
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
    app.config.globalProperties.route = (n, p) => '/' + n
        + (p === undefined ? '' : '?' + new URLSearchParams(p && typeof p === 'object' ? p : { _: String(p) }).toString());
    // Komentar HTML ikut terender oleh SSR, dan komentar di layar ini MENYEBUT "<a>" saat
    // menjelaskan kenapa tombolnya bukan tautan. Dibiarkan, pemindai <a> akan menganggapnya
    // tag sungguhan lalu menyapu sampai </a> milik baris berikutnya — penjaga jadi salah baca.
    const html = (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');

    return { html, teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/\s+/g, ' ') };
}

const anchor = (html, label) => [...html.matchAll(/<a\b([^>]*)>([\s\S]*?)<\/a>/g)]
    .filter((m) => m[2].replace(/<[^>]+>/g, ' ').includes(label));
function tombol(html, label) {
    const semua = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
        .filter((m) => m[2].replace(/<[^>]+>/g, ' ').includes(label));

    return { jumlah: semua.length, mati: semua.some((m) => /(?:^|\s)disabled(?:=|\s|$)/.test(m[1])) };
}
/** Buang komentar supaya penjaga tidak cocok dengan penjelasannya sendiri. */
const bersih = (s) => s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*$/gm, '');

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
    const srcModal = fs.readFileSync(SRC_MODAL, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');
    const biTanpaKomentar = bersih(srcBi);

    const bi = await render(HAL_BI, { records: BARIS });
    const kpr = await render(HAL_KPR, { records: BARIS_KPR });

    console.log('\n=== 1. Tidak ada pintu yang melewati konfirmasi ===');

    const memo = tombol(bi.html, 'Memo Rekom Batal');
    // 2 tombol per-baris + 1 tombol massal (label massalnya memuat teks yang sama).
    cek('tombol Memo Rekom Batal ada di tiap baris', memo.jumlah === BARIS.length + 1, 'ditemukan ' + memo.jumlah);
    // Inilah penjaga terpentingnya: <a href> sudah membuka tab & mencatat penanda sebelum
    // pertanyaan pertama sempat tampil.
    cek('  BUKAN tautan — tidak ada <a> berlabel Memo Rekom Batal',
        anchor(bi.html, 'Memo Rekom Batal').length === 0);
    cek('  tiap tombol membawa id barisnya sendiri ke modal',
        /mintaRekomBatal\(String\(r\.id\)\)/.test(biTanpaKomentar));
    cek('  daftar ini tidak lagi memuat rute memo secara langsung',
        ! /reject-bi\.memo-form/.test(biTanpaKomentar), 'masih ada jalan pintas ke memo tanpa konfirmasi');
    cek('  maupun rute cetak lama', ! /reject-bi\.cetak/.test(biTanpaKomentar));
    cek('tombol Report tetap ada di tiap baris', anchor(bi.html, 'Report').length === BARIS.length);

    console.log('\n=== 2. Tombol massal ===');

    const massalBi = tombol(bi.html, 'Memo Rekom Batal (Massal)');
    cek('ada di kepala halaman', massalBi.jumlah === 1, 'ditemukan ' + massalBi.jumlah);
    cek('  MATI selama belum ada baris tercentang', massalBi.mati);
    cek('  membuka modal yang SAMA dengan tombol per-baris',
        /mintaRekomBatal\(\[\.\.\.selected\.value\]\.join\(','\)\)/.test(biTanpaKomentar));
    cek('tombol Download Excel tetap ada', tombol(bi.html, 'Download Excel').jumlah === 1);

    console.log('\n=== 3. Konfirmasi dua tahap — kalimatnya ===');

    const modalBersih = bersih(srcModal);
    cek('tahap 1 bertanya persis seperti yang diminta',
        modalBersih.includes('Apakah anda yakin akan membuat rekom batal?'));
    cek('tahap 2 menyebut instruksi ke admin sales',
        modalBersih.includes('Rekom batal ini akan memberikan instruksi ke admin sales untuk pembatalan SPR, apakah anda yakin akan hal tersebut?'));
    cek('  keduanya berlabel Ya / Tidak', /> ?Ya ?</.test(modalBersih) && /> ?Tidak ?</.test(modalBersih));
    cek('  satu modal yang isinya berganti, bukan dua modal bertumpuk',
        (modalBersih.match(/tahap === 1/g) || []).length >= 2 && ! /Teleport/.test(modalBersih));
    // "Tidak" hanya menutup — berpindah halaman akan membuang filter & centang operator.
    cek('  "Tidak" hanya menutup modal (tidak berpindah halaman)',
        /@click="tutup"/.test(modalBersih) && ! /router\.(visit|get)/.test(modalBersih));

    console.log('\n=== 4. Tahap kedua mengirim <form> sungguhan ===');

    // Dicocokkan per-atribut, bukan dengan satu pola [^>]* berurutan: atribut v-if-nya sendiri
    // memuat karakter ">" (tahap > 0), sehingga pola semacam itu berhenti di tengah jalan.
    // Diambil sebagai JENDELA teks sesudah "<form", bukan dengan mencari ">" penutup tag:
    // atribut v-if-nya memuat ">" sendiri, jadi pencarian penutup berhenti di tempat yang salah.
    const tagForm = modalBersih.slice(modalBersih.indexOf('<form'), modalBersih.indexOf('<form') + 400);
    cek('modalnya SEBUAH <form> POST ber-target _blank',
        /method="post"/.test(tagForm) && /target="_blank"/.test(tagForm) && /:action="props\.action"/.test(tagForm),
        'tab memo akan diblokir peramban — ' + tagForm.slice(0, 120));
    cek('  tombol "Ya" tahap 2 adalah type="submit"', /type="submit"/.test(modalBersih));
    cek('  TIDAK memakai window.open (diblokir sesudah modal)', ! /window\.open/.test(modalBersih));
    cek('  membawa token CSRF & daftar ids', /name="_token"/.test(modalBersih) && /name="ids"/.test(modalBersih));
    // document tidak ada saat SSR; membacanya di setup akan mematikan seluruh halaman.
    cek('  token dibaca saat onMounted, bukan saat setup', /onMounted\(\(\) => \{[\s\S]{0,200}?document\./.test(modalBersih));

    console.log('\n=== 5. Kedua layar memakai komponen & rute yang sepadan ===');

    const kprTanpaKomentar = bersih(srcKpr);
    cek('keduanya memasang KonfirmasiRekomBatal',
        /<KonfirmasiRekomBatal/.test(biTanpaKomentar) && /<KonfirmasiRekomBatal/.test(kprTanpaKomentar));
    cek('  masing-masing menunjuk rute rekom-batal sub-modulnya',
        /reject-bi\.rekom-batal/.test(biTanpaKomentar) && /reject-kpr\.rekom-batal/.test(kprTanpaKomentar));
    cek('  pola tombol per-baris identik',
        /mintaRekomBatal\(String\(r\.id\)\)/.test(kprTanpaKomentar));
    cek('  pola tombol massal identik',
        /mintaRekomBatal\(\[\.\.\.selected\.value\]\.join\(','\)\)/.test(kprTanpaKomentar));
    const massalKpr = tombol(kpr.html, 'Memo Rekom Batal (Massal)');
    cek('  sub-modul sebelahnya juga mati saat belum ada centang', massalKpr.jumlah === 1 && massalKpr.mati);
    cek('  dan juga bukan tautan', anchor(kpr.html, 'Memo Rekom Batal').length === 0);

    console.log('\n=== 6. Rute: POST mencatat, GET memo tetap bebas efek samping ===');

    cek('rute POST rekom-batal terdaftar untuk kedua sub-modul',
        /Route::post\('\/kpr-database\/reject-bi-checking-rekom-batal'/.test(rute)
        && /Route::post\('\/kpr-database\/reject-proses-kpr-rekom-batal'/.test(rute));
    cek('  rute GET memo tetap GET',
        /Route::get\('\/kpr-database\/reject-bi-checking-memo-batal'/.test(rute)
        && /Route::get\('\/kpr-database\/reject-proses-kpr-memo-batal'/.test(rute));

    console.log('\n=== 7. Lembar A4 "Berita Acara" tidak ikut dihapus ===');

    cek('rutenya masih terdaftar', /kpr\.database\.reject-bi\.cetak/.test(rute));
    cek('  halaman detail masih memanggilnya', /reject-bi\.cetak/.test(fs.readFileSync(SRC_DETAIL, 'utf8')));

    console.log('\n=== 8. Halaman memo dipakai dua sub-modul ===');

    const memoSrc = bersih(fs.readFileSync(SRC_MEMO, 'utf8'));
    cek('tombol kembalinya TIDAK dipatok ke Reject Proses KPR',
        ! /route\('kpr\.database\.reject-kpr'\)/.test(memoSrc));
    cek('  tujuannya datang dari server (prop back)', /props\.back\.href/.test(memoSrc));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
