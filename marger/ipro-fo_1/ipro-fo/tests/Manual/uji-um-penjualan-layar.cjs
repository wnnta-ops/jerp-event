// Render UmPenjualan.vue YANG SEBENARNYA dan periksa sambungannya ke buku besar.
//
// Uji sisi server di sebelah (uji-um-penjualan-buku-besar.php) membuktikan CONTROLLERNYA
// mengirim seluruh baris buku besar. Ia tidak bisa melihat apakah layarnya menampilkannya —
// dan itu bukan kekhawatiran teoretis: prop 'gl' sempat sudah dikirim server sementara
// layarnya belum membacanya sama sekali, sehingga dari layar tidak ada bedanya dengan
// sebelum apa pun dikerjakan. Tidak ada galat, tidak ada tanda.
//
// Diperiksa juga KEUTUHAN TABEL kedua tabelnya: tiap baris menempati lebar yang sama persis.
// Kolom yang bergeser tidak merusak tabelnya — ia hanya menaruh kredit di bawah judul Debit.
//
// Jalankan:  node tests/Manual/uji-um-penjualan-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Receivable/UmPenjualan.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        // Alias '@/' → resources/js. Ekstensinya harus dicoba sendiri: layar ini mengimpor
        // composable ".ts" tanpa menyebut ekstensinya, dan esbuild tidak menebaknya untuk
        // path yang sudah kita petakan jadi absolut.
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

// ── Data uji ───────────────────────────────────────────────────────────────────────────────
// Cerminan kasus nyata: satu baris antrean yang jurnalnya menyentuh akun UM, satu yang TIDAK
// (kreditnya ke Piutang Usaha), dan satu retensi DAJAM yang justru MENGURANGI akun UM.
const ANTREAN = [
    {
        row_key: 'utj_dp-7', source_type: 'utj_dp', source_label: 'Approval UM Penjualan (UTJ/DP)', source_id: 7,
        booking_id: 1, customer_id: 11, customer: 'Roronoa Zorro', spr: 'SPR-0001', unit: 'JRK3-004-XV-12A',
        doc_date: '2026-03-15', journal_id: 901, journal_number: 'JKB/2603/0001', kpr_number: null,
        note: 'UTJ awal', dpp: 100000000, ppn: 11000000, ppn_pasti: true, total: 111000000,
        gl_net: 100000000, gl_ada: true,
    },
    {
        row_key: 'pencairan-3', source_type: 'pencairan', source_label: 'Approval Retensi — Pencairan KPR', source_id: 3,
        booking_id: 2, customer_id: 12, customer: 'Siti Aminah', spr: 'SPR-0002', unit: 'JRK3-004-XV-3A',
        doc_date: '2026-03-20', journal_id: 904, journal_number: 'JKB/2603/0004', kpr_number: 'KPR/JRES/2603/00004',
        note: null, dpp: 360360360, ppn: 39639640, ppn_pasti: false, total: 400000000,
        // Jurnalnya kredit ke Piutang Usaha — tidak menyentuh akun UM sama sekali.
        gl_net: null, gl_ada: false,
    },
    {
        row_key: 'retensi-5', source_type: 'retensi', source_label: 'Approval Retensi — DAJAM', source_id: 5,
        booking_id: 1, customer_id: 11, customer: 'Roronoa Zorro', spr: 'SPR-0001', unit: 'JRK3-004-XV-12A',
        doc_date: '2026-03-18', journal_id: 903, journal_number: 'JKB/2603/0003', kpr_number: 'KPR/JRES/2603/00003',
        note: 'Retensi ditahan', dpp: 45045045, ppn: 4954955, ppn_pasti: false, total: 50000000,
        // Dr Uang Muka / Cr Bank — arah BERLAWANAN dengan nilai antreannya.
        gl_net: -50000000, gl_ada: true,
    },
];

const gl = (lineId, journalId, no, tgl, status, statusLabel, debit, credit, docLabel, docNo, cust, queueKey) => ({
    line_id: lineId, journal_id: journalId, journal_number: no, journal_date: tgl,
    journal_status: status, status_label: statusLabel, journal_source: 'memorial',
    reference: 'REF-' + no, account_code: '20106060601', account_name: 'UANG MUKA PENJUALAN',
    description: 'Baris ' + no, debit, credit, net: credit - debit, arah: credit >= debit ? 'K' : 'D',
    doc_type: docLabel ? 'x' : null, doc_label: docLabel, doc_number: docNo,
    customer_id: cust ? 11 : null, customer: cust, spr: cust ? 'SPR-0001' : null,
    unit: cust ? 'JRK3-004-XV-12A' : null, queue_key: queueKey,
});

