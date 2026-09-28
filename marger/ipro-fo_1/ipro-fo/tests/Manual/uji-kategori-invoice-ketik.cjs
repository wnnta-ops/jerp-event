// Registrasi Invoice Hutang — Kategori Invoice bisa DIKETIK, bukan lagi digulir.
//
// ── Mengapa ini penting ──
// Kategorinya puluhan dan namanya berawalan mirip ("BIAYA AIR KANTOR", "BIAYA INTERNET",
// "BIAYA LISTRIK KANTOR", …). Mencarinya dengan menggulir berarti membaca satu per satu, dan
// salah pilih kategori berarti invoice-nya mendarat di akun yang salah — tanpa satu pun galat.
//
// Yang dijaga:
//  1. Layar wizard-nya DIRENDER SUNGGUHAN lewat SSR dan memakai SearchSelect, bukan <select>
//     polos. Peringatan Vue DITANGKAP, bukan dibungkam.
//  2. PENGETIKANNYA BENAR-BENAR MENYARING. Penyaring SearchSelect mencocokkan terhadap LABEL,
//     jadi uji ini menjalankan logika penyaring yang sama atas label yang benar-benar disusun
//     layar — dan menuntut pengetikan NAMA maupun KODE sama-sama menemukan barisnya.
//  3. BENTUK TULISAN LABELNYA TIDAK BERUBAH dari yang dulu tampil di <select> ("NAMA (KODE)"),
//     supaya tidak ada yang merasa daftarnya berganti.
//  4. PENJAGA WAJIB-ISI TIDAK HILANG bersama atribut `required` bawaan <select>: tombol
//     "Berikutnya" tetap dikunci canNext() selama category_id kosong, dan server tetap
//     memvalidasi required|integer|exists.
//  5. Mengosongkan pilihan memulangkan nilai kosong yang SAMA dengan keadaan awal form,
//     sehingga penjaganya tetap menahan.
//
// Jalankan:  node tests/Manual/uji-kategori-invoice-ketik.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = 'BusinessSupport/Payable/Form';
const BERKAS = path.join(ROOT, 'resources/js/Pages', HALAMAN + '.vue');
const PICKER = path.join(ROOT, 'resources/js/Components/UI/SearchSelect.vue');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BusinessSupport/Payable/ApInvoiceController.php');

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => {
            const dasar = path.join(ROOT, 'resources/js', a.path.slice(2));
            for (const akhiran of ['', '.ts', '.js', '.vue']) {
                const coba = dasar + akhiran;
                if (fs.existsSync(coba) && fs.statSync(coba).isFile()) return { path: coba };
            }

            return { path: dasar };
        });
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h, reactive } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + ' export function useForm(data) {'
                  + '   const f = reactive({ ...data, errors: {}, processing: false,'
                  + '     post(){}, put(){}, get(){}, delete(){}, reset(){}, transform(){ return f; } });'
                  + '   return f;'
                  + ' }'
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

async function render(props) {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs', platform: 'node',
        external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const peringatan = [];
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = (msg) => { peringatan.push(String(msg)); };
    const rute = (n, p) => '/' + n + (p !== undefined ? '/' + (Array.isArray(p) ? p.join('/') : p) : '');
    globalThis.route = rute;
    app.config.globalProperties.route = rute;
    const html = await renderToString(app);

    return {
        html,
        teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' '),
        peringatan,
    };
}

// Kategori tiruan yang MENIRU masalah sesungguhnya: lima nama berawalan "BIAYA " yang hanya
// bisa dibedakan di kata berikutnya. Daftar yang mudah dibedakan tidak menguji apa pun.
const KATEGORI = [
    { id: 11, code: 'BIAYA-AIR-KANTOR', name: 'BIAYA AIR KANTOR', debit: '50301', payable: '20101' },
    { id: 12, code: 'BIAYA-INTERNET', name: 'BIAYA INTERNET', debit: '50302', payable: '20101' },
    { id: 13, code: 'BIAYA-LISTRIK-KANTOR', name: 'BIAYA LISTRIK KANTOR', debit: '50303', payable: '20101' },
    { id: 14, code: 'BIAYA-TELP-PULSA', name: 'BIAYA TELP & PULSA', debit: '50304', payable: '20101' },
    { id: 15, code: 'ADMIN-BANK', name: 'ADMIN BANK', debit: '50305', payable: '20101' },
];

