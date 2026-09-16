// KPR → Set Up → Kategori Reject Bank — sisi layar, di-render SUNGGUHAN lewat SSR.
//
// Tiga hal yang salahnya TIDAK memunculkan galat apa pun:
//
//  1. NAMA HALAMAN Inertia vs NAMA DIREKTORI. Controller mengirim string
//     'BankLoan/Setup/RejectBank/Index'; Vite memuat berkasnya lewat import.meta.glob. Di
//     Windows salah huruf besar/kecil tetap jalan, di container Linux halamannya tidak
//     ketemu — gagal yang hanya muncul di server. Karena itu pencocokannya di sini dilakukan
//     HURUF PER HURUF lewat isi direktori, bukan lewat fs.existsSync yang tidak peka huruf.
//  2. PROP PAGINATOR. Controller memulangkan ->paginate(50); layar menulis
//     v-for="c in categories.data". Memberi array datar membuat tabel kosong tanpa satu pun
//     galat — dan uji yang hanya memeriksa "tidak error" akan hijau.
//  3. URUTAN MENU. Permintaannya tegas: "di bawah Kategori Berkas Tidak Lengkap". Sidebar
//     disusun dari navGroups.ts; menaruhnya di tempat lain tidak merusak apa pun, hanya
//     membuat orang mencarinya.
//
// Jalankan:  node tests/Manual/uji-kategori-reject-bank-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = 'BankLoan/Setup/RejectBank/Index';
const BERKAS = path.join(ROOT, 'resources/js/Pages', HALAMAN + '.vue');
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');
const RUTE = path.join(ROOT, 'routes/web.php');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BankLoan/Setup/BankRejectCategoryController.php');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, delete(){} };'
                  + ' export const useForm = (d) => ({ ...d, errors: {}, processing: false, reset(){}, post(){}, put(){} });'
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

const ISI_AWAL = [
    'RPC KURANG',
    'DATA DIRAGUKAN',
    'TIDAK DAPAT UNIT',
    'TIDAK BANKABLE (MAYORITAS PENGHASILAN TUNAI)',
    'BATAL SEPIHAK',
];

const PROPS = () => ({
    categories: {
        data: ISI_AWAL.map((name, i) => ({ id: i + 1, name, sort_order: i + 1, is_active: i !== 1 })),
    },
    filters: {},
});

/** Cocokkan path HURUF PER HURUF lewat isi direktori — fs.existsSync tidak peka huruf di Windows. */
function adaPersis(akar, relatif) {
    let kini = akar;
    for (const bagian of relatif.split('/')) {
        let isi;
        try { isi = fs.readdirSync(kini); } catch { return false; }
        if (! isi.includes(bagian)) return false;
        kini = path.join(kini, bagian);
    }

    return true;
}