const GL = [
    gl(1, 901, 'JKB/2603/0001', '2026-03-15', 'posted', 'Approved', 0, 100000000, 'TTS (UTJ/DP)', 'TTS-0001', 'Roronoa Zorro', 'utj_dp-7'),
    // Booked Revenue — jalur yang selama ini TIDAK terwakili di antrean sama sekali.
    gl(2, 902, 'JM/2603/0002', '2026-03-16', 'posted', 'Approved', 0, 500000000, 'Booked Revenue', 'BR-2603-0001', 'Roronoa Zorro', null),
    // Retensi DAJAM — mendebit akun UM.
    gl(3, 903, 'JKB/2603/0003', '2026-03-18', 'posted', 'Approved', 50000000, 0, null, null, null, 'retensi-5'),
    // Jurnal memorial manual, belum di-approve.
    gl(4, 905, 'JM/2603/0005', '2026-03-25', 'draft', 'Baru', 200000000, 0, null, null, null, null),
];

const REKON = {
    gl_kredit: 600000000, gl_debit: 250000000, gl_saldo: 350000000, gl_belum_efektif: -200000000,
    antrean_total: 561000000, antrean_count: 3, ppn_antrean: 55594595,
    tanpa_gl_count: 1, tanpa_gl_total: 400000000,
    lawan_count: 1, lawan_total: 50000000, lawan_gl: -50000000,
    yatim_count: 2, yatim_net: 300000000,
    kolom_hilang: [],
};

const PROPS = (tab) => ({
    rows: ANTREAN,
    summary: { count: 3, total: 561000000 },
    gl: {
        accounts: [{ id: 130, code: '20106060601', name: 'UANG MUKA PENJUALAN' }],
        rows: GL,
        periode: { from: '2026-03-15', to: '2026-03-25' },
        filters: { date_from: null, date_to: null },
        tab,
    },
    rekon: REKON,
});

/** Lebar tiap baris, dengan rowspan dari baris di atasnya ikut diperhitungkan. */
function lebarPerBaris(html) {
    const baris = html.match(/<tr[\s\S]*?<\/tr>/g) || [];
    const sisa = [];
    const lebar = [];
    for (const r of baris) {
        let n = sisa.filter((x) => x > 0).length;
        for (let i = 0; i < sisa.length; i++) if (sisa[i] > 0) sisa[i]--;
        for (const sel of r.match(/<t[dh][^>]*>/g) || []) {
            const cs = Number((sel.match(/colspan="(\d+)"/) || [])[1] || 1);
            const rs = Number((sel.match(/rowspan="(\d+)"/) || [])[1] || 1);
            n += cs;
            if (rs > 1) for (let k = 0; k < cs; k++) sisa.push(rs - 1);
        }
        lebar.push(n);
    }

    return lebar;
}

// route() dipalsukan sebagai querystring yang terbaca, supaya parameter tautannya bisa
// diperiksa — bukan hanya keberadaan tautannya.
const routeFn = (n, p) => {
    const q = p && typeof p === 'object'
        ? '?' + Object.entries(p).map(([k, v]) => k + '=' + v).join('&')
        : (p !== undefined ? '/' + p : '');

    return '/' + n + q;
};