function propsLayar() {
    // Daftar propnya panjang, dan yang kurang memunculkan "Missing required prop" — persis
    // gejala yang sedang dijaga pemeriksaan pertama, jadi semuanya diisi.
    return {
        suppliers: [{ id: 1, code: 'SUP-1', name: 'PLN' }],
        categories: KATEGORI,
        pphAccounts: [{ id: 201, code: '21101', name: 'HUTANG PPH 23' }],
        pphDefaults: { pph23: 201 },
        ppnAccount: { id: 202, code: '11701', name: 'PPN MASUKAN' },
        pphBorneDefaults: { pph23: 203 },
        pphBorneExpense: { id: 203, code: '50999', name: 'BEBAN PPH DITANGGUNG' },
        accounts: [{ id: 101, code: '50303', name: 'BIAYA LISTRIK' }],
        costCenters: [{ id: 5, code: 'CC-FIN', name: 'Finance' }],
        departments: [], ledgers: [], locations: [], units: [],
        trxTypes: { normal: 'Normal' },
    };
}

/**
 * Perender komponen pemilihnya SENDIRI.
 *
 * Langkah 2 wizard belum dirender saat halaman dibuka (masih di langkah 1 "Pilih Supplier"),
 * dan panel SearchSelect hanya muncul setelah dibuka. Jadi yang dibuktikan di sini adalah
 * bahwa label yang DIRAKIT LAYAR benar-benar itu yang dilihat orang pada tombolnya.
 */
