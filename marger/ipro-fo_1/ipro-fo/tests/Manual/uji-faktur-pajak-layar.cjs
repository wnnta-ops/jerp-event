// Layar Faktur Pajak Keluaran & Masukan — kolom, isian kosong, aksi, seleksi, dan impor.
//
// KENAPA PERLU UJI:
//  · Kedua daftar kini dibentuk dari BARIS JURNAL. Yang paling mudah rusak diam-diam adalah
//    kolomnya: menambah kolom centang tanpa menyesuaikan colspan tfoot tidak menimbulkan galat
//    apa pun — angkanya hanya bergeser satu kolom, dan biasanya baru ketahuan saat dicetak.
//  · No. Faktur Pajak & NPWP HARUS berangkat kosong. Kalau salah satunya diam-diam terisi dari
//    master (mis. NPWP supplier), operator akan mengira faktur itu sudah diverifikasi.
//  · Nomor dokumen harus menaut ke JURNALNYA — sebagian baris memang tidak punya invoice, jadi
//    tautan lama ke halaman invoice akan berujung 404 tanpa ada yang menandainya.
//  · Baris UTJ & DP yang belum dikonversi tidak boleh punya kotak isian: satu-satunya aksinya
//    adalah tombol konversi.
//
// Jalankan:  node tests/Manual/uji-faktur-pajak-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const KELUARAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Tax/Keluaran.vue');
const MASUKAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Tax/Masukan.vue');

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

const SUMBER = { cash: 'Kas/Bank', memorial: 'Memorial', ar: 'Piutang (AR)', utj_dp: 'Approval UM Penjualan' };

/* Fikstur sengaja beragam supaya perbedaan perlakuan benar-benar terlihat:
   · baris jurnal kosong (belum ber-FP)  → punya kotak isian, tanpa tombol Hapus
   · baris jurnal terisi                 → punya kotak isian DAN tombol Hapus
   · entri UTJ & DP belum dikonversi     → tanpa kotak isian, hanya tombol Konversi
   Fikstur berbaris-satu akan membuat "berlaku untuk semua baris" tak terbedakan dari
   "berlaku untuk baris pertama saja". */
const ROWS_KELUARAN = [
    { row_type: 'jurnal', id: 101, journal_id: 7, journal_number: 'JKB/2609/0176', source: 'cash',
      invoice_date: '2026-09-08', partner: 'SARRA AMANDA SINAGA', doc_no: 'KONVERSI-UTJDP-10',
      unit: null, keterangan: 'Konversi UTJ/DP ke Uang Muka Penjualan', dpp: 1000000, ppn: 110000,
      tax_invoice_no: null, npwp: null, has_entry: false, editable: true, needs_konversi: false },
    { row_type: 'jurnal', id: 102, journal_id: 12, journal_number: 'JKB/2609/0180', source: 'memorial',
      invoice_date: '2026-09-10', partner: 'PT UJI COBA', doc_no: 'INV/AR/2609/0001',
      unit: 'JRK3-004-XV-7', keterangan: 'Penjualan unit', dpp: 2000000, ppn: 220000,
      tax_invoice_no: '010.000-26.00000001', npwp: '01.234.567.8-901.000', has_entry: true,
      editable: true, needs_konversi: false },
    { row_type: 'entry', id: 55, journal_id: null, journal_number: null, source: 'utj_dp',
      invoice_date: '2026-09-12', partner: 'BUDI SANTOSO', doc_no: 'JRK1-001-II-3', unit: 'JRK1-001-II-3',
      keterangan: 'UTJ & DP', dpp: 3000000, ppn: 330000, tax_invoice_no: null, npwp: null,
      has_entry: false, editable: false, needs_konversi: true },
];

const ROWS_MASUKAN = [
    { id: 201, journal_id: 21, invoice_number: 'JKB/2609/0201', reference: null, invoice_date: '2026-09-03',
      supplier_invoice_no: 'INV-SUP-001', partner: 'CV PEMASOK', keterangan: 'Pembelian',
      dpp: 5000000, ppn: 550000, tax_invoice_no: null, npwp: null, has_entry: false },
    { id: 202, journal_id: 22, invoice_number: 'JKB/2609/0202', reference: null, invoice_date: '2026-09-06',
      supplier_invoice_no: null, partner: 'Koreksi PPN memorial', keterangan: 'Memorial',
      dpp: 1000000, ppn: 110000, tax_invoice_no: '020.000-26.00000009', npwp: '09.876.543.2-109.000', has_entry: true },
];

