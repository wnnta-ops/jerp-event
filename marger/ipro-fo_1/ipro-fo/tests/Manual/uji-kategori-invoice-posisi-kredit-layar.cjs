// Setup Kategori Invoice — kolom "Posisi Kredit" (Hutang / Beban Masih Harus Dibayar).
//
// Yang dijaga:
//  1. POSISI TIAP BARIS TERLIHAT DI DAFTAR. Tanpa itu, dua kategori yang menjurnal ke akun
//     berbeda tampak persis sama di layar.
//  2. MODAL EDIT HARUS IKUT MENGISI credit_position. Object.assign TIDAK menyentuh kunci yang
//     tidak disebut, jadi modal yang lupa menyebutnya akan MEWARISI posisi dari baris yang
//     dibuka SEBELUMNYA — operator membuka kategori 'hutang', melihat 'beban', lalu menyimpan.
//  3. POSISI "BEBAN" MENGUNCI AKUNNYA, TETAPI TETAP MENAMPILKANNYA. Menyembunyikan begitu saja
//     membuat operator menebak akun apa yang akan dikredit.
//  4. AKUNNYA TIDAK DIPAKU DI LAYAR. Kode 20105050501 datang dari server (config), bukan
//     ditulis di komponen — kalau ditulis di sini, mengganti COA-nya di config akan membuat
//     layar dan jurnal menyebut akun yang berbeda.
//
// Jalankan:  node tests/Manual/uji-kategori-invoice-posisi-kredit-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HAL = 'BusinessSupport/Payable/Categories';
const BERKAS = path.join(ROOT, 'resources/js/Pages', HAL + '.vue');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BusinessSupport/Payable/ApCategoryController.php');
const MODEL = path.join(ROOT, 'app/Domain/BusinessSupport/Models/BsApCategory.php');
const CFG = path.join(ROOT, 'config/business_support.php');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){} };'
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

