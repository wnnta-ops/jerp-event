// Jalankan tombol Import pada ImportMassal.vue YANG SEBENARNYA, dan periksa ke mana ia mengirim.
//
// KENAPA PERLU UJI INI: berkas uji import massal yang sudah ada (uji-import-massal.php) hanya
// memanggil pengendalinya langsung. Pengendalinya memang benar — yang rusak jalur menuju ke
// sana. router.post() menerima URL, sedangkan komponennya mengoper NAMA rutenya apa adanya
// ("bs.suppliers.import"), sehingga browser memposting ke path harfiah itu, relatif terhadap
// halaman yang sedang dibuka, dan jawabannya 404 "Halaman Tidak Ditemukan".
//
// Gejalanya khas dan menipu: tautan unduh template memakai route(...) dan bekerja normal,
// jadi fiturnya tampak setengah hidup — template turun, tombol Import mental. Uji pengendali
// tidak akan pernah melihatnya. Uji ini melihatnya karena benar-benar MEMANGGIL kirim().
//
// Yang dijaga: alamat tujuannya URL hasil route(), bukan nama rutenya; nama itu diteruskan
// apa adanya ke route(); berkas & mode ikut terkirim; dan tombolnya tidak mengirim dua kali.
//
// Jalankan:  node tests/Manual/uji-import-massal-kirim.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Components/UI/ImportMassal.vue');

// Semua yang dikirim komponen ditampung di sini.
const terkirim = [];
const dimintaKeRoute = [];

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
                // router.post dicatat, bukan dijalankan — yang diperiksa argumen pertamanya.
                ? 'export const router = { post: (...a) => globalThis.__catat(a), get(){} };'
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                : 'module.exports = new Proxy({}, { get: () => ({ render: () => null }) });',
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            // inlineTemplate: false — yang dibutuhkan bindings hasil setup(), bukan HTML-nya.
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

(async () => {
    // Berkas hasil bundling ditaruh DI DALAM proyek: ia me-require 'vue', dan dari luar
    // pohon node_modules proyek modul itu tidak ketemu.
    const out = path.join(ROOT, 'importmassal.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [BERKAS], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });

    globalThis.__catat = (a) => terkirim.push(a);
    // route() adalah GLOBAL sungguhan di browser: @routes menyisipkan dist/route.umd.js.
    // Di sini dipalsukan seperlunya, sekaligus mencatat nama yang diminta.
    globalThis.route = (nama) => {
        dimintaKeRoute.push(nama);

        return '/business-support/setup/suppliers/import';
    };

    const Komponen = require(out).default;
    const props = {
        postRoute: 'bs.suppliers.import',
        templateRoute: 'bs.suppliers.import.template',
        entitas: 'Supplier',
        kolom: ['Kode', 'Nama Supplier'],
        wajib: ['Nama Supplier'],
    };
    const ctx = Komponen.setup(props, { attrs: {}, slots: {}, emit: () => {}, expose: () => {} });

    console.log('\n=== 1. Tombol Import mengirim ke URL, bukan ke nama rute ===');

    ctx.berkas.value = { name: 'Template-Import-Supplier.xlsx' };   // seolah berkas sudah dipilih
    ctx.mode.value = 'perbarui';
    ctx.kirim();

    cek('router.post dipanggil sekali', terkirim.length === 1, 'dipanggil ' + terkirim.length + 'x');
    const [tujuan, muatan] = terkirim[0] || [];
    cek('nama rutenya diserahkan ke route()', dimintaKeRoute.includes('bs.suppliers.import'),
        'yang diminta: ' + JSON.stringify(dimintaKeRoute));
    // Inti perbaikannya. Tanpa route(), nilai ini persis sama dengan props.postRoute.
    cek('tujuannya BUKAN nama rutenya mentah-mentah', tujuan !== props.postRoute, 'tujuan=' + tujuan);
    cek('tujuannya berupa path absolut', typeof tujuan === 'string' && tujuan.startsWith('/'),
        'tujuan=' + JSON.stringify(tujuan));

    console.log('\n=== 2. Isi kirimannya lengkap ===');

    cek('berkasnya ikut terkirim', !!(muatan && muatan.file), JSON.stringify(muatan));
    cek('pilihan "bila datanya sudah ada" ikut terkirim', !!(muatan && muatan.mode === 'perbarui'),
        JSON.stringify(muatan && muatan.mode));

    console.log('\n=== 3. Klik dua kali tidak mengunggah dua kali ===');

    // Berkas ribuan baris butuh waktu; operator yang mengklik lagi karena mengira tak terjadi
    // apa-apa tidak boleh membuat importnya berjalan dua kali.
    ctx.kirim();
    cek('kiriman kedua ditahan selagi yang pertama berjalan', terkirim.length === 1,
        'total kiriman ' + terkirim.length);

    console.log('\n=== 4. Tanpa berkas, tidak mengirim apa pun ===');

    ctx.mengirim.value = false;
    ctx.berkas.value = null;
    ctx.kirim();
    cek('tidak ada kiriman tanpa berkas', terkirim.length === 1, 'total kiriman ' + terkirim.length);

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message); process.exit(1); });
