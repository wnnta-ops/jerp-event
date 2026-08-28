// Render Journals/Index.vue YANG SEBENARNYA dan periksa keutuhan tabel memanjangnya.
//
// Tata letaknya kini satu baris tabel per BARIS JURNAL: kolom milik jurnalnya memakai rowspan
// dan membentang menaungi baris-barisnya. Rowspan adalah hal yang paling mudah salah sekaligus
// paling sunyi kalau salah — tabelnya tetap tergambar, hanya isinya bergeser satu kolom dan
// angka duduk di bawah judul yang bukan miliknya. Karena itu pemeriksaan intinya bukan "ada
// berapa kolom" melainkan: SETIAP baris menempati lebar yang sama persis, setelah rowspan
// dari baris-baris di atasnya diperhitungkan.
//
// Jalankan:  node tests/Manual/uji-jurnal-tabel-memanjang.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/Journals/Index.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
                  + " export const Link = { setup: (p, { slots }) => () => h('a', {}, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                // SENGAJA CommonJS: halaman ini menarik belasan ikon lewat import bernama, dan
                // modul ESM harus mengekspor tiap nama secara statis. Sebagai CommonJS, nama apa
                // pun diselesaikan saat berjalan lewat Proxy — tidak perlu didaftar satu per satu
                // dan tidak akan patah saat kelak ada ikon baru dipakai.
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

const akun = (code, name) => ({ code, name });
const JURNAL = [
    {
        id: 1, journal_number: 'JM/2608/0063', journal_date: '2026-08-31', source: 'memorial',
        description: 'Penyusutan aset test 2026/8', reference: 'FA/2607/0005', status: 'final',
        lines_count: 2, total_debit: 25000, total_credit: 25000,
        budget_status: 'none', budget_note: null, period_closed: false,
        lines: [
            { id: 11, account_id: 5, account: akun('50302020227', 'BIAYA PENYUSUTAN AT INVENTARIS KANTOR'), description: 'Beban penyusutan', debit: 25000, credit: 0 },
            { id: 12, account_id: 6, account: akun('10203030303', 'AKUMULASI PENYUSUTAN INVENTARIS KANTOR'), description: 'Akumulasi penyusutan', debit: 0, credit: 25000 },
        ],
    },
    {
        id: 2, journal_number: 'JM/2608/0062', journal_date: '2026-08-31', source: 'memorial',
        description: 'Penyusutan DISPENSER OFFICE 2026/8', reference: 'FA/2605/0004', status: 'final',
        lines_count: 3, total_debit: 20833.33, total_credit: 20833.33,
        budget_status: 'over', budget_note: 'Anggaran 10.000 · realisasi 20.833', period_closed: false,
        lines: [
            { id: 21, account_id: 5, account: akun('50302020227', 'BIAYA PENYUSUTAN AT INVENTARIS KANTOR'), description: 'Beban penyusutan', debit: 20833.33, credit: 0 },
            { id: 22, account_id: 6, account: akun('10203030303', 'AKUMULASI PENYUSUTAN INVENTARIS KANTOR'), description: 'Akumulasi penyusutan', debit: 0, credit: 10000 },
            { id: 23, account_id: 7, account: akun('10203030304', 'AKUMULASI PENYUSUTAN LAIN'), description: null, debit: 0, credit: 10833.33 },
        ],
    },
];

const PROPS = (detail) => ({
    journals: { data: JURNAL, total: JURNAL.length },
    sources: { memorial: 'Memorial' },
    statuses: { draft: 'Baru', final: 'Final', posted: 'Approved' },
    filters: { date_from: '2026-08-01', date_to: '2026-08-31' },
    defaultDetail: detail,
    listRoute: 'bs.gl.journal.index',
    title: 'Detail Transaksi Jurnal',
    canApprove: true,
    jumlahStatus: { baru: 31, final: 22, approved: 11, closed: 0 },
    tahunRekap: 2026,
    opsiKolom: { number: [], date: [], desc: [], lines: [], debit: [], credit: [], account: [], line_desc: [] },
    batasOpsi: 200,
    budgetStatuses: { over: 'Over Budget', under: 'Under Budget', none: 'Non Budget' },
});

