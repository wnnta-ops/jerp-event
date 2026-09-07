// Render Gl/Journals/Form.vue YANG SEBENARNYA dan periksa lebar kolomnya.
//
// KENAPA PERLU UJI: kolom yang terlalu sempit tidak menimbulkan galat apa pun. Ia hanya
// memotong isinya — "BELI KE" alih-alih "BELI KERAMIK", dan dua angka total yang menempel
// jadi "1.127.5001.127.500" yang terbaca sebagai satu bilangan. Nilainya tetap benar
// tersimpan; yang salah hanya apa yang bisa dibaca orang.
//
// Yang diukur BUKAN "ada kelas w-44", melainkan ANGGARAN LEBARNYA: jumlah lebar minimum
// seluruh kolom harus muat di dalam lebar halaman. Itulah yang dilanggar sebelum ini —
// halaman max-w-4xl (896px) sementara kolomnya menuntut lebih dari 1000px, sehingga tiga
// kolom terakhir yang diperas.
//
// Jalankan:  node tests/Manual/uji-jurnal-lebar-kolom.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/Journals/Form.vue');

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
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                  // useForm dipalsukan seperlunya: yang diuji tata letaknya, bukan pengirimannya.
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {},'
                  + '   post(){}, put(){}, transform(){ return this; } });'
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

// Lebar kelas Tailwind yang dipakai halaman ini, dalam piksel.
const SKALA = { 36: 144, 40: 160, 44: 176, 48: 192 };
const MAXW = { '3xl': 768, '4xl': 896, '5xl': 1024, '6xl': 1152, '7xl': 1280 };

/** Lebar minimum sebuah sel dari kelasnya: min-w-[NNNpx] atau w-NN. */
function lebarSel(kelas) {
    const min = kelas.match(/min-w-\[(\d+)px\]/);
    if (min) return Number(min[1]);
    const w = kelas.match(/(?:^|\s)w-(\d+)(?:\s|$)/);
    if (w && SKALA[Number(w[1])]) return SKALA[Number(w[1])];

    return 0;
}

