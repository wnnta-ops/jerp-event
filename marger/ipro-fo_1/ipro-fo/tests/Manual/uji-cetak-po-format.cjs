// Render Print.vue YANG SEBENARNYA menjadi HTML dan bandingkan dengan lampiran acuan
// PO-JI-2026-07-0074.docx.
//
// Datanya diambil persis dari lampiran itu, sehingga setiap pemeriksaan di bawah adalah
// perbandingan langsung dengan dokumen yang dipegang supplier — bukan selera.
//
// Jalankan:  node tests/Manual/uji-cetak-po-format.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Purchasing/PurchaseOrders/Print.vue');

// Ketergantungan aplikasi distub — yang diuji tata letak & angkanya, bukan Inertia.
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
                : "import { h } from 'vue';" + ['ArrowLeft', 'Printer', 'Save', 'Plus', 'Trash2']
                    .map((n) => ` export const ${n} = { setup: () => () => h('span') };`).join(''),
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /PrintLayout\.vue$/ }, () => ({
            contents: "import { h } from 'vue';"
                + " export default { setup: (p, { slots }) => () => h('div', {}, slots.default ? slots.default() : []) };",
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

// Persis isi lampiran PO-JI-2026-07-0074.
const CONTOH = {
    id: 1, po_number: 'PO-2026/07-0074', printed_at: null, print_count: 0,
    form: {
        nomor: 'PO-2026/07-0074',
        sistem_pembayaran: 'Term Of Payment (TOP)',
        kepada: 'PT. JOTUN INDONESIA',
        kepada_alamat: 'JL. RUNGKUT INDUSTRI IV NO 24A, KOTA SURABAYA, JAWA TIMUR',
        proyek: 'PROYEK HOUSING CLUSTER 3 TAHAP 3 BATCH I',
        kirim_ke: 'MITRA USAHA PROPERTINDO',
        kirim_alamat: 'TERUNG KULON, KEC. KRIAN, KABUPATEN SIDOARJO, JAWA TIMUR',
        tanggal_order: '13 Juli 2026',
        pelaksanaan: 'MITRA USAHA PROPERTINDO',
        penerima: 'PANJI',
        items: [{ nama: 'JOTASHIELD NCS 6311-Y29R (MUP)', sat: 'PAL', qty: 3, harga: 1412500, jumlah: 4237500 }],
        subtotal: 4237500, ppn_pct: 11, ppn_amount: 466125, total: 4703625,
        catatan: 'Harga Sudah termasuk PPN 11% & Ongkir',
        cara_pembayaran: 'Nett 30 hari',
        garansi: 'Keterangan tambahan terlampir dan menjadi satu kesatuan yang tidak terpisahkan dengan PO ini',
        kelengkapan: 'Kwitansi asli (≥ 5 juta bermaterai) · Invoice (1 asli)\nSurat jalan (asli) · Faktur Pajak (2 lembar)\nSurat Pesanan Barang/PO (asli)',
        pengambilan: 'Pengambilan PO asli dan penyerahan tagihan di MITRA USAHA PROPERTINDO RUKO AVENUE NO. 27',
        pembayaran: 'Pembayaran dilakukan dengan cara transfer',
        ttd: [
            { label: 'Dibuat Oleh,', nama: 'DIPTA DAMAI PRAYOGA', jabatan: 'HRGA' },
            { label: 'Mengetahui,', nama: 'RIZKI SETYAWARDANA', jabatan: 'General Manager' },
            { label: 'Disetujui,', nama: 'RACHMAT SUHENDRA', jabatan: 'Direktur' },
        ],
        diterima_label: 'Disetujui dan diterima',
        diterima_nama: 'PT. JOTUN INDONESIA',
    },
};

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const app = createSSRApp({ render: () => h(modul.exports.default, { orders: [CONTOH] }) });
    app.config.globalProperties.route = () => '#';
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&#39;/g, "'").replace(/&amp;/g, '&').replace(/\s+/g, ' ');
    const lepas = (s) => s.replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&quot;/g, '"')
        .replace(/&lt;/g, '<').replace(/&gt;/g, '>');
    const nilai = [...html.matchAll(/value="([^"]*)"/g)].map((m) => lepas(m[1]));
    const isiTextarea = [...html.matchAll(/<textarea[^>]*>([\s\S]*?)<\/textarea>/g)].map((m) => m[1]);

    console.log('\n=== 1. Angka ditulis seperti pada lampiran ===');

    // Lampiran: 4,237,500.00 — ribuan koma, desimal titik. Format id-ID (4.237.500,00)
    // membuat lembar cetak berbeda dari yang selama ini dipegang supplier.
    cek('subtotal 4,237,500.00', teks.includes('4,237,500.00'), 'tidak ditemukan');
    cek('PPN 466,125.00', teks.includes('466,125.00'), 'tidak ditemukan');
    cek('total 4,703,625.00', teks.includes('4,703,625.00'), 'tidak ditemukan');
    cek('tidak memakai format id-ID', ! teks.includes('4.237.500,00'), 'masih memakai titik ribuan');
    // Lampiran menulis QTY sebagai 3.00, bukan 3.
    cek('QTY dicetak dua angka desimal (3.00)', teks.includes('3.00'), 'tidak ditemukan');
    cek('harga satuan 1,412,500.00', teks.includes('1,412,500.00'), 'tidak ditemukan');

    console.log('\n=== 2. Kotak angka tidak ikut tercetak ===');

    // input type=number mencetak "3" bukan "3.00", dan pada sebagian browser membawa panah
    // pengatur nilainya ke atas kertas.
    const inputAngka = [...html.matchAll(/<input[^>]*type="number"[^>]*>/g)].map((m) => m[0]);
    cek('ada kotak angka untuk disunting di layar', inputAngka.length >= 3, String(inputAngka.length));
    cek('semua kotak angka disembunyikan saat mencetak',
        inputAngka.every((t) => t.includes('print:hidden')),
        inputAngka.filter((t) => ! t.includes('print:hidden')).join(' | ').slice(0, 160));
    cek('PPN tercetak menyatu sebagai "PPN 11%"', teks.includes('PPN 11%'), 'tidak ditemukan');

    console.log('\n=== 2b. Logo kop ===');

    const img = (html.match(/<img[^>]*>/g) || []).join(' ');
    // mup-letterhead.jpeg adalah lembar kop A4 utuh (2482×3511, lengkap cap air sehalaman dan
    // bilah alamat di kaki). Dipasang sebagai <img> setinggi 64px, seluruh halaman itu mampat
    // jadi sepotong oranye setinggi beberapa piksel dan logonya praktis lenyap.
    cek('memakai berkas logo, bukan lembar kop A4', img.includes('/images/mup-logo.jpeg'), img.slice(0, 140));
    cek('lembar kop A4 tidak dipakai sebagai logo', ! img.includes('mup-letterhead'), img.slice(0, 140));
    cek('digambar persegi supaya tidak gepeng', /h-16[^"]*w-16|w-16[^"]*h-16/.test(img), img.slice(0, 140));
    cek('punya teks alternatif', /alt="[^"]+"/.test(img), img.slice(0, 140));

    console.log('\n=== 3. Kolom tabel sama dengan lampiran ===');

    const th = [...html.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1].trim()).filter(Boolean);
    cek('urutan kolom NO./NAMA BARANG/SAT/QTY/HARGA SATUAN/JUMLAH',
        th.slice(0, 6).join('|') === 'NO.|NAMA BARANG|SAT|QTY|HARGA SATUAN|JUMLAH', th.slice(0, 6).join('|'));
    cek('baris ringkasan Subtotal, PPN, TOTAL ada',
        teks.includes('Subtotal') && teks.includes('TOTAL'), 'tidak lengkap');

    console.log('\n=== 4. Kondisi Pembelian bernomor 1..5 tanpa nomor kosong ===');

    // Textarea adalah elemen blok: di dalam <li> ia turun ke baris berikutnya dan menyisakan
    // nomornya sendirian di baris kosong — itulah "4." kosong yang selama ini tercetak.
    const li = [...html.matchAll(/<li[^>]*>([\s\S]*?)<\/li>/g)].map((m) => m[1]);
    cek('ada lima butir', li.length === 5, String(li.length));
    const nomor = li.map((s) => (s.match(/>(\d)\.</) || [])[1]).join(',');
    cek('nomornya berurut 1..5', nomor === '1,2,3,4,5', nomor);
    const kosong = li.filter((s) => {
        const t = s.replace(/<[^>]+>/g, '').replace(/\d\./, '').trim();
        const punyaNilai = /value="[^"]+"/.test(s) || /<textarea[^>]*>[\s\S]*?\S[\s\S]*?<\/textarea>/.test(s);

        return ! t && ! punyaNilai;
    });
    cek('tidak ada butir yang hanya berisi nomor', kosong.length === 0, String(kosong.length));
    cek('butir 4 memang berisi teks pengambilan PO',
        li[3].includes('Pengambilan PO asli'), li[3].replace(/<[^>]+>/g, '').trim().slice(0, 60));
    cek('butir 5 memang berisi cara pembayaran',
        li[4].includes('Pembayaran dilakukan dengan cara transfer'), 'tidak ditemukan');

    console.log('\n=== 5. Kelengkapan tagihan dua kolom saat dicetak ===');

    cek('tiap barisnya dipecah jadi dua kolom',
        teks.includes('Kwitansi asli') && teks.includes('Invoice (1 asli)')
        && teks.includes('Faktur Pajak (2 lembar)'), 'tidak lengkap');
    cek('pemisah "·" tidak ikut tercetak',
        ! html.split('print:block')[1]?.split('</li>')[0]?.includes('·'), 'pemisah masih terbawa');

    console.log('\n=== 6. Tanda tangan & penerimaan supplier ===');

    ['Dibuat Oleh,', 'Mengetahui,', 'Disetujui,'].forEach((l) => {
        cek('blok "' + l + '" ada', nilai.includes(l), nilai.join(' | ').slice(0, 120));
    });
    ['DIPTA DAMAI PRAYOGA', 'RIZKI SETYAWARDANA', 'RACHMAT SUHENDRA'].forEach((n) => {
        cek('nama penanda tangan ' + n + ' tercetak', nilai.includes(n));
    });
    // Blok ini tidak pernah tercetak sebelumnya, sehingga lembar yang dikirim ke supplier
    // tidak punya tempat tanda tangannya.
    cek('blok "Disetujui dan diterima" ada', nilai.includes('Disetujui dan diterima'), 'tidak ditemukan');
    cek('nama supplier tercetak di blok penerimaan', nilai.includes('PT. JOTUN INDONESIA'), 'tidak ditemukan');

    console.log('\n=== 7. Blok identitas memakai titik dua ===');

    ['Nomor', 'Sistem Pembayaran', 'Kepada Yth,', 'PROYEK', 'KIRIM KE', 'TANGGAL ORDER', 'Pelaksanaan', 'PENERIMA']
        .forEach((l) => cek('label "' + l + '" ada', teks.includes(l)));
    const titikDua = (teks.match(/ : /g) || []).length;
    cek('titik dua pemisah tampil untuk tiap label', titikDua >= 8, String(titikDua));

    console.log('\n=== 8. Isian tetap bisa disunting ===');

    cek('nomor PO terisi', nilai.includes('PO-2026/07-0074'));
    cek('catatan terisi', nilai.includes('Harga Sudah termasuk PPN 11% & Ongkir'));
    cek('kelengkapan tetap satu kotak teks yang bisa disunting',
        isiTextarea.some((t) => t.includes('Kwitansi asli')), 'tidak ditemukan');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