async function renderPicker(props) {
    const hasil = await esbuild.build({
        entryPoints: [PICKER], bundle: true, write: false, format: 'cjs', platform: 'node',
        external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = () => {};
    const html = await renderToString(app);

    return { html, teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ') };
}

/**
 * Salinan PERSIS logika penyaring SearchSelect (labelOf + filtered), dibaca dari berkasnya
 * supaya uji ini gagal bila penyaring aslinya berubah bentuk — bukan menebak cara kerjanya.
 */
function penyaringSearchSelect(src) {
    const punyaLabel = /if \(o\.label\) return String\(o\.label\);/.test(src);
    const menyaringLabel = /filter\(\(o\) => labelOf\(o\)\.toLowerCase\(\)\.includes\(q\)\)/.test(src);

    return { punyaLabel, menyaringLabel };
}

async function main() {
    // Label dirakit persis seperti layar merakitnya — dipakai penyaring maupun perender pemilih.
    const opsi = KATEGORI.map((c) => ({ ...c, label: `${c.name} (${c.code})` }));
    const src = fs.readFileSync(BERKAS, 'utf8');
    const srcPicker = fs.readFileSync(PICKER, 'utf8');
    const srcCtl = fs.existsSync(CTL) ? fs.readFileSync(CTL, 'utf8') : '';

    console.log('=== 1. Layar dirender & memakai pemilih yang bisa diketik ===');

    let hasil;
    try {
        hasil = await render(propsLayar());
        const parah = hasil.peringatan.filter((w) =>
            /Missing required prop|was accessed during render|Invalid prop/i.test(w));
        cek('wizard Registrasi Invoice Hutang dirender', parah.length === 0, parah.slice(0, 2).join(' | '));
    } catch (e) {
        cek('wizard Registrasi Invoice Hutang dirender', false, String(e.message).split('\n')[0]);
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + gagal + '\nADA YANG GAGAL');
        process.exit(1);
    }

    // Blok Kategori Invoice harus memakai SearchSelect, bukan <select> polos.
    const blok = src.slice(Math.max(0, src.indexOf('Kategori Invoice *')), src.indexOf('Kategori Invoice *') + 700);
    cek('Kategori Invoice memakai SearchSelect', /<SearchSelect[^>]*v-model="form\.category_id"/.test(blok),
        'potongan: ' + blok.slice(0, 120).replace(/\s+/g, ' '));
    cek('  dan tidak lagi <select> polos', ! /<select[^>]*v-model="form\.category_id"/.test(src),
        'menggulir puluhan kategori berawalan mirip adalah cara termudah memilih yang salah');
    cek('  diberi pilihan yang berlabel, bukan daftar mentah', /:options="kategoriOpsi"/.test(blok));

    console.log('\n=== 2. Pengetikan benar-benar menyaring ===');

    const ss = penyaringSearchSelect(srcPicker);
    cek('SearchSelect memakai `label` bila ada', ss.punyaLabel,
        'tanpa itu, label rakitan layar diabaikan dan pengetikan kode tidak menemukan apa pun');
    cek('  dan menyaring terhadap LABEL', ss.menyaringLabel);

    const saring = (q) => {
        const s = String(q).trim().toLowerCase();

        return s ? opsi.filter((o) => o.label.toLowerCase().includes(s)) : opsi;
    };

    cek('mengetik "listrik" → hanya BIAYA LISTRIK KANTOR',
        saring('listrik').length === 1 && saring('listrik')[0].id === 13,
        'hasil: ' + JSON.stringify(saring('listrik').map((o) => o.name)));
    cek('mengetik KODE "BIAYA-INTERNET" → hanya BIAYA INTERNET',
        saring('BIAYA-INTERNET').length === 1 && saring('BIAYA-INTERNET')[0].id === 12,
        'hasil: ' + JSON.stringify(saring('BIAYA-INTERNET').map((o) => o.name)));
    cek('  pencarian tidak peka huruf besar-kecil',
        saring('LiStRiK').length === 1 && saring('LiStRiK')[0].id === 13);
    cek('mengetik "biaya" → keempat yang berawalan BIAYA, bukan ADMIN BANK',
        saring('biaya').length === 4 && ! saring('biaya').some((o) => o.id === 15));
    cek('mengetik yang tidak ada → kosong, bukan seluruh daftar',
        saring('kategori yang tidak ada').length === 0,
        'memulangkan seluruh daftar saat tak ada yang cocok membuat orang memilih yang salah');
    cek('kotak pencarian kosong → seluruh daftar', saring('').length === KATEGORI.length);

    console.log('\n=== 3. Bentuk tulisan labelnya tidak berubah ===');

    // Di <select> yang lama, barisnya tertulis "{{ c.name }} ({{ c.code }})".
    cek('label tetap "NAMA (KODE)" seperti di <select> yang lama',
        opsi[2].label === 'BIAYA LISTRIK KANTOR (BIAYA-LISTRIK-KANTOR)', 'label: ' + opsi[2].label);
    cek('  dan layar merakitnya dengan bentuk itu',
        /label: `\$\{c\.name\} \(\$\{c\.code\}\)`/.test(src),
        'bentuk yang berbeda membuat orang merasa daftarnya berganti');

    console.log('\n=== 4. Penjaga wajib-isi tidak ikut hilang ===');

    // Atribut `required` bawaan <select> memang hilang — tetapi dua penjaga lain tetap ada.
    cek('tombol "Berikutnya" dikunci selama kategori kosong',
        /step\.value === 2\) return !!form\.category_id/.test(src) && /:disabled="!canNext\(\)"/.test(src));
    cek('server tetap memvalidasi required|integer|exists',
        /'category_id' => \['required', 'integer', 'exists:bs_ap_categories,id'\]/.test(srcCtl),
        'berkas pengendali: ' + (srcCtl ? 'terbaca' : 'TIDAK terbaca'));
    // Dikosongkan → nilai yang SAMA dengan keadaan awal form, sehingga penjaganya tetap menahan.
    cek('mengosongkan pilihan memulangkan nilai kosong yang sama dengan keadaan awal',
        /function clear\(\) \{ emit\('update:modelValue', ''\); \}/.test(srcPicker)
        && /category_id: ''/.test(src),
        'bila yang dipulangkan null sedangkan form mulai dengan "", keduanya memang sama-sama '
        + 'kosong — tetapi perbedaannya akan menggigit di perbandingan yang ketat');

    console.log('\n=== 5. Layarnya benar-benar memuat kategorinya ===');

    cek('langkah 1 wizard terender', /Pilih Supplier/.test(hasil.teks));
    cek('tidak ada NaN/undefined yang lolos ke layar',
        ! /\bNaN\b|\bundefined\b/.test(hasil.teks));

    const kosong = await renderPicker({ modelValue: '', options: opsi, placeholder: '— ketik atau pilih kategori —' });
    cek('pemilih dengan pilihan kosong menampilkan ajakan mengetik',
        kosong.teks.includes('ketik atau pilih kategori'), 'teks: ' + kosong.teks.trim().slice(0, 80));
    const terpilih = await renderPicker({ modelValue: 13, options: opsi });
    cek('  pilihan terpilih tampil PERSIS sebagai "NAMA (KODE)"',
        terpilih.teks.includes('BIAYA LISTRIK KANTOR (BIAYA-LISTRIK-KANTOR)'),
        'teks: ' + terpilih.teks.trim().slice(0, 90));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exit(gagal === 0 ? 0 : 1);
}

main().catch((e) => { console.error(e); process.exit(1); });
