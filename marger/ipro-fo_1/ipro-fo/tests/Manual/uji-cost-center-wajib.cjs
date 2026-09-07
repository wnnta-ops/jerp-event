// Cost center WAJIB pada Struktur Jurnal — Registrasi Invoice Hutang & Jurnal Memorial.
//
// KENAPA DIWAJIBKAN: baris jurnal tanpa cost center tidak terhitung di Budget Control maupun
// laporan per cost center. Itu tidak muncul sebagai galat apa pun — hanya angka yang kurang,
// dan biasanya baru ketahuan berbulan kemudian saat anggaran direkonsiliasi.
//
// KENAPA TIDAK SEMUA BARIS: 71 dari 267 akun postable bertanda cost_center_optional
// (CoaTanpaCostCenterSeeder) — Utang Usaha Supplier dan sebagian akun neraca lain memang tidak
// menanggung cost center. Mewajibkannya untuk SEMUA baris akan mengunci layarnya pada invoice
// yang justru paling biasa: Dr beban (ber-cost center) / Cr Utang Usaha (tanpa). Uji ini
// menjaga KEDUA sisinya — yang wajib ditahan, yang dikecualikan dibiarkan lewat.
//
// Jalankan:  node tests/Manual/uji-cost-center-wajib.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const MEMORIAL = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/Journals/Form.vue');
const HUTANG = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Form.vue');

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
                  + ' export const router = { post(){}, get(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + " export const usePage = () => ({ props: { flash: {}, auth: { user: { name: 'Super Admin' } } } });"
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {},'
                  + '   post(){}, put(){}, transform(){ return this; } });'
                : 'module.exports = new Proxy({}, { get: () => ({ render: () => null }) });',
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            return {
                contents: sfc.compileScript(descriptor, { id, inlineTemplate: false }).content,
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

// Dua akun WAJIB cost center + satu yang dikecualikan (seperti Utang Usaha Supplier).
const ACCOUNTS = [
    { id: 1, code: '50302020213', name: 'JASA OUTSOURCE (OB, DRIVER, SECURITY, DSB)', cost_center_optional: false },
    { id: 2, code: '10105010101', name: 'PPN MASUKAN', cost_center_optional: false },
    { id: 3, code: '20101010101', name: 'UTANG USAHA SUPPLIER', cost_center_optional: true },
];
const COST_CENTERS = [{ id: 7, code: 'CC-OPS', name: 'Operasional' }];

async function setup(berkas, props) {
    /* Nama keluarannya diturunkan dari SELURUH path, bukan basename-nya. Kedua layar yang diuji
       di sini sama-sama bernama Form.vue; dengan basename, bundel kedua menimpa yang pertama
       DAN require() memulangkan modul pertama dari cache — ujinya lalu memeriksa komponen yang
       salah sambil tetap tampak berjalan. */
    const kunci = require('crypto').createHash('sha1').update(berkas).digest('hex').slice(0, 8);
    const out = path.join(ROOT, 'cc.' + kunci + '.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [berkas], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    globalThis.route = (n) => '/' + n;
    const Komponen = require(out).default;

    return Komponen.setup(props, { attrs: {}, slots: {}, emit: () => {}, expose: () => {} });
}

(async () => {
    console.log('\n=== 1. Jurnal Memorial: gerbang & pengecualiannya ===');

    const m = await setup(MEMORIAL, { accounts: ACCOUNTS, costCenters: COST_CENTERS });

    // Dua baris bawaan; diisi akun yang WAJIB cost center, tanpa cost center.
    m.form.lines[0].account_id = 1; m.form.lines[0].debit = 1000; m.form.lines[0].credit = 0;
    m.form.lines[1].account_id = 2; m.form.lines[1].debit = 0; m.form.lines[1].credit = 1000;
    cek('kurang cost center terdaftar', m.kurangCc.value.length === 2, JSON.stringify(m.kurangCc.value));
    cek('baris pertama ditandai kurang', m.ccKurang(m.form.lines[0]) === true);
    cek('jurnalnya balance', m.balanced.value === true);

    m.form.lines[0].cost_center_id = 7;
    cek('mengisi satu baris menyisakan satu yang kurang', m.kurangCc.value.length === 1,
        JSON.stringify(m.kurangCc.value));
    m.form.lines[1].cost_center_id = 7;
    cek('semuanya terisi → tidak ada yang kurang', m.kurangCc.value.length === 0);

    // Akun yang DIKECUALIKAN tidak boleh ikut menahan.
    m.form.lines[1].account_id = 3; m.form.lines[1].cost_center_id = null;
    cek('akun cost_center_optional tidak dihitung kurang', m.kurangCc.value.length === 0,
        JSON.stringify(m.kurangCc.value));
    cek('barisnya pun tidak ditandai merah', m.ccKurang(m.form.lines[1]) === false);

    // Cost center HEADER diwarisi baris yang dikosongkan — jadi mengisinya juga melepas gerbang.
    m.form.lines[1].account_id = 2;
    m.form.lines[0].cost_center_id = null; m.form.lines[1].cost_center_id = null;
    cek('tanpa header & tanpa baris → kurang', m.kurangCc.value.length === 2);
    m.form.cost_center_id = 7;
    cek('cost center header melepas gerbangnya', m.kurangCc.value.length === 0,
        JSON.stringify(m.kurangCc.value));

    console.log('\n=== 2. Registrasi Invoice Hutang: gerbang langkah Struktur ===');

    // Prop-nya LENGKAP sesuai yang dikirim ApInvoiceController. Menguji dengan payload yang
    // lebih miskin membuat setup()-nya berjalan di jalur yang tidak pernah terjadi — dan
    // sebagian binding-nya tidak terbentuk, sehingga ujinya gagal atas hal yang bukan cacat.
    const h2 = await setup(HUTANG, {
        suppliers: [{ id: 1, code: 'SP.001', name: 'CV TUNAS JAYA' }],
        categories: [{ id: 1, name: 'Jasa' }], accounts: ACCOUNTS, costCenters: COST_CENTERS,
        departments: [], ledgers: [], locations: [], units: [],
        trxTypes: { normal: 'Normal' }, ppnAccount: null, pphBorneExpense: null,
    });

    h2.step.value = 4;
    h2.form.journal_lines = [
        { account_id: 1, cost_center_id: null, description: 'trial', currency: 'IDR', kurs: 1, nilai: 1000, debit: 1000, credit: 0 },
        { account_id: 3, cost_center_id: null, description: 'hutang', currency: 'IDR', kurs: 1, nilai: 1000, debit: 0, credit: 1000 },
    ];
    cek('balance terbaca', h2.jurnalBalanced.value === true);
    cek('akun wajib tanpa cost center → terdaftar kurang', h2.kurangCc.value.length === 1,
        JSON.stringify(h2.kurangCc.value));
    cek('akun dikecualikan tidak ikut terdaftar',
        ! h2.kurangCc.value.some((s) => s.includes('UTANG USAHA')), JSON.stringify(h2.kurangCc.value));

    // INI inti permintaannya: tidak bisa lanjut selagi cost centernya kurang.
    cek('tombol Berikutnya TERTAHAN selagi cost center kurang', h2.canNext() === false);
    h2.form.journal_lines[0].cost_center_id = 7;
    cek('setelah diisi, Berikutnya terbuka', h2.canNext() === true);

    // Gerbangnya tidak boleh menggantikan penjagaan balance yang sudah ada.
    h2.form.journal_lines[1].credit = 999;
    cek('balance tetap dijaga terpisah', h2.canNext() === false, 'balance tidak lagi menahan');

    console.log('\n=== 3. Tanda bintang merahnya ada di kedua layar ===');

    // Tanda wajib itulah yang memberi tahu kolomnya menahan penyimpanan; tanpa itu orang
    // hanya melihat tombol mati dan menyangka layarnya rusak.
    const srcM = fs.readFileSync(MEMORIAL, 'utf8');
    const srcH = fs.readFileSync(HUTANG, 'utf8');
    cek('Jurnal Memorial: kolomnya bertanda bintang merah',
        /Dimensi\s*<span class="text-danger-500">\*<\/span>/.test(srcM));
    cek('Registrasi Invoice Hutang: kolomnya bertanda bintang merah',
        /Cost Center\s*<span class="text-danger-500">\*<\/span>/.test(srcH));
    cek('Jurnal Memorial: baris kurang ditandai "Wajib diisi"',
        srcM.includes('ccKurang(l)') && srcM.includes('Wajib diisi'));
    cek('Registrasi Invoice Hutang: baris kurang ditandai "Wajib diisi"',
        srcH.includes('ccKurang(l)') && srcH.includes('Wajib diisi'));
    cek('Registrasi Invoice Hutang: alasan tombol mati disebutkan',
        srcH.includes('Cost center wajib diisi untuk akun:'));

    console.log('\n=== 4. Baris tanpa akun tidak ikut ditahan ===');

    // Layar Struktur Jurnal memang membolehkan baris kosong sebagai pengisi; barisnya dibuang
    // saat diproses. Menahannya berarti orang harus menghapus baris kosong satu per satu.
    cek('memorial: baris tanpa akun tidak dianggap kurang', m.ccKurang({ account_id: null, cost_center_id: null }) === false);
    cek('hutang: baris tanpa akun tidak dianggap kurang', h2.ccKurang({ account_id: null, cost_center_id: null }) === false);

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message + '\n' + e.stack); process.exit(1); });