async function render(berkas, props, tag) {
    const out = path.join(ROOT, 'fp-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [berkas], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    globalThis.route = (n, p) => '/' + n + (p != null ? '/' + (p?.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

/** Semua <tr> di dalam <tbody>. */
function barisTubuh(html) {
    const tbody = (html.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/) || ['', ''])[1];

    return [...tbody.matchAll(/<tr[^>]*>[\s\S]*?<\/tr>/g)].map((m) => m[0]);
}
/** Baris <tr> yang memuat sebuah untai. */
function barisMemuat(html, untai) {
    return barisTubuh(html).find((b) => b.includes(untai)) ?? null;
}
/** Sel <td> ke-i dari sebuah baris. */
function sel(baris, i) {
    return [...baris.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1])[i] ?? '';
}
/** Judul kolom dari baris <tr> pertama pada <thead>. */
function judulKolom(html) {
    const thead = (html.match(/<thead[^>]*>([\s\S]*?)<\/thead>/) || ['', ''])[1];
    const trPertama = (thead.match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];

    return [...trPertama.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
        .map((m) => m[1].replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').trim());
}

(async () => {
    const html = await render(KELUARAN, {
        rows: ROWS_KELUARAN, period: { year: 2026, month: 9 }, sources: SUMBER,
        totals: { dpp: 6000000, ppn: 660000, count: 3, tanpa_fp_count: 2, tanpa_fp_ppn: 440000, jurnal_count: 2, entry_count: 1 },
    }, 'keluaran');
    const srcKel = fs.readFileSync(KELUARAN, 'utf8');

    console.log('\n=== 1. KELUARAN — kolom mengikuti rincian jurnalnya ===');

    const judul = judulKolom(html);
    cek('kolom pertama adalah kotak centang (tanpa judul)', judul[0] === '', JSON.stringify(judul));
    cek('urutan kolomnya: FP, Sumber, Tanggal, NPWP, Nama, No. Dokumen / Unit, DPP, PPN, Aksi',
        JSON.stringify(judul.slice(1)) === JSON.stringify(
            ['No. Faktur Pajak', 'Sumber', 'Tanggal', 'NPWP', 'Nama', 'No. Dokumen / Unit', 'DPP', 'PPN', 'Aksi']),
        JSON.stringify(judul));

    // colspan tfoot harus berhenti tepat sebelum kolom DPP; kalau tidak, angkanya bergeser.
    const tfoot = (html.match(/<tfoot[^>]*>[\s\S]*?<\/tfoot>/) || [''])[0];
    cek('colspan total berhenti tepat sebelum kolom DPP',
        /colspan="7"/.test(tfoot), (tfoot.match(/colspan="\d+"/) || [''])[0]);

    console.log('\n=== 2. KELUARAN — isian FP & NPWP berangkat kosong ===');

    const b101 = barisMemuat(html, 'KONVERSI-UTJDP-10');
    const b102 = barisMemuat(html, 'INV/AR/2609/0001');
    const b55 = barisMemuat(html, 'JRK1-001-II-3');
    cek('ketiga baris ter-render', !!b101 && !!b102 && !!b55);

    /* Vue SSR TIDAK menuliskan value="" untuk nilai kosong, jadi "tidak ada value" dan "tidak ada
       kotak isian sama sekali" akan tampak sama kalau hanya dicari atribut value-nya. Karena itu
       null (tidak ada <input>) dibedakan dari '' (ada <input>, isinya kosong). */
    const isiInput = (s) => {
        const input = (s.match(/<input\b[^>]*>/) || [null])[0];
        if (! input) return null;

        return (input.match(/value="([^"]*)"/) || [null, ''])[1];
    };
    cek('baris tanpa entri: kotak No. Faktur Pajak ada dan kosong', isiInput(sel(b101, 1)) === '', JSON.stringify(isiInput(sel(b101, 1))));
    cek('baris tanpa entri: kotak NPWP ada dan kosong', isiInput(sel(b101, 4)) === '', JSON.stringify(isiInput(sel(b101, 4))));
    cek('baris yang sudah diisi menampilkan nomornya kembali',
        isiInput(sel(b102, 1)) === '010.000-26.00000001', JSON.stringify(isiInput(sel(b102, 1))));
    cek('baris yang sudah diisi menampilkan NPWP-nya kembali',
        isiInput(sel(b102, 4)) === '01.234.567.8-901.000', JSON.stringify(isiInput(sel(b102, 4))));

    console.log('\n=== 3. KELUARAN — nomor dokumen menaut ke JURNALNYA ===');

    cek('baris jurnal 101 menaut ke jurnal id 7',
        /<a href="\/bs\.gl\.journal\.show\/7"/.test(sel(b101, 6)), sel(b101, 6));
    cek('baris jurnal 102 menaut ke jurnal id 12',
        /<a href="\/bs\.gl\.journal\.show\/12"/.test(sel(b102, 6)), sel(b102, 6));
    cek('entri approval yang belum berjurnal TIDAK bertaut', ! /<a href=/.test(sel(b55, 6)), sel(b55, 6));
    cek('tidak ada lagi tautan ke halaman invoice AR', ! html.includes('/bs.ar.show'));

    console.log('\n=== 4. KELUARAN — aksi per baris ===');

    cek('baris belum ber-FP: ada Simpan, tanpa Hapus',
        sel(b101, 9).includes('Simpan') && ! sel(b101, 9).includes('Hapus'), sel(b101, 9));
    cek('baris sudah ber-FP: ada Simpan DAN Hapus',
        sel(b102, 9).includes('Simpan') && sel(b102, 9).includes('Hapus'), sel(b102, 9));
    cek('entri UTJ & DP belum dikonversi: hanya tombol Konversi',
        sel(b55, 9).includes('Konversi ke Uang Muka') && ! sel(b55, 9).includes('Simpan'), sel(b55, 9));
    cek('entri UTJ & DP belum dikonversi: tanpa kotak isian FP',
        ! sel(b55, 1).includes('<input') && sel(b55, 1).includes('konversi dulu'), sel(b55, 1));
    cek('entri approval tidak punya kotak NPWP', ! sel(b55, 4).includes('<input'), sel(b55, 4));

    console.log('\n=== 5. KELUARAN — centang, input massal & impor ===');

    cek('setiap baris punya kotak centangnya sendiri',
        barisTubuh(html).every((b) => /<input type="checkbox"/.test(sel(b, 0))));
    cek('ada kotak centang "pilih semua" di kepala tabel',
        /<th[^>]*>\s*<input type="checkbox"/.test((html.match(/<thead[^>]*>[\s\S]*?<\/thead>/) || [''])[0]));
    // Bawaannya seluruh baris tercentang, tetapi entri yang belum dikonversi tidak ikut dikirim.
    cek('tombol input massal menyebut jumlah baris yang benar-benar akan dikirim',
        html.includes('Input Massal FP &amp; NPWP (2)') || html.includes('Input Massal FP & NPWP (2)'),
        (html.match(/Input Massal FP [^<]*/) || [''])[0]);
    cek('ada tautan unduh Format Impor', html.includes('href="/bs.tax.keluaran.format/'), 'tautan format impor tidak ada');
    cek('ada tombol Impor FP & NPWP', /Impor FP (&amp;|&) NPWP/.test(html));
    cek('ada kotak unggah berkas ber-accept csv', /<input[^>]*type="file"[^>]*accept="[^"]*csv/.test(html));
    cek('rute impor dipakai di berkasnya', srcKel.includes("route('bs.tax.keluaran.impor')"));

    console.log('\n=== 6. KELUARAN — label sumber memakai peta dari server ===');

    cek('sumber "cash" ditampilkan sebagai Kas/Bank', sel(b101, 2).includes('Kas/Bank'), sel(b101, 2));
    cek('sumber "memorial" ditampilkan sebagai Memorial', sel(b102, 2).includes('Memorial'), sel(b102, 2));
    cek('sumber "utj_dp" ditampilkan sebagai Approval UM Penjualan',
        sel(b55, 2).includes('Approval UM Penjualan'), sel(b55, 2));

    const kosong = await render(KELUARAN, {
        rows: [], period: { year: 2026, month: 9 }, sources: SUMBER,
        totals: { dpp: 0, ppn: 0, count: 0, tanpa_fp_count: 0, tanpa_fp_ppn: 0, jurnal_count: 0, entry_count: 0 },
    }, 'keluaran-kosong');
    /* Diperiksa PADA BLOK KOSONGNYA saja. Kode akun yang sama juga tercetak pada kartu ringkasan
       di atas tabel, jadi pencarian se-halaman akan lulus walaupun keterangan kosongnya sudah
       tidak menyebut akun apa pun. */
    const blokKosong = kosong.split('Belum ada faktur keluaran')[1] ?? '';
    cek('keterangan halaman kosong menyebut akun PPN Keluaran yang dibaca',
        blokKosong.includes('20104040406'), blokKosong.slice(0, 200));

    console.log('\n=== 7. MASUKAN — centang, isian kosong & aksi ===');

    const htmlMas = await render(MASUKAN, {
        rows: ROWS_MASUKAN, period: { year: 2026, month: 9 },
        totals: { dpp: 6000000, ppn: 660000, count: 2 },
    }, 'masukan');
    const srcMas = fs.readFileSync(MASUKAN, 'utf8');

    const judulMas = judulKolom(htmlMas);
    cek('kolom pertama Masukan adalah kotak centang', judulMas[0] === '', JSON.stringify(judulMas));
    cek('urutan kolom Masukan tetap: FP, Tanggal, NPWP, Nama Supplier, No. Inv. Supplier, DPP, PPN, Aksi',
        JSON.stringify(judulMas.slice(1)) === JSON.stringify(
            ['No. Faktur Pajak', 'Tanggal', 'NPWP', 'Nama Supplier', 'No. Inv. Supplier', 'DPP', 'PPN', 'Aksi']),
        JSON.stringify(judulMas));

    const m201 = barisMemuat(htmlMas, 'INV-SUP-001');
    const m202 = barisMemuat(htmlMas, 'JKB/2609/0202');
    cek('baris masukan tanpa entri: kotak FP ada dan kosong', isiInput(sel(m201, 1)) === '', JSON.stringify(isiInput(sel(m201, 1))));
    cek('baris masukan tanpa entri: kotak NPWP ada dan kosong', isiInput(sel(m201, 3)) === '', JSON.stringify(isiInput(sel(m201, 3))));
    cek('baris masukan yang sudah diisi menampilkan nomornya kembali',
        isiInput(sel(m202, 1)) === '020.000-26.00000009', JSON.stringify(isiInput(sel(m202, 1))));
    cek('baris masukan tanpa entri: tanpa tombol Hapus',
        ! sel(m201, 8).includes('Hapus'), sel(m201, 8));
    cek('baris masukan yang sudah diisi: ada tombol Hapus', sel(m202, 8).includes('Hapus'), sel(m202, 8));
    cek('nomor jurnal masukan menaut ke jurnalnya',
        /<a href="\/bs\.gl\.journal\.show\/21"/.test(sel(m201, 5)), sel(m201, 5));
    cek('tombol input massal Masukan menyebut jumlah baris terpilih',
        htmlMas.includes('Input Massal FP &amp; NPWP (2)') || htmlMas.includes('Input Massal FP & NPWP (2)'),
        (htmlMas.match(/Input Massal FP [^<]*/) || [''])[0]);
    cek('hapus di Masukan memakai rute baris, bukan rute invoice',
        srcMas.includes("route('bs.tax.baris.hapus')") && ! srcMas.includes("bs.tax.masukan.hapus"));

    /* Modal input massal HARUS mengulang baris terpilih, bukan seluruh baris: bulk[r.id] hanya
       dibentuk untuk baris terpilih, jadi menampilkan seluruh baris membuat modalnya patah saat
       ada satu saja baris yang centangnya dilepas. Diperiksa pada template, sebab modalnya
       memang belum ter-render sebelum dibuka. */
    // Diperiksa pada POTONGAN MODAL-nya saja: tabel utama memang mengulang seluruh baris, jadi
    // pencarian se-template akan lulus/gagal palsu.
    const modal = (src) => {
        const t = (src.match(/<template>[\s\S]*<\/template>/) || [''])[0].replace(/<!--[\s\S]*?-->/g, '');

        return t.split('v-if="showBulk"')[1] ?? '';
    };
    const modalMas = modal(srcMas);
    cek('modal massal Masukan mengulang barisTerpilih, bukan seluruh rows',
        modalMas.includes('v-for="r in barisTerpilih"') && ! /v-for="r in rows"/.test(modalMas),
        'modal masih mengulang seluruh rows');
    const modalKel = modal(srcKel);
    cek('modal massal Keluaran mengulang barisTerpilih, bukan seluruh rows',
        modalKel.includes('v-for="r in barisTerpilih"') && ! /v-for="r in (rows|filtered)"/.test(modalKel),
        'modal keluaran tidak memakai barisTerpilih');

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
