// Pengajuan Bon Sementara — bawaan Cara Persetujuan = OFFLINE, online tetap bisa dipilih.
//
// KENAPA PERLU UJI: yang dipilih di kolom ini menentukan apakah pembayaran nanti TERTAHAN
// menunggu tanda tangan. Offline mematikan jenjang persetujuan di aplikasi, jadi bawaannya
// bukan sekadar soal kenyamanan — ia menentukan gerbang mana yang terbuka pada bon yang
// dibuat tanpa seorang pun menyentuh pilihan itu. Karena itu bawaannya dikunci uji, dan
// dipastikan pula bahwa pilihan online masih benar-benar ada untuk diubah.
//
// Jalankan:  node tests/Manual/uji-bon-bawaan-offline.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Form.vue');

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
                  + ' export const router = { get(){}, post(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                  // Stub setia: TIDAK memaksa nilai apa pun, supaya bawaan yang diuji benar-benar
                  // datang dari komponennya. Stub yang mengisi sendiri akan membuat uji ini lulus
                  // apa pun isi Form.vue.
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {}, post(){}, put(){}, reset(){} });'
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

async function render(props) {
    const out = path.join(ROOT, 'bawaan-offline.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [BERKAS], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    globalThis.route = (n, p) => '/' + n + (p ? '/' + (p.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

/** Tag <input ...> yang memuat value="..." tertentu. */
function inputBernilai(html, nilai) {
    for (const m of html.matchAll(/<input\b[^>]*>/g)) {
        if (m[0].includes(`value="${nilai}"`)) return m[0];
    }

    return null;
}

/** Blok <label ...>…</label> yang memuat sebuah untai. */
function labelMemuat(html, untai) {
    for (const m of html.matchAll(/<label\b[^>]*>[\s\S]*?<\/label>/g)) {
        if (m[0].includes(untai)) return m[0];
    }

    return null;
}

(async () => {
    const html = await render({ employees: [{ id: 1, name: 'Eko Prasetyo', position: 'Staff' }] });

    console.log('\n=== 1. Bawaan pilihannya OFFLINE ===');

    const rOffline = inputBernilai(html, 'offline');
    const rOnline = inputBernilai(html, 'online');
    cek('kedua pilihan tersedia di layar', !!rOffline && !!rOnline);
    // SSR menandai radio terpilih dengan atribut checked. Inilah yang benar-benar dilihat orang
    // saat halaman terbuka, bukan sekadar nilai awal di dalam skripnya.
    cek('radio Offline TERPILIH saat halaman dibuka', !!rOffline && /\bchecked\b/.test(rOffline), rOffline || 'tidak ada');
    cek('radio Online TIDAK terpilih', !!rOnline && !/\bchecked\b/.test(rOnline), rOnline || 'tidak ada');

    console.log('\n=== 2. Kartu yang tersorot adalah kartu Offline ===');

    // Sorotan kartu memakai kelas border-primary-400/bg-primary-50. Diperiksa terpisah dari
    // atribut checked: radio yang benar di dalam kartu yang tidak tersorot tetap terbaca
    // "belum memilih apa-apa" oleh mata.
    const kOffline = labelMemuat(html, 'Persetujuan Offline');
    const kOnline = labelMemuat(html, 'Persetujuan Online');
    cek('kartu Offline tersorot', !!kOffline && kOffline.includes('border-primary-400'), kOffline ? 'tidak tersorot' : 'kartu tidak ada');
    cek('kartu Online tidak tersorot', !!kOnline && !kOnline.includes('border-primary-400'));

    console.log('\n=== 3. Akibat tiap pilihan tetap tertulis ===');

    cek('kartu Offline menyebut jenjang aplikasi dimatikan',
        !!kOffline && kOffline.includes('Jenjang di aplikasi dimatikan'));
    cek('kartu Offline menyebut pembayaran boleh tanpa menunggu',
        !!kOffline && kOffline.includes('tanpa menunggunya'));
    cek('kartu Online menyebut pembayaran terbuka setelah tanda tangan',
        !!kOnline && kOnline.includes('setelah seluruh tanda tangan terpenuhi'));

    console.log('\n=== 4. Online masih bisa dipilih (bukan sekadar dipajang) ===');

    cek('radio Online tidak disabled', !!rOnline && !/\bdisabled\b/.test(rOnline), rOnline || '');
    cek('radio Offline tidak disabled', !!rOffline && !/\bdisabled\b/.test(rOffline), rOffline || '');
    cek('keduanya terikat ke kolom yang sama (radio, bukan dua kotak terpisah)',
        !!rOnline && !!rOffline && rOnline.includes('type="radio"') && rOffline.includes('type="radio"'));

    console.log('\n=== 5. Nilai awalnya memang dari komponen, bukan dari stub ===');

    const src = fs.readFileSync(BERKAS, 'utf8');
    cek("useForm berbawaan approval_mode: 'offline'", /approval_mode:\s*'offline'/.test(src));
    cek("tidak ada lagi bawaan 'online' pada useForm", !/useForm[^\n]*approval_mode:\s*'online'/.test(src));

    console.log('\n=== HASIL ===');
    console.log('  lulus: ' + lulus + ' · gagal: ' + gagal);
    console.log(gagal === 0 ? '  SEMUA LULUS' : '  ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