/** Label anak sebuah menu di navGroups.ts, apa adanya dari definisinya. */
function anakMenu(src, label) {
    const i = src.indexOf("label: '" + label + "'");
    if (i < 0) return null;
    const mulai = src.indexOf('children: [', i);
    if (mulai < 0) return null;
    let j = src.indexOf('[', mulai), dalam = 0, akhir = j;
    for (; akhir < src.length; akhir++) {
        if (src[akhir] === '[') dalam++;
        else if (src[akhir] === ']') { dalam--; if (dalam === 0) break; }
    }
    const blok = src.slice(j, akhir);

    return [...blok.matchAll(/\{\s*label: '([^']+)'[^}]*?href: '([^']+)'[^}]*?permission: '([^']+)'/g)]
        .map((m) => ({ label: m[1], href: m[2], permission: m[3] }));
}

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const Comp = modul.exports.default;

    const app = createSSRApp({ render: () => h(Comp, PROPS()) });
    app.config.globalProperties.route = (n, p) => '/' + n + (p ? '/' + p : '');
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    const src = fs.readFileSync(BERKAS, 'utf8');
    // Komentar dibuang lebih dulu: kalau tidak, penjaga di bawah bisa cocok dengan komentarku sendiri.
    const kode = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
    const nav = fs.readFileSync(NAV, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');
    const ctl = fs.readFileSync(CTL, 'utf8');

    console.log('\n=== 1. Halaman ter-render & memuat isinya ===');

    cek('judul halaman tampil', teks.includes('Kategori Reject Bank'), teks.slice(0, 160));
    for (const n of ISI_AWAL) cek('baris "' + n + '" tampil', teks.includes(n));
    cek('keempat judul kolom ada',
        ['Urutan', 'Nama Kategori', 'Status', 'Action'].every((j) => teks.includes(j)), teks.slice(0, 300));
    cek('kolom urutan menampilkan angkanya', /1 RPC KURANG/.test(teks), teks.slice(0, 300));
    cek('status Aktif & Nonaktif dibedakan', teks.includes('Aktif') && teks.includes('Nonaktif'),
        'baris nonaktif terlihat sama dengan yang aktif');

    console.log('\n=== 2. Prop paginator, bukan array datar ===');

    cek('layar membaca categories.data', /v-for="c in categories\.data"/.test(kode),
        'kalau membaca categories langsung, prop paginator dari controller tidak akan terbaca');
    const kosong = createSSRApp({ render: () => h(Comp, { categories: { data: [] }, filters: {} }) });
    kosong.config.globalProperties.route = () => '#';
    const htmlKosong = (await renderToString(kosong)).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    cek('daftar kosong memunculkan EmptyState', htmlKosong.includes('Belum ada kategori'), htmlKosong.slice(0, 200));
    cek('daftar kosong tidak memunculkan baris data', ! ISI_AWAL.some((n) => htmlKosong.includes(n)));

    console.log('\n=== 3. Nama halaman Inertia = nama direktori, huruf per huruf ===');

    const mHalaman = /Inertia::render\('([^']+)'/.exec(ctl);
    cek('controller menyebut nama halaman', !! mHalaman, '(tidak ada Inertia::render)');
    cek('namanya ' + HALAMAN, mHalaman && mHalaman[1] === HALAMAN, mHalaman ? mHalaman[1] : '-');
    // Di container Linux salah satu huruf saja = halaman tidak ketemu; di Windows lolos diam-diam.
    cek('berkas .vue-nya ada dengan ejaan yang sama persis',
        mHalaman && adaPersis(path.join(ROOT, 'resources/js/Pages'), mHalaman[1] + '.vue'),
        'ejaan direktori berbeda dari string Inertia::render');

    console.log('\n=== 4. Nama rute yang dipakai layar benar-benar terdaftar ===');

    const dipakai = [...new Set([...kode.matchAll(/route\('([^']+)'/g)].map((m) => m[1]))];
    cek('layar memakai keempat rute reject-bank', dipakai.length === 4, JSON.stringify(dipakai));
    for (const n of dipakai) {
        cek('rute ' + n + ' terdaftar di routes/web.php', rute.includes("->name('" + n + "')"),
            'route() ke nama yang tidak ada melempar galat saat halaman dibuka');
    }
    cek('tidak ada nama rute milik master sebelah yang ikut tersalin',
        ! /berkas-tidak-lengkap/.test(kode), 'hasil salin-tempel menunjuk balik ke Kategori Berkas Tidak Lengkap');

    console.log('\n=== 5. Sidebar: tepat DI BAWAH Kategori Berkas Tidak Lengkap ===');

    const setUp = anakMenu(nav, 'Set Up');
    cek('menu Set Up KPR terbaca', !! setUp && setUp.length >= 5, JSON.stringify(setUp?.map((x) => x.label)));
    const label = (setUp ?? []).map((x) => x.label);
    const iBerkas = label.indexOf('Kategori Berkas Tidak Lengkap');
    const iReject = label.indexOf('Kategori Reject Bank');
    cek('Kategori Reject Bank ada di menu', iReject >= 0, JSON.stringify(label));
    cek('posisinya TEPAT di bawah Kategori Berkas Tidak Lengkap', iBerkas >= 0 && iReject === iBerkas + 1,
        'berkas=' + iBerkas + ' reject=' + iReject + ' → ' + JSON.stringify(label));
    const item = (setUp ?? []).find((x) => x.label === 'Kategori Reject Bank');
    cek('menunjuk kpr.setup.reject-bank.index', item?.href === 'kpr.setup.reject-bank.index', item?.href);
    cek('memakai izin bankloan.setup.view (tanpa izin baru)', item?.permission === 'bankloan.setup.view', item?.permission);

    console.log('\n=== 6. Form tambah/ubah lengkap ===');

    // Modal dibungkus <Teleport> dan showModal awalnya false, jadi isinya TIDAK ADA di HTML
    // hasil renderToString — diperiksa dari sumbernya.
    cek('ada isian Nama Kategori', /Nama Kategori \*/.test(kode));
    cek('ada isian Urutan', /form\.sort_order/.test(kode));
    cek('ada saklar Aktif', /form\.is_active/.test(kode));
    cek('galat validasi nama ditampilkan', /form\.errors\.name/.test(kode),
        'nama kembar ditolak server tanpa satu pun keterangan di layar');
    cek('hapus meminta konfirmasi', /confirm\(/.test(kode));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
