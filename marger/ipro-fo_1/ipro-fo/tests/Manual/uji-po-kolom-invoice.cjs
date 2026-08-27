// Jalankan setup() Purchase Orders/Index.vue yang SEBENARNYA dan periksa kolom Invoice Hutang.
//
// Yang diperiksa bukan tampilannya melainkan hal-hal yang bisa rusak tanpa satu pun galat:
//  - teks sel untuk 0 / 1 / 2 invoice;
//  - pencarian & checklist filter memakai teks PENUH, sehingga invoice kedua tetap ketemu;
//  - jumlah kolom Excel antara baris judul dan baris isi tetap sama — menggeser salah satunya
//    saja memindahkan seluruh kolom di kanannya, berkasnya tetap terbuka mulus, dan angkanya
//    duduk di kolom yang salah.
//
// Jalankan:  node tests/Manual/uji-po-kolom-invoice.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { effectScope } = require('vue');

const ROOT = process.argv[2] || '.';
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Purchasing/PurchaseOrders/Index.vue');

// Halaman ini menarik banyak komponen & Inertia. Semuanya distub: yang diuji logika kolomnya,
// bukan komponen anaknya.
const stub = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^[^.]/ }, (args) => (args.path === 'vue' ? undefined : { path: args.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({
            contents: 'module.exports = new Proxy(function(){}, { get: () => function(){} });',
            loader: 'js',
        }));
        build.onLoad({ filter: /\.vue$/ }, (args) => {
            const { descriptor } = sfc.parse(fs.readFileSync(args.path, 'utf8'), { filename: args.path });
            const id = 'x' + Buffer.from(args.path).toString('hex').slice(0, 8);

            return {
                contents: sfc.compileScript(descriptor, { id, inlineTemplate: false }).content,
                loader: 'ts', resolveDir: path.dirname(args.path),
            };
        });
    },
};

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

const inv = (n, d) => ({ id: n, number: 'AP.INV/BIAYA-TELP-PULSA/MUP/26/VIII/' + String(n).padStart(5, '0'), description: d });

