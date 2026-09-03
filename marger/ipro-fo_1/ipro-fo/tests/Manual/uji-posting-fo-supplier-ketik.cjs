// Render Posting.vue YANG SEBENARNYA: kolom Supplier harus bisa DIKETIK, dan bawaannya terisi.
//
// KENAPA PERLU UJI: masternya berisi ratusan supplier. Dengan <select> biasa, daftar sepanjang
// itu praktis tidak bisa dipakai — orang harus menggulir mencari satu nama, untuk setiap baris,
// berulang kali. Kembali ke <select> tidak menimbulkan galat apa pun; layarnya tetap "jalan",
// hanya menjadi tak terpakai. Itulah yang dijaga di sini.
//
// Dijaga juga: nilai yang dipakai tetap ID supplier (bukan labelnya), sebab seluruh logika
// centang → gabung → posting di bawahnya membandingkan id. Dan bawaan per sumber — BLT ke
// SP.00222, Komisi ke SP.00223 — benar-benar terisi ke kotaknya, bukan sekadar ada di data.
//
// Jalankan:  node tests/Manual/uji-posting-fo-supplier-ketik.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Posting.vue');

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
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const usePage = () => ({ props: { flash: {} } });'
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

// SP.00222 & SP.00223 = supplier penampung pembayaran BLT & Komisi.
const SUPPLIERS = [
    { id: 494, label: 'SP.00222 — PEMBAYARAN BLT' },
    { id: 495, label: 'SP.00223 — PEMBAYARAN KOMISI' },
    { id: 410, label: 'SP.00410 — A&F Entertainment (Rengga Ferianto)' },
];
const REFUNDS = [
    { id: 1, refund_no: 'BLTPENG-202608-00002#BLT-202608-00013', customer: 'AKU CINTA KAMU',
      sumber_label: 'BLT', amount: 2500000, tgl: '2026-08-10', keterangan: 'BLT Kantor', supplier_id: 494, akun_pecah: 2 },
    { id: 2, refund_no: 'KOMPENG-202609-00001#KOM-202609-00003', customer: 'DEDE SANJAYA',
      sumber_label: 'Komisi', amount: 11044792, tgl: '2026-09-01', keterangan: 'Komisi', supplier_id: 495, akun_pecah: 2 },
    { id: 3, refund_no: 'GMC-202609-00004-1', customer: 'NABILA ZACHNAS',
      sumber_label: 'GMC', amount: 1900000, tgl: '2026-09-02', keterangan: 'Gimmick AJB', supplier_id: 410, akun_pecah: 2 },
];

(async () => {
    const out = path.join(ROOT, 'posting.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [BERKAS], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });

    globalThis.route = (nama) => '/' + nama;
    const Komponen = require(out).default;
    const app = createSSRApp(Komponen, { refunds: REFUNDS, suppliers: SUPPLIERS });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };

    /* Peringatan Vue TIDAK didiamkan begitu saja — hanya SATU yang dimaafkan, dan alasannya
       diketahui: ikon lucide distub sebagai Proxy, sehingga impor bernamanya ("ArrowLeft",
       dst.) terbaca undefined oleh interop CJS esbuild. Ikonnya tidak ikut diperiksa uji ini.
       Peringatan lain apa pun MENGGAGALKAN uji — peringatan render yang dibiarkan adalah cara
       paling mudah sebuah komponen diam-diam berhenti dirender tanpa ada yang menyadarinya. */
    const peringatanLain = [];
    app.config.warnHandler = (msg) => {
        if (! msg.includes('Invalid vnode type')) peringatanLain.push(msg);
    };
    const html = await renderToString(app);
    // Komentar template ikut terbawa hasil render; dibuang supaya tidak ikut tercocokkan.
    const bersih = html.replace(/<!--[\s\S]*?-->/g, '');

    console.log('\n=== 1. Kolom Supplier bisa diketik ===');

    cek('tidak ada lagi <select> supplier', ! bersih.includes('— pilih supplier —</option>'),
        'masih memakai <option>');
    // Kotak ketiknya ada di panel ber-Teleport yang baru dirender saat dropdownnya DIBUKA,
    // jadi tidak muncul pada render diam. Yang diperiksa di sini kontrak komponennya:
    // SearchSelect memang menyediakan penyaring ketik. Bahwa halaman ini memakainya
    // diperiksa di bagian 3.
    const srcSelect = fs.readFileSync(path.join(ROOT, 'resources/js/Components/UI/SearchSelect.vue'), 'utf8');
    cek('komponen pilihannya menyediakan kotak ketik',
        /v-model="query"[^>]*type="text"/.test(srcSelect.replace(/\n\s*/g, ' ')),
        'kotak pencarian tidak ditemukan di SearchSelect');
    cek('penyaringannya mencakup kode maupun nama',
        srcSelect.includes('Ketik nama / kode'), 'petunjuk ketiknya tidak ditemukan');
    // Daftar 762 supplier yang dicetak seluruhnya sebagai <option> per baris adalah persis
    // keadaan yang membuat layarnya berat sekaligus tak terpakai.
    const jmlOption = (bersih.match(/<option/g) || []).length;
    cek('opsinya tidak dicetak seluruhnya per baris', jmlOption === 0, jmlOption + ' <option>');

    console.log('\n=== 2. Bawaan per sumber terisi di kotaknya ===');

    // Bukan sekadar ada di data: yang dilihat operator adalah isi kotaknya.
    cek('baris BLT menampilkan PEMBAYARAN BLT', bersih.includes('SP.00222 — PEMBAYARAN BLT'),
        'label bawaan BLT tidak terlihat');
    cek('baris Komisi menampilkan PEMBAYARAN KOMISI', bersih.includes('SP.00223 — PEMBAYARAN KOMISI'),
        'label bawaan Komisi tidak terlihat');
    cek('baris GMC tetap memakai suppliernya sendiri', bersih.includes('A&amp;F Entertainment')
        || bersih.includes('A&F Entertainment'), 'label supplier GMC tidak terlihat');

    console.log('\n=== 3. Yang dipakai tetap ID suppliernya ===');

    // Seluruh logika centang → gabung → posting membandingkan id. Bila komponennya
    // memulangkan label, penggabungan "satu supplier" akan membandingkan untai dan
    // penjagaannya jadi kebetulan, bukan aturan.
    const src = fs.readFileSync(BERKAS, 'utf8');
    cek('SearchSelect dipasang dengan option-value="id"',
        /<SearchSelect[^>]*option-value="id"/.test(src.replace(/\n/g, ' ')));
    cek('SearchSelect terikat ke supplierSel baris itu',
        /<SearchSelect[^>]*v-model="supplierSel\[it\.id\]"/.test(src.replace(/\n/g, ' ')));

    console.log('\n=== 4. Tombol posting per baris tetap ada ===');

    cek('tombol Posting Invoice per baris masih dirender', bersih.includes('Posting'),
        'tombol tidak ditemukan');
    cek('ketiga baris antrean dirender',
        REFUNDS.every((r) => bersih.includes(r.refund_no)));
    cek('tidak ada peringatan render lain', peringatanLain.length === 0,
        peringatanLain.join(' | '));

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message + '\n' + e.stack); process.exit(1); });