/** Potong bagian <table> saja. */
const ambilTabel = (html) => (html.match(/<table[\s\S]*?<\/table>/) || [''])[0];

/**
 * Lebar tiap baris tabel, dengan rowspan dari baris di atasnya ikut diperhitungkan.
 * Inilah pemeriksaan yang menangkap pergeseran kolom akibat rowspan yang keliru.
 */
function lebarPerBaris(tabelHtml) {
    const baris = tabelHtml.match(/<tr[\s\S]*?<\/tr>/g) || [];
    const sisaRowspan = [];   // berapa baris lagi tiap sel ber-rowspan menempati
    const lebar = [];
    for (const r of baris) {
        let n = sisaRowspan.filter((x) => x > 0).length;
        for (let i = 0; i < sisaRowspan.length; i++) if (sisaRowspan[i] > 0) sisaRowspan[i]--;
        for (const sel of r.match(/<t[dh][^>]*>/g) || []) {
            const cs = Number((sel.match(/colspan="(\d+)"/) || [])[1] || 1);
            const rs = Number((sel.match(/rowspan="(\d+)"/) || [])[1] || 1);
            n += cs;
            if (rs > 1) for (let k = 0; k < cs; k++) sisaRowspan.push(rs - 1);
        }
        lebar.push(n);
    }

    return lebar;
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

    const render = async (detail) => {
        const app = createSSRApp({ render: () => h(Comp, PROPS(detail)) });
        app.config.globalProperties.route = () => '#';

        return ambilTabel(await renderToString(app));
    };

    const buka = await render(true);     // Detail Transaksi Jurnal — rincian tampil
    const tutup = await render(false);   // Master → Jurnal — rincian disembunyikan

    console.log('\n=== 1. Judul kolom: satu pasang Debit & Kredit, tidak lebih ===');

    const th = [...buka.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1].trim());
    const judul = th.filter(Boolean);
    // Dulu ada sepasang Debit/Kredit pada kepala jurnal DAN sepasang lagi pada rinciannya —
    // dua-duanya berjudul sama, sehingga mata harus memutuskan sendiri mana yang dibacanya.
    cek('hanya ada satu judul "Debit"', judul.filter((t) => t === 'Debit').length === 1,
        String(judul.filter((t) => t === 'Debit').length));
    cek('hanya ada satu judul "Kredit"', judul.filter((t) => t === 'Kredit').length === 1,
        String(judul.filter((t) => t === 'Kredit').length));
    const urut = judul.join('|');
    cek('urutannya seperti tampilan acuan',
        urut.includes('No. Jurnal|Tanggal|Sumber|Keterangan|Status|Baris|Anggaran|COA|Nama Akun|Keterangan Baris|Debit|Kredit'), urut);
    cek('COA dan Nama Akun jadi dua kolom terpisah',
        judul.includes('COA') && judul.includes('Nama Akun'), urut);

    console.log('\n=== 2. Keutuhan tabel — tiap baris selebar yang sama ===');

    for (const [nama, tabel] of [['rincian tampil', buka], ['rincian tersembunyi', tutup]]) {
        const lebar = lebarPerBaris(tabel);
        const unik = [...new Set(lebar)];
        cek('semua baris selebar sama (' + nama + ')', unik.length === 1,
            'lebar yang muncul: ' + unik.join(',') + ' → ' + lebar.join(','));
    }

    console.log('\n=== 3. Satu baris tabel untuk satu baris jurnal ===');

    const trIsi = (t) => (t.split('<tbody')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];
    cek('lima baris untuk jurnal berisi 2 + 3 baris', trIsi(buka).length === 5, String(trIsi(buka).length));
    // Kolom milik jurnalnya ditulis SEKALI lalu membentang — bukan diulang tiap baris.
    // Dicocokkan sebagai isi sel (>…<), bukan sebagai teks bebas: nomor jurnal juga muncul di
    // atribut title kotak centang, dan menghitungnya akan membuat pemeriksaan ini bohong.
    cek('nomor jurnal pertama hanya ditulis sekali sebagai isi sel',
        (buka.match(/>JM\/2608\/0063</g) || []).length === 1,
        String((buka.match(/>JM\/2608\/0063</g) || []).length));
    cek('rowspan-nya sebesar jumlah barisnya',
        buka.includes('rowspan="2"') && buka.includes('rowspan="3"'), 'tidak ditemukan');

    console.log('\n=== 4. Isi kolom baris benar ===');

    cek('COA baris pertama tercetak', buka.includes('50302020227'), 'tidak ditemukan');
    cek('nama akun baris pertama tercetak', buka.includes('BIAYA PENYUSUTAN AT INVENTARIS KANTOR'));
    cek('keterangan baris tercetak', buka.includes('Beban penyusutan') && buka.includes('Akumulasi penyusutan'));
    cek('keterangan baris kosong tidak jadi baris hilang', buka.includes('—'), 'tidak ditemukan');
    cek('debit baris tercetak', buka.includes('25.000'), 'tidak ditemukan');

    console.log('\n=== 5. Rincian tersembunyi tetap membawa angkanya ===');

    // Halaman ini dipakai DUA menu: Query → Detail Transaksi Jurnal (rincian tampil) dan
    // Master → Jurnal (rincian tersembunyi). Karena Debit/Kredit kepala jurnal dihapus,
    // baris ringkas WAJIB membawa totalnya — kalau tidak, daftar Jurnal kehilangan seluruh
    // angkanya.
    cek('satu baris per jurnal saat rincian ditutup', trIsi(tutup).length === 2, String(trIsi(tutup).length));
    cek('total debit jurnal tetap tampil', tutup.includes('25.000'), 'tidak ditemukan');
    cek('dikatakan bahwa itu total, bukan baris', tutup.includes('total jurnal'), 'tidak ditemukan');
    cek('COA dikosongkan saat rincian ditutup, bukan diisi tebakan',
        ! tutup.includes('50302020227'), 'COA masih tercetak');

    console.log('\n=== 6. Saringan tiap kolom tetap ada ===');

    // Nama saringan tidak ikut ke HTML (ia hanya pengikat di komponen), jadi keberadaannya
    // diperiksa pada SUMBER template — cukup untuk menangkap saringan yang hilang saat tabelnya
    // ditulis ulang, yang justru risiko terbesar perubahan ini.
    const sumber = fs.readFileSync(BERKAS, 'utf8');
    const thead = sumber.split('<thead')[1]?.split('</thead>')[0] ?? '';
    ['f_number', 'f_date', 'f_desc', 'f_lines', 'f_budget', 'f_account', 'f_line_desc', 'f_debit', 'f_credit']
        .forEach((k) => cek('saringan ' + k + ' masih terpasang', thead.includes('kolomFilter.' + k)));
    cek('saringan Sumber & Status juga masih di kepala tabel',
        thead.includes('opsiSumber') && thead.includes('opsiStatus'));
    // Tiap kotak saringan benar-benar tergambar di layar, bukan sekadar tertulis di sumber.
    // SearchSelect menuliskan placeholder-nya sebagai TEKS saat belum ada yang dipilih,
    // bukan sebagai atribut — jadi itulah jejak yang dihitung.
    const kotak = (buka.match(/>Semua(?: Akun| Keterangan)?</g) || []).length;
    cek('kotak saringannya benar-benar tergambar', kotak >= 9, String(kotak));
    // Apa yang sebenarnya disaring harus dikatakan: saringan Debit/Kredit mencocokkan TOTAL
    // jurnalnya, bukan angka per baris yang tertera di kolom itu saat rincian dibuka.
    cek('dijelaskan bahwa Akun & Keterangan Baris menjaring jurnalnya', buka.includes('menjaring jurnalnya'));
    cek('dijelaskan bahwa Debit & Kredit mencocokkan total jurnalnya', buka.includes('mencocokkan total jurnalnya'));

    console.log('\n=== 7. Aksi massal & penanda tidak hilang ===');

    cek('kotak centang per jurnal masih ada', (buka.match(/type="checkbox"/g) || []).length >= 3,
        String((buka.match(/type="checkbox"/g) || []).length));
    cek('tautan Lihat masih ada', (buka.match(/Lihat/g) || []).length >= 2);
    cek('label anggaran masih tergambar', buka.includes('Over Budget') && buka.includes('Non Budget'));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