const po = (id, invoices, notes) => ({
    id, po_number: 'PO/JRES/2608/' + String(id).padStart(4, '0'),
    po_date: '2026-08-18', due_date: '2026-08-28',
    supplier: { name: 'CV Jaya Beton Readymix' }, project: null, po_type: 'barang',
    notes: notes ?? null, ap_invoices: invoices,
    lines_count: 1, subtotal: 1000000, ppn_amount: 0, total: 1000000,
    status: 'closed', printed_at: null, print_count: 0,
});

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [stub],
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const Comp = modul.exports.default;

    const BARIS = [
        po(1, []),                                                   // belum ditagih
        po(2, [inv(24, 'Beli Pulsa')], 'Catatan manual operator'),   // satu invoice
        po(3, [inv(31, 'Termin satu'), inv(32, 'Termin dua')]),      // ditagih bertahap
    ];

    const scope = effectScope();
    let b;
    scope.run(() => {
        b = Comp.setup({ orders: BARIS, pendingFpb: [] },
            { attrs: {}, slots: {}, emit() {}, expose() {} });
    });

    console.log('\n=== 1. Teks sel untuk nol, satu, dan banyak invoice ===');

    cek('PO belum ditagih tampil sebagai em dash', b.dInvShort(BARIS[0]) === '—', b.dInvShort(BARIS[0]));
    // Inilah bentuk yang diminta: nomor dokumen, em dash, lalu keterangannya.
    cek('satu invoice tampil "NOMOR — Keterangan"',
        b.dInvShort(BARIS[1]) === 'AP.INV/BIAYA-TELP-PULSA/MUP/26/VIII/00024 — Beli Pulsa',
        b.dInvShort(BARIS[1]));
    // Menampilkan yang pertama saja akan MENYEMBUNYIKAN tagihan kedua tanpa jejak.
    cek('dua invoice menyebutkan sisanya, bukan diam-diam menampilkan yang pertama',
        b.dInvShort(BARIS[2]) === 'AP.INV/BIAYA-TELP-PULSA/MUP/26/VIII/00031 — Termin satu (+1 lagi)',
        b.dInvShort(BARIS[2]));
    cek('tidak ada em dash menggantung saat keterangannya kosong',
        b.dInvShort(po(9, [inv(40, '')])) === 'AP.INV/BIAYA-TELP-PULSA/MUP/26/VIII/00040',
        b.dInvShort(po(9, [inv(40, '')])));

    console.log('\n=== 2. Teks penuh membawa SEMUA invoice ===');

    const penuh = b.dInv(BARIS[2]);
    cek('memuat nomor invoice pertama', penuh.includes('00031'), penuh);
    cek('memuat nomor invoice kedua', penuh.includes('00032'), penuh);
    cek('dipisah " ; "', penuh.includes(' ; '), penuh);
    cek('PO tanpa invoice tetap em dash', b.dInv(BARIS[0]) === '—', b.dInv(BARIS[0]));

    console.log('\n=== 3. Pencarian menemukan invoice KEDUA ===');

    // Kalau pencarian memakai teks sel yang sudah dipendekkan, mengetik nomor invoice kedua
    // tidak akan menemukan barisnya padahal invoicenya ada di sana.
    b.search.value = '00032';
    cek('mengetik nomor invoice kedua menyisakan barisnya',
        b.filtered.value.length === 1 && b.filtered.value[0].id === 3,
        JSON.stringify(b.filtered.value.map((r) => r.id)));

    b.search.value = 'Beli Pulsa';
    cek('mengetik keterangan invoice juga menemukan barisnya',
        b.filtered.value.length === 1 && b.filtered.value[0].id === 2,
        JSON.stringify(b.filtered.value.map((r) => r.id)));
    b.search.value = '';

    console.log('\n=== 4. Checklist filter memakai nilai yang sama dengan yang dicocokkan ===');

    // Nilai opsi WAJIB identik dengan nilai yang dibandingkan, kalau tidak memilih sebuah opsi
    // justru mengosongkan tabel.
    cek('opsi filter memuat teks penuh tiap baris',
        b.optInv.value.includes(b.dInv(BARIS[2])), JSON.stringify(b.optInv.value));
    b.col.inv = [b.dInv(BARIS[2])];
    cek('memilih satu opsi menyisakan tepat baris itu',
        b.filtered.value.length === 1 && b.filtered.value[0].id === 3,
        JSON.stringify(b.filtered.value.map((r) => r.id)));
    b.col.inv = [];

    console.log('\n=== 5. Ekspor Excel — judul & isi tidak boleh bergeser ===');

    let html = '';
    const asliCreate = global.URL?.createObjectURL;
    const asliDoc = global.document;
    global.URL = { createObjectURL: () => 'blob:uji', revokeObjectURL() {} };
    global.Blob = function (isi) { html = String(isi[0]); };
    global.document = {
        createElement: () => ({ click() {}, remove() {}, style: {}, setAttribute() {} }),
        body: { appendChild() {}, removeChild() {} },
    };
    try {
        b.exportExcel();
    } finally {
        global.document = asliDoc;
        if (asliCreate) global.URL.createObjectURL = asliCreate;
    }

    const judul = (html.match(/<th>/g) || []).length;
    const barisIsi = html.match(/<tr>(?:(?!<\/tr>)[\s\S])*<\/tr>/g) || [];
    cek('berkasnya benar-benar terbentuk', html.length > 0);
    cek('ada tiga baris isi', barisIsi.length === 3, String(barisIsi.length));
    // Judul dan isi dirakit di dua tempat terpisah tanpa pengaman apa pun.
    const selPerBaris = barisIsi.map((t) => (t.match(/<td>/g) || []).length);
    cek('jumlah sel tiap baris sama dengan jumlah judul',
        selPerBaris.every((n) => n === judul), judul + ' judul vs sel ' + selPerBaris.join(','));
    cek('judul kolom keenam kini "Invoice Hutang"',
        html.includes('<th>Proyek</th><th>Invoice Hutang</th>'), 'tidak ditemukan');
    cek('catatan PO tetap ikut terekspor sebagai kolom sendiri',
        html.includes('<th>Invoice Hutang</th><th>Catatan PO</th>'), 'tidak ditemukan');
    cek('tidak ada lagi judul "Keterangan"', ! html.includes('<th>Keterangan</th>'));
    cek('nomor invoice benar-benar tertulis di selnya',
        html.includes('AP.INV/BIAYA-TELP-PULSA/MUP/26/VIII/00024'), 'tidak ditemukan');
    cek('catatan manual operator tidak hilang dari ekspor',
        html.includes('Catatan manual operator'), 'tidak ditemukan');

    scope.stop();

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