const LINES = [
    { account_id: 1, cost_center_id: 1, description: 'BELI KERAMIK 20 BIJI', debit: 1000000, credit: 0 },
    { account_id: 2, cost_center_id: null, description: 'PPN Masukan atas pembelian', debit: 110000, credit: 0 },
    { account_id: 3, cost_center_id: null, description: 'Hutang PPh Pasal 4 ayat (2)', debit: 0, credit: 17500 },
];

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const app = createSSRApp({
        render: () => h(modul.exports.default, {
            accounts: [{ id: 1, code: '20107070706', name: 'PENYELESAIAN BIAYA KONSTRUKSI JRK CLUSTER 3 KRIAN' },
                { id: 2, code: '10105010101', name: 'PPN MASUKAN' },
                { id: 3, code: '20104040401', name: 'HUTANG PPH PASAL 4 AYAT (2)' }],
            costCenters: [{ id: 1, code: 'CC-FIN', name: 'Finance & Accounting' }],
            editing: { id: 1, journal_number: 'AP.INV/KONSTRUKSI-INFRA-C3/MUP/26/VII/00004', source: 'ap',
                journal_date: '2026-07-05', description: 'AP — BELI KERAMIK 20 BIJI', reference: 'AP.INV/KONSTRUKSI-INFRA-C3/MUP/26/VII/00004',
                cost_center_id: null, created_by_name: 'Super Admin', lines: LINES },
            optionalCcAccounts: [2, 3],
        }),
    });
    app.config.globalProperties.route = (n, p) => '/' + n + (p ? '/' + JSON.stringify(p) : '');
    globalThis.route = app.config.globalProperties.route;
    // $page adalah properti global Inertia; templatnya membacanya ($page.props.flash?.error).
    app.config.globalProperties.$page = { props: { flash: {} } };
    const html = (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
    const src = fs.readFileSync(BERKAS, 'utf8');

    if (process.env.DUMP) { fs.writeFileSync(process.env.DUMP, html); }

    console.log('\n=== 1. Anggaran lebar: kolomnya muat di halamannya ===');

    // Lebar halaman.
    const mw = (src.match(/<div class="max-w-(\w+) space-y-4">/) || [])[1];
    cek('lebar halaman dinyatakan', mw !== undefined && MAXW[mw] !== undefined, String(mw));
    const lebarHalaman = MAXW[mw] ?? 0;

    // Lebar minimum tabelnya sendiri.
    const minTabel = Number((src.match(/<table class="w-full min-w-\[(\d+)px\] text-sm">/) || [])[1] || 0);
    cek('tabel menyatakan lebar minimum', minTabel > 0, String(minTabel));

    // Jumlah lebar minimum seluruh sel pada satu baris data.
    // Baris data diambil dari dalam <tbody> — bukan dari kelas barisnya. Sejak tata letaknya
    // menyalin kartu Struktur Jurnal, pemisah antarbaris memakai divide-y pada <tbody> dan
    // barisnya tidak lagi berkelas border-t; mencocokkan kelas membuat uji ini diam-diam
    // mengukur baris Grand Total di kaki tabel — dan lulus atas kolom yang salah.
    const isiTbody = (html.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/) || ['', ''])[1];
    const barisData = (isiTbody.match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];
    const sel = [...barisData.matchAll(/<td class="([^"]*)"/g)].map((m) => m[1]);
    cek('baris datanya ter-render', sel.length >= 5, sel.length + ' sel');
    const jumlah = sel.reduce((s, k) => s + lebarSel(k), 0);

    // INILAH yang dilanggar sebelumnya: kolomnya menuntut lebih dari lebar halamannya,
    // jadi browser memeras yang tidak punya lebar minimum — Keterangan, Debit, Kredit.
    cek('jumlah lebar minimum kolom muat di lebar halaman', jumlah <= lebarHalaman,
        jumlah + 'px kolom vs ' + lebarHalaman + 'px halaman');
    cek('lebar minimum tabel menampung kolomnya', minTabel >= jumlah,
        minTabel + 'px vs ' + jumlah + 'px');

    console.log('\n=== 2. Ketiga kolom yang dulu terpotong punya lebar sendiri ===');

    // Akun & Cost Center sudah punya sejak awal; ketiga sisanya yang tidak, dan justru
    // merekalah yang diperas sampai isinya terpotong.
    // Judul kolomnya kini mengikuti kartu Struktur Jurnal pada layar detail.
    const namaKolom = ['Account', 'Dimensi', 'Keterangan', 'Debit', 'Kredit'];
    sel.slice(0, 5).forEach((k, i) => {
        cek('kolom ' + namaKolom[i] + ' punya lebar minimum', lebarSel(k) > 0, k);
    });
    cek('Keterangan minimal 220px', lebarSel(sel[2]) >= 220, lebarSel(sel[2]) + 'px');
    cek('Debit minimal 160px', lebarSel(sel[3]) >= 160, lebarSel(sel[3]) + 'px');
    cek('Kredit minimal 160px', lebarSel(sel[4]) >= 160, lebarSel(sel[4]) + 'px');

    console.log('\n=== 3. Kepala kolom selebar selnya ===');

    // Kepala yang lebih sempit dari selnya membuat tabel bergeser sendiri saat dirender.
    const th = [...html.matchAll(/<th class="([^"]*)"[^>]*>([^<]*)<\/th>/g)].map((m) => ({ k: m[1], t: m[2].trim() }));
    for (const nama of ['Keterangan', 'Debit', 'Kredit']) {
        const h = th.find((x) => x.t === nama);
        const iSel = namaKolom.indexOf(nama);
        cek('kepala ' + nama + ' selebar selnya', h !== undefined && lebarSel(h.k) >= lebarSel(sel[iSel]),
            h ? lebarSel(h.k) + 'px vs ' + lebarSel(sel[iSel]) + 'px' : 'kepala tidak ditemukan');
    }

    console.log('\n=== 4. Layar sempit MENGGESER, bukan memotong ===');

    // Tanpa pembungkus ini, layar yang lebih sempit dari min-w tabel akan memotong lagi —
    // masalah yang sama persis, hanya berpindah ke laptop kecil.
    cek('tabelnya dibungkus wadah yang bisa digeser', src.includes('<div class="overflow-x-auto">'),
        'tidak ada overflow-x-auto');
    const iWadah = src.indexOf('<div class="overflow-x-auto">');
    const iTabel = src.indexOf('<table class="w-full min-w-');
    cek('wadahnya membungkus tabel jurnal', iWadah > 0 && iTabel > iWadah, 'urutannya keliru');

    console.log('\n=== 5. Dua total tidak lagi menempel ===');

    // Di layar lama keduanya terbaca "1.127.5001.127.500" — satu bilangan, bukan dua.
    const tfoot = (html.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    const selTot = [...tfoot.matchAll(/<td class="([^"]*)"[^>]*>([\s\S]*?)<\/td>/g)]
        .map((m) => ({ k: m[1], t: m[2].replace(/<[^>]+>/g, '').trim() }));
    const angka = selTot.filter((x) => /^[\d.]+$/.test(x.t) && x.t !== '');
    cek('kedua total ter-render sebagai dua sel terpisah', angka.length === 2,
        JSON.stringify(selTot.map((x) => x.t)));
    // Yang menjaga keduanya tidak menempel ada dua hal, dan keduanya diperiksa apa adanya:
    // JARAK MENDATAR pada selnya (px-/pr-), dan LEBAR KOLOMNYA — yang sejak tata letaknya
    // menyalin kartu Struktur Jurnal ditetapkan di kepala kolom, bukan lagi diulang di tiap
    // sel total. Memeriksa kelas sel totalnya saja akan menuntut bentuk lama tanpa alasan.
    const lebarKolomAngka = [...html.matchAll(/<th class="([^"]*)"[^>]*>\s*(Debit|Kredit)\s*<\/th>/g)]
        .map((m) => lebarSel(m[1]));
    angka.forEach((a, i) => {
        cek('total ke-' + (i + 1) + ' berjarak mendatar', /(?:^|\s)(px-|pr-)\d/.test(a.k), a.k);
        cek('kolom total ke-' + (i + 1) + ' selebar minimal 160px', (lebarKolomAngka[i] ?? 0) >= 160,
            (lebarKolomAngka[i] ?? 0) + 'px');
    });
    // Angka rata kanan tanpa lebar tetap akan "melompat" saat digit bertambah.
    cek('totalnya memakai angka berlebar tetap', angka.every((a) => a.k.includes('tabular-nums')),
        angka.map((a) => a.k).join(' | '));

    console.log('\n=== 6. Isi barisnya tidak berubah ===');

    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
    cek('judulnya masih Edit Jurnal', teks.includes('Edit Jurnal AP.INV/KONSTRUKSI-INFRA-C3/MUP/26/VII/00004'),
        'tidak ditemukan');
    cek('peringatan jurnal dari modul AP masih tampil', teks.includes('berasal dari modul'), 'tidak ditemukan');
    cek('tombol tambah baris masih ada', teks.includes('Baris'), 'tidak ditemukan');
    cek('penanda balance masih ada', teks.includes('Balance') || teks.includes('balance'), 'tidak ditemukan');
    cek('tiga baris jurnal ter-render',
        (isiTbody.match(/<tr[^>]*>/g) || []).length === LINES.length,
        String((isiTbody.match(/<tr[^>]*>/g) || []).length));

    console.log('\n=== 7. Detail Transaksi Jurnal: colgroup vs lebar tabel ===');

    // Layar DAFTAR jurnal punya penyakit yang sama, dengan sebab yang lebih tajam: seluruh
    // lebarnya dinyatakan di <colgroup>, dan bila jumlahnya MELEBIHI min-w tabelnya, kolom
    // yang tidak menyatakan lebar menanggung seluruh kekurangan itu — tergencet sampai nyaris
    // nol. Dipadu break-words, isinya tercetak satu huruf per baris.
    //
    // Diukur dari sumbernya, bukan lewat render: lebar-lebar itu memang tertulis statis di
    // markup, dan itulah tepat yang salah.
    const daftar = fs.readFileSync(path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/Journals/Index.vue'), 'utf8');

    const rem = (s) => {
        const m = s.match(/w-\[([\d.]+)rem\]/);
        if (m) return Number(m[1]) * 16;
        const n = s.match(/(?:^|\s)w-(\d+)(?:\s|$)/);

        return n ? Number(n[1]) * 4 : 0;   // skala Tailwind: w-10 = 2.5rem = 40px
    };

    const blokCol = (daftar.match(/<colgroup>[\s\S]*?<\/colgroup>/) || [''])[0];
    const kolom = [...blokCol.matchAll(/<col(\s+class="([^"]*)")?\s*\/>/g)]
        .map((m) => ({ kelas: m[2] ?? '', px: rem(m[2] ?? '') }));

    cek('colgroup terbaca', kolom.length > 0, kolom.length + ' kolom');
    // Kolom TANPA lebar adalah yang menanggung kekurangannya — pada tabel selebar ini,
    // "sisa ruang" bukan jaminan ruang yang cukup.
    const tanpaLebar = kolom.filter((k) => k.px === 0);
    cek('tidak ada kolom tanpa lebar', tanpaLebar.length === 0,
        tanpaLebar.length + ' kolom tanpa lebar — merekalah yang akan tergencet');

    const totalKolom = kolom.reduce((s, k) => s + k.px, 0);
    const minTabelDaftar = (() => {
        const m = daftar.match(/<table class="w-full min-w-\[([\d.]+)rem\] table-fixed text-sm">/);

        return m ? Number(m[1]) * 16 : 0;
    })();
    cek('tabel daftar menyatakan lebar minimum', minTabelDaftar > 0, String(minTabelDaftar));
    cek('lebar minimum tabel >= jumlah kolomnya', minTabelDaftar >= totalKolom,
        minTabelDaftar + 'px tabel vs ' + totalKolom + 'px kolom');

    // Dua kolom teks terpanjang harus benar-benar lapang, bukan sekadar bukan-nol.
    const iNamaAkun = 10;          // urutan colgroup: 0 centang, 1 buka, 2..9, 10 Nama Akun, 11 Ket. Baris
    const iKetBaris = 11;
    cek('kolom Nama Akun minimal 240px', (kolom[iNamaAkun]?.px ?? 0) >= 240,
        (kolom[iNamaAkun]?.px ?? 0) + 'px');
    cek('kolom Keterangan Baris minimal 240px', (kolom[iKetBaris]?.px ?? 0) >= 240,
        (kolom[iKetBaris]?.px ?? 0) + 'px');
    cek('kartunya bisa digeser mendatar', daftar.includes('card overflow-x-auto'),
        'tanpa overflow-x-auto, layar sempit akan menggencet lagi');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