const POSISI = { hutang: 'Hutang', beban_ymhd: 'Beban Masih Harus Dibayar' };
const PROPS = {
    categories: [
        { id: 1, code: 'BIAYA-LISTRIK', name: 'BIAYA LISTRIK KANTOR', is_active: true, debit_account_id: 10, payable_account_id: 20, credit_position: 'hutang', debit: '50302020217 - BIAYA LISTRIK DAN AIR', payable: '20101010101 - UTANG USAHA SUPPLIER' },
        { id: 2, code: 'JASA-AUDIT', name: 'JASA AUDIT', is_active: true, debit_account_id: 11, payable_account_id: 357, credit_position: 'beban_ymhd', debit: '50301010110 - BIAYA AUDIT', payable: '20105050501 - BEBAN MASIH HARUS DIBAYAR' },
    ],
    filters: {},
    accounts: [{ id: 10, code: '50302020217', name: 'BIAYA LISTRIK DAN AIR' }, { id: 20, code: '20101010101', name: 'UTANG USAHA SUPPLIER' }],
    defaultPayable: 20,
    posisiKredit: POSISI,
    akunBeban: { id: 357, label: '20105050501 - BEBAN MASIH HARUS DIBAYAR' },
};

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs', platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, PROPS) });
    app.config.warnHandler = () => {};
    app.config.globalProperties.route = (n, p) => '/' + n + (p !== undefined ? '/' + p : '');
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    const src = fs.readFileSync(BERKAS, 'utf8');
    const kode = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1').replace(/<!--[\s\S]*?-->/g, '');
    const ctl = fs.readFileSync(CTL, 'utf8');
    const model = fs.readFileSync(MODEL, 'utf8');
    const cfg = fs.readFileSync(CFG, 'utf8');

    console.log('\n=== 1. Daftar memperlihatkan posisi tiap baris ===');

    /* Diperiksa pada <thead>-nya sendiri: kalimat penjelas di atas tabel juga memuat frasa
       "Posisi Kredit", jadi memeriksa seluruh teks halaman tetap hijau walau judul kolomnya
       dihapus. */
    const thead = (/<thead[\s\S]*?<\/thead>/.exec(html) ?? [''])[0].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    cek('kolom Posisi Kredit ada di judul tabel', thead.includes('Posisi Kredit'), thead);
    cek('baris hutang ditandai Hutang', teks.includes('Hutang'), teks.slice(0, 500));
    cek('baris beban ditandai Beban Masih Harus Dibayar', teks.includes('Beban Masih Harus Dibayar'));
    cek('akun kredit tiap baris tetap tampil',
        teks.includes('20101010101 - UTANG USAHA SUPPLIER') && teks.includes('20105050501 - BEBAN MASIH HARUS DIBAYAR'));
    cek('label posisi dibaca dari prop, bukan dipaku di layar',
        /posisiKredit\[c\.credit_position\]/.test(kode), 'layar punya daftar label sendiri');
    cek('keterangan halaman menyebut ini hanya soal penjurnalan',
        /hanya memengaruhi penjurnalan/.test(teks), teks.slice(0, 700));

    console.log('\n=== 2. Modal: posisi ikut terisi & akunnya menyesuaikan ===');

    cek('pilihan posisi dirender dari prop', /v-for="\(label, nilai\) in posisiKredit"/.test(kode));
    // Object.assign tidak menyentuh kunci yang tidak disebut — modal Edit yang lupa
    // menyebutkan credit_position akan mewarisi posisi baris yang dibuka SEBELUMNYA.
    cek('openEdit ikut mengisi credit_position', /Object\.assign\(form, \{[^}]*credit_position:/.test(kode),
        'modal Edit mewarisi posisi dari baris yang dibuka sebelumnya');
    cek('openCreate mengembalikannya ke hutang', /function openCreate\(\)[^}]*credit_position = 'hutang'/.test(kode),
        'Tambah baru membawa sisa posisi dari kategori yang terakhir diedit');
    cek('useForm punya credit_position', /useForm<any>\(\{[^}]*credit_position:/.test(kode));

    cek('posisi beban MENGGANTI pemilih akun, bukan menambahinya',
        /v-if="! posisiBeban"[\s\S]{0,400}?<SearchSelect v-model="form\.payable_account_id"/.test(kode)
        && /v-else[\s\S]{0,400}?akunBeban\?\.label/.test(kode),
        'kedua kolom bisa tampil bersamaan, atau akunnya disembunyikan tanpa diganti apa pun');
    cek('akun bebannya DITAMPILKAN, bukan disembunyikan', /akunBeban\?\.label/.test(kode),
        'operator menebak akun apa yang akan dikredit');
    cek('dikatakan bahwa pelunasannya mengurangi akun yang sama',
        /Pelunasannya nanti mengurangi akun yang sama/.test(kode));

    console.log('\n=== 3. Kode akun tidak dipaku di mana pun kecuali config ===');

    cek('layar tidak memaku 20105050501', ! /20105050501/.test(kode), 'ganti COA di config → layar & jurnal menyebut akun berbeda');
    cek('controller tidak memaku 20105050501', ! /20105050501/.test(ctl));
    cek('model tidak memaku 20105050501', ! /20105050501/.test(model));
    cek('config-lah yang menyimpannya', /'accrued_expense' => '20105050501'/.test(cfg));
    cek('model membacanya dari config', /config\('business_support\.gl_accounts\.accrued_expense'\)/.test(model));

    console.log('\n=== 4. Server yang menentukan akunnya, bukan layar ===');

    cek('controller memaksa akun pada posisi beban', /private function akunKredit/.test(ctl));
    cek('  dan mengabaikan kiriman layar', /if \(\$posisi !== BsApCategory::POSISI_BEBAN\)/.test(ctl),
        'akun kiriman layar dipercaya apa adanya');
    cek('akun hutang wajib HANYA pada posisi hutang', /required_if:credit_position/.test(ctl),
        'form posisi beban ditolak karena tidak mengirim akun');
    cek('posisi divalidasi terhadap daftar yang ada', /Rule::in\(array_keys\(BsApCategory::POSISI\)\)/.test(ctl));
    cek('impor menerima kolom posisi_kredit', /'posisi_kredit'/.test(ctl));
    cek('  dan berkas lima kolom tetap jatuh ke hutang', /\$posisi === "" \? BsApCategory::POSISI_HUTANG/.test(ctl),
        'impor rutin berkolom lima diam-diam memindahkan kategori ke akun lain');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