async function render(modul, tab) {
    const app = createSSRApp({ render: () => h(modul.exports.default, PROPS(tab)) });
    // DUA tempat, dan keduanya perlu. Pemanggilan di dalam <template> lewat
    // globalProperties; pemanggilan di dalam <script setup> dikompilasi jadi identifier
    // telanjang sehingga ia mencari GLOBAL sungguhan — di browser itu window.route yang
    // dipasang @routes. Memasang salah satunya saja membuat separuh tautan meledak.
    app.config.globalProperties.route = routeFn;
    globalThis.route = routeFn;

    return await renderToString(app);
}

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    // Komentar template ikut ter-render pada SSR (di build produksi ia dibuang) — dibuang di
    // sini supaya perhitungan elemen tidak ikut menghitung teks di dalam komentar.
    const bersih = (s) => s.replace(/<!--[\s\S]*?-->/g, '');
    const htmlAntrean = bersih(await render(modul, 'antrean'));
    const htmlBuku    = bersih(await render(modul, 'buku'));
    const teksBuku    = htmlBuku.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');
    const teksAntrean = htmlAntrean.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    if (process.env.DUMP) { fs.writeFileSync(process.env.DUMP, htmlBuku); }

    console.log('\n=== 1. Layar BENAR-BENAR membaca prop buku besar ===');

    // Inilah yang tidak bisa dilihat uji sisi server: prop terkirim tetapi tak terpakai.
    cek('tab Buku Besar ada', teksAntrean.includes('Buku Besar Akun UM'), 'tidak ditemukan');
    cek('jumlah baris jurnal disebut di tabnya', /Buku Besar Akun UM\s*4/.test(teksBuku), 'angka 4 tidak menempel');
    cek('tab dapat dituju langsung dari server', htmlBuku.includes('Jumlah 4 baris yang tampil'),
        'tab=buku tidak membuka tab Buku Besar');

    console.log('\n=== 2. Tiap baris buku besar tampil, termasuk yang tak punya wakil ===');

    // DUA penyempitan, dan keduanya perlu. Halaman ini punya lebih dari satu tabel — jembatan
    // selisih juga sebuah tabel ber-<tbody> — jadi tabelnya dipilih dulu lewat penanda kolom.
    // Lalu diambil isi <tbody> saja: memotong dari '<tbody' sampai akhir ikut menyeret baris
    // <tfoot>. Tanpa keduanya jumlah barisnya 7, bukan 4, padahal tabelnya benar.
    const tabelDengan = (html, penanda) => (html.match(/<table[\s\S]*?<\/table>/g) || []).find((t) => t.includes(penanda)) || '';
    const isiTbody = (html) => (html.match(/<tbody[\s\S]*?<\/tbody>/g) || []).join('');
    const trBuku = (isiTbody(tabelDengan(htmlBuku, 'Keterangan Baris')).match(/<tr[\s\S]*?<\/tr>/g) || []);
    cek('empat baris jurnal ter-render', trBuku.length === 4, String(trBuku.length));
    ['JKB/2603/0001', 'JM/2603/0002', 'JKB/2603/0003', 'JM/2603/0005'].forEach((n) => {
        cek('"' + n + '" tercetak', teksBuku.includes(n), 'tidak ditemukan');
    });
    cek('Booked Revenue dikenali dokumennya', teksBuku.includes('Booked Revenue'), 'tidak ditemukan');
    cek('nomor BR-nya tercetak', teksBuku.includes('BR-2603-0001'), 'tidak ditemukan');
    cek('baris tanpa dokumen dikatakan apa adanya', teksBuku.includes('jurnal manual / tak terlacak'), 'tidak ditemukan');
    cek('baris yang sudah punya wakil ditandai', teksBuku.includes('ada di antrean'), 'tidak ditemukan');
    cek('jurnal yang belum approved tetap tampil dengan statusnya', teksBuku.includes('Baru'), 'tidak ditemukan');

    console.log('\n=== 3. Jumlah kolomnya benar-benar menjumlah ===');

    // 100.000.000 + 500.000.000 kredit; 50.000.000 + 200.000.000 debit.
    cek('jumlah kredit tercetak', teksBuku.includes('Rp 600.000.000'), 'tidak ditemukan');
    cek('jumlah debit tercetak', teksBuku.includes('Rp 250.000.000'), 'tidak ditemukan');
    cek('saldo buku besar tercetak', teksAntrean.includes('Rp 350.000.000'), 'tidak ditemukan');

    console.log('\n=== 4. Keutuhan tabel — tiap baris selebar sama ===');

    // Ada LEBIH DARI SATU tabel di halaman ini: jembatan selisih juga sebuah tabel (2 kolom).
    // Mengambil yang pertama saja mengukur tabel yang salah — dan karena tabel jembatan memang
    // konsisten 2 kolom, pemeriksaannya HIJAU sambil tidak memeriksa apa pun yang dimaksud.
    for (const [nama, html, penanda, lebarSeharusnya] of [
        ['antrean', htmlAntrean, 'Total UM', 11],
        ['buku besar', htmlBuku, 'Keterangan Baris', 9],
    ]) {
        const tabel = html.match(/<table[\s\S]*?<\/table>/g) || [];
        cek('tabel ' + nama + ': setiap tabel di halaman konsisten lebarnya',
            tabel.length > 0 && tabel.every((t) => new Set(lebarPerBaris(t)).size === 1),
            tabel.map((t) => [...new Set(lebarPerBaris(t))].join('/')).join(' | '));
        const utama = tabel.find((t) => t.includes(penanda));
        cek('tabel ' + nama + ': tabel datanya ketemu', utama !== undefined,
            'penanda "' + penanda + '" tidak ada di tabel mana pun');
        const unik = utama ? [...new Set(lebarPerBaris(utama))] : [];
        cek('tabel ' + nama + ': lebarnya ' + lebarSeharusnya + ' kolom', unik[0] === lebarSeharusnya, String(unik[0]));
    }

    console.log('\n=== 5. Tautan ke Detail Transaksi Jurnal ===');

    // Layar tujuan berbawaan "bulan berjalan"; tanpa rentang tanggal, tautannya mendarat kosong.
    const tautAkun = (htmlBuku.match(/href="\/bs\.gl\.journal\.detail\?[^"]*"/g) || []);
    cek('tautan ke Detail Transaksi Jurnal ada', tautAkun.length > 0, 'tidak ditemukan');
    cek('membawa penyaring akun', tautAkun.some((t) => t.includes('f_account=130')), tautAkun.join(' | '));
    cek('membawa rentang tanggal awal', tautAkun.some((t) => t.includes('date_from=2026-03-15')), tautAkun.join(' | '));
    cek('membawa rentang tanggal akhir', tautAkun.some((t) => t.includes('date_to=2026-03-25')), tautAkun.join(' | '));

    // No. Jurnal dulu teks mati; sekarang membuka jurnalnya.
    const tautJurnal = (htmlAntrean.match(/href="\/bs\.gl\.journal\.show\/\d+"/g) || []);
    cek('No. Jurnal di antrean kini bertaut', tautJurnal.length === 3, String(tautJurnal.length));
    cek('tautannya memakai id jurnal, bukan nomornya', tautJurnal.includes('href="/bs.gl.journal.show/901"'),
        tautJurnal.join(' | '));
    const tautBuku = (htmlBuku.match(/href="\/bs\.gl\.journal\.show\/\d+"/g) || []);
    cek('tiap baris buku besar juga bertaut', tautBuku.length === 4, String(tautBuku.length));

    console.log('\n=== 6. Selisih dijelaskan, bukan sekadar ditampilkan ===');

    cek('jembatan selisih ada', teksAntrean.includes('Jembatan antrean'), 'tidak ditemukan');
    // 561.000.000 − 350.000.000 = 211.000.000
    cek('selisihnya dihitung benar', teksAntrean.includes('Rp 211.000.000'), 'tidak ditemukan');
    cek('PPN disebut sebagai penyebab', teksAntrean.includes('PPN pada nilai antrean'), 'tidak ditemukan');
    cek('baris yang jurnalnya tak menyentuh akun UM disebut',
        teksAntrean.includes('tidak menyentuh akun Uang Muka sama sekali'), 'tidak ditemukan');
    cek('baris buku besar tanpa wakil disebut',
        teksAntrean.includes('tidak punya wakil di antrean'), 'tidak ditemukan');
    cek('arah berlawanan (retensi DAJAM) disebut',
        teksAntrean.includes('mengurangi'), 'tidak ditemukan');
    cek('jurnal yang belum approved disebut', teksAntrean.includes('belum Approved'), 'tidak ditemukan');

    console.log('\n=== 7. Penanda per baris antrean ===');

    // Baris yang jurnalnya tidak menyentuh akun UM, dan baris yang arahnya berlawanan,
    // harus terlihat DI BARISNYA — bukan hanya sebagai angka gabungan di jembatan.
    cek('baris tanpa jejak buku besar diberi tanda',
        htmlAntrean.includes('Baris ini tidak akan muncul di tab Buku Besar'), 'tidak ditemukan');
    cek('baris berarah berlawanan diberi tanda',
        htmlAntrean.includes('berlawanan arah dengan nilai antrean'), 'tidak ditemukan');

    console.log('\n=== 8. Aksi lama tidak hilang ===');

    const src = fs.readFileSync(BERKAS, 'utf8');
    cek('tombol Kompensasi dengan AR masih ada', src.includes("route('bs.ar.um.compensate')"));
    cek('kotak centang per baris masih ada', htmlAntrean.includes('type="checkbox"'));
    cek('kolom DPP/PPN/Total masih ada',
        teksAntrean.includes('DPP') && teksAntrean.includes('PPN') && teksAntrean.includes('Total UM'));
    cek('penanda PPN perkiraan masih ada', htmlAntrean.includes('Perkiraan PPN inklusif 11%'));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
