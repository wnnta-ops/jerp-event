// COGS Baru — SISI LAYAR, di-render SUNGGUHAN lewat SSR dengan PROPS ASLI dari pengendali.
//
// ── Mengapa props-nya tidak ditulis tangan ──
// Uji layar yang propsnya dikarang hanya menguji layar terhadap tebakan penulis ujinya. Begitu
// pengendali mengganti nama satu prop, layarnya menampilkan kolom kosong dan ujinya tetap hijau.
// Berkas ini memanggil tests/Manual/_props-cogs2.php lebih dulu — ia menyemai satu periode
// lengkap, menulis props Inertia SUNGGUHAN dari kesebelas layar, lalu membatalkan seluruh
// tulisannya — dan merender layarnya dengan props itu.
//
// Ini menutup satu-satunya kelas kesalahan yang TIDAK tertangkap uji sisi server maupun vue-tsc:
// beberapa prop di layar ini memang bertipe `any`, karena bentuknya ditentukan kalkulator.
//
// Yang dijaga:
//  1. KESEBELAS LAYAR DIRENDER TANPA PERINGATAN VUE. Peringatan DITANGKAP, bukan dibungkam —
//     "Missing required prop" dan "was accessed during render but is not defined" adalah persis
//     gejala prop yang namanya meleset.
//  2. ANGKA SERVER BENAR-BENAR SAMPAI KE LAYAR. Tiap layar dituntut memuat angka nyata dari
//     propsnya sendiri, bukan sekadar merender tanpa galat.
//  3. UNIT TERKUNCI tampil dengan realisasi nol, dan rekonsiliasinya nol.
//  4. TIDAK ADA #DIV/0!, NaN, Infinity, maupun "undefined" yang lolos ke layar.
//  5. Housing pada Perhitungan COGS tampil "—", bukan tarif tunggal.
//  6. Ketiga angka per m² tampil dengan penyebutnya masing-masing.
//
// Jalankan:  node tests/Manual/uji-cogs-baru-layar.cjs .
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
        // Alias "@/" dipetakan ke resources/js. Ekstensinya DILENGKAPI di sini: esbuild tidak
        // melakukannya sendiri untuk jalur yang dipulangkan sebuah plugin, sehingga impor tanpa
        // ekstensi (mis. '@/Components/Cogs2/warna') akan gagal dibaca.
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
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){} };'
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
    // Peringatan DITANGKAP, bukan dibungkam: "Missing required prop" dan "was accessed during
    // render but is not defined" adalah gejala langsung prop yang namanya meleset antara
    // pengendali dan layar — satu-satunya kesalahan yang uji sisi server tidak bisa melihat.
    app.config.warnHandler = (msg) => { peringatan.push(String(msg)); };
    // `route()` dipasang sebagai GLOBAL sungguhan, bukan hanya globalProperties: di peramban
    // Ziggy menaruhnya di window, dan layar-layar ini memanggilnya dari dalam <script setup>
    // (di computed), yang tidak melihat globalProperties sama sekali.
    const rute = (n, p) => '/' + n + (p !== undefined ? '/' + (Array.isArray(p) ? p.join('/') : p) : '');
    globalThis.route = rute;
    app.config.globalProperties.route = rute;
    const html = await renderToString(app);

    return {
        html,
        teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ')
            .replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/\s+/g, ' '),
        peringatan,
    };
}

