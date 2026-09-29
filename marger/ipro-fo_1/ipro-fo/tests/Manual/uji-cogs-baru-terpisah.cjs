// COGS Baru — TIAP SUB-MODUL BERDIRI SEBAGAI LAYARNYA SENDIRI.
//
// Sebelumnya keempat sub-modul berbagi satu kepala dengan deretan tab, sehingga Perhitungan COGS,
// Set Up Kategori COGS, Actual Costing COGS, dan Rincian COGS terlihat seperti EMPAT TAB DARI SATU
// HALAMAN — padahal di sidebar keempatnya empat butir menu yang berbeda. Berkas ini menjaga bahwa
// pemisahannya benar-benar terjadi di layar, dan tetap terjadi.
//
// Layarnya di-render SUNGGUHAN lewat SSR dengan PROPS ASLI dari pengendali (tests/Manual/
// _props-cogs2.php), bukan props karangan: props karangan hanya menguji layar terhadap tebakan
// penulis ujinya.
//
// Yang dijaga, berurut dari yang paling mahal:
//
//  1. JUDUL DI LAYAR = LABEL DI SIDEBAR, dibaca dari navGroups.ts, bukan ditulis ulang di sini.
//     Layar yang judulnya berbeda dari butir menu yang diklik membuat orang mengira ia salah
//     membuka — atau, lebih buruk, mengira ia sudah membuka padahal belum.
//  2. TIDAK ADA LAGI TAUTAN ANTAR SUB-MODUL di badan layar. Satu pun tautan bernama sub-modul
//     lain mengembalikan kesan "empat tab satu halaman" yang justru diminta hilang.
//  3. IDENTITAS PERIODE TETAP ADA DI TIAP LAYAR. Layar yang berdiri sendiri tanpa menyebut
//     lokasi & tahapnya membuat orang menyunting periode yang salah tanpa satu pun galat.
//  4. PEMINDAH KOMPONEN HANYA DI DUA SUB-MODUL yang butir menunya memang berbutir komponen.
//  5. WARNA KEPALA MENGIKUTI KOMPONENNYA, bukan rona karangan per sub-modul — warna di modul ini
//     menandai identitas Tanah/Housing/Infra/License dan tidak boleh berpindah arti.
//
// Jalankan:  node tests/Manual/uji-cogs-baru-terpisah.cjs .
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const PROPS = path.join(ROOT, 'storage/app/_props-cogs2.json');

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
            for (const akhiran of ['', '.ts', '.js', '.vue', '/index.ts', '/index.js']) {
                const coba = dasar + akhiran;
                if (fs.existsSync(coba) && fs.statSync(coba).isFile()) return { path: coba };
            }

            return { path: dasar };
        });
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){}, reload(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  // <Head> DIRENDER sebagai penanda, bukan dibuang: judul tab peramban adalah
                  // bagian dari identitas sub-modul yang sedang diperiksa di sini.
                  + " export const Head = { props: ['title'], setup: (p) => () => h('meta', { 'data-judul': p.title }) };"
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

async function render(halaman, props) {
    const berkas = path.join(ROOT, 'resources/js/Pages', halaman + '.vue');
    const hasil = await esbuild.build({
        entryPoints: [berkas], bundle: true, write: false, format: 'cjs', platform: 'node',
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

    return { html, teks: bersih(html), peringatan };
}

const bersih = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ')
    .replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/\s+/g, ' ').trim();

/** Teks setiap <a> di halaman — inilah yang dilihat orang sebagai "tautan ke mana". */
function tautan(html) {
    const keluar = [];
    for (const m of html.matchAll(/<a\b[^>]*>([\s\S]*?)<\/a>/g)) keluar.push(bersih(m[1]));

    return keluar;
}

const judulH1 = (html) => {
    const m = html.match(/<h1\b[^>]*>([\s\S]*?)<\/h1>/);

    return m ? bersih(m[1]) : null;
};
const judulTab = (html) => {
    const m = html.match(/<meta data-judul="([^"]*)"/);

    return m ? m[1].replace(/&amp;/g, '&') : null;
};

async function main() {
    // ── 0. Label sub-modul DIBACA DARI SIDEBAR, bukan ditulis ulang di sini ───────────
    // Kalau ujinya menyalin sendiri nama-namanya, ia hanya membandingkan layar dengan salinan
    // ujinya — dan sidebar boleh berubah diam-diam tanpa satu pun uji yang merah.
    const nav = fs.readFileSync(path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts'), 'utf8');
    const menu = [];
    for (const m of nav.matchAll(
        /\{\s*label:\s*'([^']+)',\s*href:\s*'bs\.cogs2\.buka',\s*params:\s*\{\s*layar:\s*'(\w+)'(?:\s*,\s*komponen:\s*'(\w+)')?\s*\}\s*\}/g)) {
        menu.push({ label: m[1], layar: m[2], komponen: m[3] || null });
    }

    console.log('=== 0. Butir menu COGS Baru di sidebar ===');
    cek('sepuluh butir menu terbaca dari navGroups.ts (1 + 4 + 4 + 1)', menu.length === 10,
        'terbaca ' + menu.length + ': ' + menu.map((x) => x.label).join(', '));
    if (menu.length !== 10) {
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + gagal + '\nADA YANG GAGAL');
        process.exit(1);
    }

    /** Nama keempat sub-modulnya, yaitu label menu tanpa akhiran komponen. */
    const NAMA_SUBMODUL = [...new Set(menu.map((x) => (x.komponen ? x.label.replace(/-[^-]+$/, '') : x.label)))];
    cek('  keempat nama sub-modulnya berbeda satu sama lain', NAMA_SUBMODUL.length === 4,
        NAMA_SUBMODUL.join(' | '));

    /** Kunci layar di berkas props ← butir menunya. */
    const kunci = (x) => (x.layar === 'perhitungan' ? 'Perhitungan'
        : x.layar === 'rincian' ? 'Rincian'
            : (x.layar === 'kategori' ? 'Kategori' : 'Actual') + '-' + x.komponen);
    const HALAMAN = (x) => 'BusinessSupport/Cogs2/'
        + (x.layar === 'perhitungan' ? 'Perhitungan' : x.layar === 'rincian' ? 'Rincian'
            : x.layar === 'kategori' ? 'Kategori' : 'Actual');

    // ── Props asli dari pengendali ────────────────────────────────────────────────
    console.log('\n  … menyemai & mengambil props asli dari pengendali');
    try {
        const keluar = execSync(
            'docker exec ipro-fo-app php artisan tinker --execute="require \'/var/www/html/tests/Manual/_props-cogs2.php\';"',
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        );
        if (! /PROPS DITULIS/.test(keluar)) {
            cek('props asli terambil', false, keluar.trim().split('\n').slice(-3).join(' | '));
        }
    } catch (e) {
        cek('props asli terambil', false, String(e.message).split('\n')[0]);
    }
    if (! fs.existsSync(PROPS)) {
        console.log('\n  Props tidak tersedia; uji layar tidak bisa dijalankan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + (gagal + 1) + '\nADA YANG GAGAL');
        process.exit(1);
    }
    const semua = JSON.parse(fs.readFileSync(PROPS, 'utf8'));

    console.log('\n=== 1. Kesepuluh layar sub-modul dirender tanpa peringatan Vue ===');
    const hasil = {};
    for (const x of menu) {
        try {
            const r = await render(HALAMAN(x), semua[kunci(x)]);
            hasil[kunci(x)] = r;
            const parah = r.peringatan.filter((w) =>
                /Missing required prop|was accessed during render|Invalid prop|Property .* is not defined/i.test(w));
            cek(x.label.padEnd(30) + ' dirender', parah.length === 0, parah.slice(0, 2).join(' | '));
        } catch (e) {
            cek(x.label.padEnd(30) + ' dirender', false, String(e.message).split('\n')[0]);
        }
    }
    if (Object.keys(hasil).length !== menu.length) {
        console.log('\n  Ada layar yang tidak berhasil dirender — pemeriksaan isi dihentikan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + gagal + '\nADA YANG GAGAL');
        process.exit(1);
    }

    console.log('\n=== 2. Tiap layar menyebut NAMANYA SENDIRI, sama persis dengan butir menunya ===');
    for (const x of menu) {
        const h1 = judulH1(hasil[kunci(x)].html);
        cek('judul layar = "' + x.label + '"', h1 === x.label, 'judul di layar: ' + JSON.stringify(h1));
    }

    console.log('\n=== 3. Judul tab peramban juga bernama sub-modulnya ===');
    for (const x of menu) {
        const t = judulTab(hasil[kunci(x)].html);
        cek('judul tab = "' + x.label + '"', t === x.label, 'judul tab: ' + JSON.stringify(t));
    }

    console.log('\n=== 4. Tidak ada lagi tautan ke sub-modul lain di badan layar ===');
    for (const x of menu) {
        const sendiri = x.komponen ? x.label.replace(/-[^-]+$/, '') : x.label;
        const lain = NAMA_SUBMODUL.filter((n) => n !== sendiri);
        const t = tautan(hasil[kunci(x)].html);
        const nyasar = t.filter((teks) => lain.includes(teks) || NAMA_SUBMODUL.includes(teks));
        cek(x.label.padEnd(30) + ' tanpa tab silang', nyasar.length === 0,
            'masih ada tautan bernama: ' + nyasar.join(', '));
    }

    console.log('\n=== 5. Identitas periode tetap terbaca di tiap layar ===');
    const P = semua.Perhitungan.periode;
    for (const x of menu) {
        const t = hasil[kunci(x)].teks;
        cek(x.label.padEnd(30) + ' menyebut lokasi & tahapnya',
            t.includes(P.lokasi_kode) && t.includes(P.lokasi_nama) && t.includes('Tahap ' + P.tahap),
            'kode/nama/tahap tidak lengkap di layar');
    }
    for (const x of menu) {
        cek(x.label.padEnd(30) + ' punya jalan ganti periode',
            tautan(hasil[kunci(x)].html).includes('Ganti periode'));
    }

    console.log('\n=== 6. Pemindah komponen hanya pada sub-modul yang berbutir komponen ===');
    for (const x of menu) {
        const ada = /aria-label="Komponen COGS"/.test(hasil[kunci(x)].html);
        cek(x.label.padEnd(30) + (x.komponen ? ' punya pemindah komponen' : ' TIDAK punya pemindah komponen'),
            ada === Boolean(x.komponen));
    }

    console.log('\n=== 7. Warna kepala mengikuti komponennya, bukan rona per sub-modul ===');
    // Diperiksa HANYA di dalam kepalanya (potongan sampai sebelum </h1>). Menghitung warna di
    // seluruh halaman tidak membuktikan apa pun: badan layar Set Up Kategori & Actual Costing
    // memang sudah memakai warna komponennya sendiri, sehingga lencana kepala boleh kehilangan
    // warnanya tanpa satu pun angka berubah.
    for (const x of menu.filter((m) => m.komponen)) {
        const kepala = judulKepala(hasil[kunci(x)].html);
        cek(x.label.padEnd(30) + ' lencana kepalanya berwarna ' + x.komponen,
            kepala.includes('background-color:var(--c2-' + x.komponen + ')'),
            'warna di kepala: ' + (kepala.match(/background-color:[^;"]+/g) || []).join(', '));
    }
    for (const x of menu.filter((m) => ! m.komponen)) {
        const kepala = judulKepala(hasil[kunci(x)].html);
        cek(x.label.padEnd(30) + ' memakai warna modul, bukan warna komponen',
            kepala.includes('background-color:#0ea5e9')
            && ! /background-color:var\(--c2-(tanah|housing|infra|license)\)/.test(kepala),
            'warna di kepala: ' + (kepala.match(/background-color:[^;"]+/g) || []).join(', '));
    }

    console.log('\n=== 8. Tidak ada galat hitung yang lolos ke layar ===');
    for (const x of menu) {
        const t = hasil[kunci(x)].teks;
        cek(x.label.padEnd(30) + ' bersih dari NaN/undefined/#DIV/0!',
            ! /\bNaN\b|\bundefined\b|#DIV\/0!|\bInfinity\b/.test(t),
            (t.match(/\bNaN\b|\bundefined\b|#DIV\/0!|\bInfinity\b/g) || []).slice(0, 3).join(', '));
    }

    try { fs.unlinkSync(PROPS); } catch { /* tidak apa-apa */ }

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exit(gagal === 0 ? 0 : 1);
}

/** Potongan HTML sampai sebelum baris konteks periode — di situlah lencana ikon kepala berada. */
function judulKepala(html) {
    const i = html.indexOf('</h1>');

    return i === -1 ? html : html.slice(0, i);
}

main().catch((e) => { console.error(e); process.exit(1); });