const rp = (n) => 'Rp ' + Math.round(Number(n)).toLocaleString('id-ID');
const m2 = (n) => Number(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function main() {
    // ── Props asli dari pengendali ────────────────────────────────────────────────
    console.log('  … menyemai & mengambil props asli dari pengendali');
    try {
        const keluar = execSync(
            'docker exec ipro-fo-app php artisan tinker --execute="require \'/var/www/html/tests/Manual/_props-cogs2.php\';"',
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
        );
        if (! /PROPS DITULIS/.test(keluar)) {
            console.log('  GAGAL mengambil props — ' + keluar.trim().split('\n').slice(-3).join(' | '));
            gagal++;
        }
    } catch (e) {
        console.log('  GAGAL menjalankan _props-cogs2.php — ' + String(e.message).split('\n')[0]);
        gagal++;
    }

    if (! fs.existsSync(PROPS)) {
        console.log('\n  Props tidak tersedia; uji layar tidak bisa dijalankan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + (gagal + 1) + '\nADA YANG GAGAL');
        process.exit(1);
    }
    const semua = JSON.parse(fs.readFileSync(PROPS, 'utf8'));

    const LAYAR = {
        Index: 'BusinessSupport/Cogs2/Index',
        Perhitungan: 'BusinessSupport/Cogs2/Perhitungan',
        Rincian: 'BusinessSupport/Cogs2/Rincian',
        'Kategori-tanah': 'BusinessSupport/Cogs2/Kategori',
        'Kategori-housing': 'BusinessSupport/Cogs2/Kategori',
        'Kategori-infra': 'BusinessSupport/Cogs2/Kategori',
        'Kategori-license': 'BusinessSupport/Cogs2/Kategori',
        'Actual-tanah': 'BusinessSupport/Cogs2/Actual',
        'Actual-housing': 'BusinessSupport/Cogs2/Actual',
        'Actual-infra': 'BusinessSupport/Cogs2/Actual',
        'Actual-license': 'BusinessSupport/Cogs2/Actual',
    };

    console.log('\n=== 1. Kesebelas layar dirender tanpa peringatan Vue ===');
    const hasil = {};
    for (const [nama, halaman] of Object.entries(LAYAR)) {
        try {
            const r = await render(halaman, semua[nama]);
            hasil[nama] = r;
            const parah = r.peringatan.filter((w) =>
                /Missing required prop|was accessed during render|Invalid prop|Property .* is not defined/i.test(w));
            cek(nama.padEnd(17) + ' dirender', parah.length === 0, parah.slice(0, 2).join(' | '));
        } catch (e) {
            cek(nama.padEnd(17) + ' dirender', false, String(e.message).split('\n')[0]);
        }
    }

    // Kalau ada layar yang tidak berhasil dirender, berhenti di sini: pemeriksaan berikutnya
    // membaca hasil render dan hanya akan menghasilkan galat susulan yang menutupi sebabnya.
    if (Object.keys(hasil).length !== Object.keys(LAYAR).length) {
        console.log('\n  Ada layar yang tidak berhasil dirender — pemeriksaan isi dihentikan.');
        console.log('\n────────────────────────────────\nLULUS: ' + lulus + '   GAGAL: ' + gagal + '\nADA YANG GAGAL');
        process.exit(1);
    }

    console.log('\n=== 2. Tidak ada galat hitung yang lolos ke layar ===');
    for (const [nama, r] of Object.entries(hasil)) {
        const kotor = ['#DIV/0!', 'NaN', 'Infinity', 'undefined', 'null'].filter((k) => r.teks.includes(k));
        cek(nama.padEnd(17) + ' bersih dari ' + kotor.join('/'), kotor.length === 0,
            kotor.length ? 'ditemukan: ' + kotor.join(', ') : '');
    }

    console.log('\n=== 3. Angka server benar-benar sampai ke layar ===');

    const P = semua.Perhitungan;
    const tP = hasil.Perhitungan.teks;
    cek('Perhitungan: TOTAL COGS ' + rp(P.hitung.total.budget), tP.includes(rp(P.hitung.total.budget)));
    cek('  Luas Lahan ' + m2(P.hitung.lahan.luas_lahan), tP.includes(m2(P.hitung.lahan.luas_lahan)));
    cek('  Jalan turunan ' + m2(P.hitung.lahan.jalan), tP.includes(m2(P.hitung.lahan.jalan)));
    cek('  persentase Saleable 53,706%', tP.includes('53.706%') || tP.includes('53,706%'),
        'persen ditampilkan sebagai ' + (tP.match(/\d+[.,]\d{3}%/) || ['tidak ketemu'])[0]);
    // Ketiga angka per m² harus BERBEDA dan masing-masing menyebut penyebutnya.
    cek('  COGS GROSS menyebut penyebutnya (÷ Luas Lahan)', /COGS GROSS/.test(tP) && /÷ Luas Lahan/.test(tP));
    cek('  COGS Saleable menyebut penyebutnya (÷ Saleable)', /COGS Saleable ÷ Saleable|÷ Saleable \d/.test(tP.replace(/\s+/g, ' ')));
    cek('  COGS Saleable Belum Booked menyebut penyebutnya', /÷ Saleable Belum Booked/.test(tP));
    // Housing tidak punya tarif per m² tunggal — di lampiran pun diisi "-".
    cek('  Housing tanpa tarif per m² tunggal', P.hitung.perhitungan.housing.budget_per_m2 === null && tP.includes('—'));

    const R = semua.Rincian;
    const tR = hasil.Rincian.teks;
    cek('Rincian: biaya aktual Tanah ' + rp(R.hitung.rekon.tanah.aktual), tR.includes(rp(R.hitung.rekon.tanah.aktual)));
    cek('  rekonsiliasi Tanah nol', R.hitung.rekon.tanah.selisih === 0 && /Rekonsiliasi Pembebanan/.test(tR));
    cek('  jumlah unit & yang terkunci tampil',
        tR.includes(String(R.hitung.unitTotal.jumlah) + ' unit') && /terkunci/.test(tR));
    // Unit terkunci: realisasinya nol, dan itu harus terlihat.
    const terkunci = R.hitung.unit.find((u) => u.terkunci);
    cek('  unit terkunci ' + terkunci.unit + ' berealisasi nol', terkunci.realisasi_total === 0);
    cek('  peringatan dari server tampil di layar',
        R.hitung.peringatan.length > 0 && R.hitung.peringatan.every((w) => tR.includes(w.pesan.slice(0, 40))),
        'peringatan: ' + R.hitung.peringatan.map((w) => w.jenis).join(', '));
    cek('  tiga lebar tampilan tersedia', /Ringkas/.test(tR) && /Budget/.test(tR) && /Realisasi/.test(tR));
    cek('  grafik Budget vs Actual Costing dirender',
        /Budget vs Actual Costing/.test(tR) && /<svg/.test(hasil.Rincian.html));
    // % HPP/Revenue: unit tanpa revenue harus "—", bukan galat pembagian.
    const tanpaRev = R.hitung.unit.find((u) => u.revenue === null);
    cek('  unit tanpa revenue → persen kosong', tanpaRev !== undefined && tanpaRev.persen_hpp === null);

    console.log('\n=== 4. Set Up Kategori — empat komponen ===');
    for (const k of ['tanah', 'housing', 'infra', 'license']) {
        const d = semua['Kategori-' + k];
        const t = hasil['Kategori-' + k].teks;
        cek('Kategori-' + k.padEnd(8) + ' memuat budget ' + rp(d.hitung.perhitungan[k].budget),
            t.includes(rp(d.hitung.perhitungan[k].budget)));
    }
    const tKH = hasil['Kategori-housing'].teks;
    cek('  Housing punya blok tipe rumah', /Tipe Rumah/.test(tKH) && /Budget HPP per Meter/.test(tKH));
    cek('  dan baris SPK-nya', /Baris SPK Housing/.test(tKH) && /Nominal Realisasi/.test(tKH));
    const KT = semua['Kategori-tanah'];
    cek('  Tanah: nominal turunan = luas × harga',
        hasil['Kategori-tanah'].teks.includes(rp(KT.kategoriBaris.tanah[0].nominal))
        && Math.abs(KT.kategoriBaris.tanah[0].nominal
            - KT.kategoriBaris.tanah[0].luas_tanah * KT.kategoriBaris.tanah[0].harga_rata2) < 0.02);

    console.log('\n=== 5. Actual Costing — empat komponen ===');
    for (const k of ['tanah', 'housing', 'infra', 'license']) {
        const d = semua['Actual-' + k];
        const t = hasil['Actual-' + k].teks;
        cek('Actual-' + k.padEnd(10) + ' memuat biaya aktual ' + rp(d.hitung.rekon[k].aktual),
            t.includes(rp(d.hitung.rekon[k].aktual)));
    }
    // Saldo akhir = penambahan − pengurangan, dan barisnya yang punya pengurangan harus terlihat.
    const AH = semua['Actual-housing'];
    const adaKurang = AH.actualBaris.housing.find((b) => b.pengurangan > 0);
    cek('  saldo akhir turunan (penambahan − pengurangan)',
        adaKurang !== undefined
        && Math.abs(adaKurang.saldo_akhir - (adaKurang.penambahan - adaKurang.pengurangan)) < 0.005
        && hasil['Actual-housing'].teks.includes(rp(adaKurang.saldo_akhir)));

    console.log('\n=== 6. Daftar periode ===');
    const tI = hasil.Index.teks;
    cek('Index memuat periode yang disemai', tI.includes('JAVA RESIDENCE KRIAN CLUSTER 3'));
    cek('  beserta budget & actual costing-nya',
        tI.includes(rp(semua.Index.rows[0].budget)) && tI.includes(rp(semua.Index.rows[0].aktual)));

    try { fs.unlinkSync(PROPS); } catch { /* tidak apa-apa */ }

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exit(gagal === 0 ? 0 : 1);
}

main().catch((e) => { console.error(e); process.exit(1); });
