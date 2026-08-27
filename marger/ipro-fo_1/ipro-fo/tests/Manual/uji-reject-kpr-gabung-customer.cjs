// Render RejectProcess.vue YANG SEBENARNYA dan periksa penggabungan per customer.
//
// Pemeriksaan intinya KEUTUHAN TABEL: tiap baris menempati lebar yang sama persis setelah
// rowspan dari baris di atasnya diperhitungkan. Rowspan yang keliru tidak merusak tabelnya —
// ia hanya menggeser isinya satu kolom sehingga alasan penolakan duduk di bawah judul Bank,
// dan itu tidak terlihat oleh siapa pun.
//
// Yang kedua: pengelompokan memakai customer_id, BUKAN nama. Dua orang berbeda yang kebetulan
// bernama sama tidak boleh tergabung — penolakan milik orang lain akan terbaca sebagai miliknya.
//
// Jalankan:  node tests/Manual/uji-reject-kpr-gabung-customer.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RejectProcess.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
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

const tolak = (id, custId, nama, no, bank, unit, stage, alasan, tgl) => ({
    id, customer_id: custId, customer: nama, number: no, bank, unit, stage,
    reason: alasan, rejected_at: tgl,
});

// GUNI muncul dua kali pada tangkapan layar — dua bank, dua tahap, dua alasan, dua tanggal.
const DATA = [
    tolak(6, 11, 'GUNI', 'KPR/JRES/2608/00006', 'Bank Rakyat Indonesia', 'JRK3-004-XVIII-17', 'Submitted', 'ETSS GAJADI', '2026-08-07'),
    tolak(15, 12, 'ARI CHUMAIDI', 'KPR/JRES/2607/00015', 'Bank Rakyat Indonesia', 'JRK3-004-XVII-26', 'SP3K', 'RPC KURANG', '2026-07-03'),
    tolak(5, 11, 'GUNI', 'KPR/JRES/2607/00006', 'BSN', 'JRK3-004-XVIII-20', 'Submitted', 'REJECT BANK', '2026-07-03'),
    tolak(4, 13, 'Andi Saputra', 'KPR/JRES/2606/00004', 'Bank Mandiri', 'JRK3-004-XV-22', 'Submitted', 'DATA DIRAGUKAN', '2026-06-30'),
    // Nama SAMA dengan yang di atas tetapi ORANG BERBEDA (customer_id berbeda).
    tolak(3, 14, 'Andi Saputra', 'KPR/JRES/2606/00003', 'Bank Negara Indonesia', 'JRK3-004-XV-23A', 'Submitted', 'RPC TIDAK CUKUP', '2026-06-30'),
    // Tanpa customer sama sekali — tidak boleh saling menempel.
    tolak(2, null, null, 'KPR/JRES/2605/00002', 'Bank Mandiri', 'JRK3-004-XV-10', 'SP3K', null, '2026-05-11'),
    tolak(1, null, null, 'KPR/JRES/2605/00001', 'BSN', 'JRK3-004-XV-11', 'Submitted', null, '2026-05-10'),
];

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

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const app = createSSRApp({ render: () => h(modul.exports.default, { records: DATA }) });
    app.config.globalProperties.route = (n, p) => '/' + n + (p ? '/' + JSON.stringify(p) : '');
    const html = await renderToString(app);
    // Komentar template ikut ter-render pada SSR (di build produksi ia dibuang). Dibuang di
    // sini supaya perhitungan elemen tidak ikut menghitung teks di dalam komentar — itulah
    // yang sempat membuat pemeriksaan ini merah padahal kodenya benar.
    const tabel = ((html.match(/<table[\s\S]*?<\/table>/) || [''])[0]).replace(/<!--[\s\S]*?-->/g, '');
    const teks = tabel.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    if (process.env.DUMP) { fs.writeFileSync(process.env.DUMP, tabel); }

    console.log('\n=== 1. Urutan kolom — Customer paling kiri ===');

    const th = [...tabel.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1].trim()).filter(Boolean);
    cek('Customer mendahului No. KPR',
        th.indexOf('Customer') >= 0 && th.indexOf('Customer') < th.indexOf('No. KPR'), th.join('|'));
    cek('urutannya sesuai permintaan',
        th.join('|').includes('Customer|No. KPR|Bank|Unit|Ditolak di Tahap|Alasan Penolakan|Tgl Ditolak|Aksi'), th.join('|'));

    console.log('\n=== 2. Keutuhan tabel — tiap baris selebar sama ===');

    const lebar = lebarPerBaris(tabel);
    const unik = [...new Set(lebar)];
    cek('semua baris selebar sama', unik.length === 1,
        'lebar yang muncul: ' + unik.join(',') + ' → ' + lebar.join(','));

    console.log('\n=== 3. Pengajuan digabungkan per customer ===');

    const trIsi = (tabel.split('</thead>')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];
    cek('tujuh baris penolakan tetap utuh', trIsi.length === 7, String(trIsi.length));
    // GUNI punya dua penolakan → namanya ditulis SEKALI, membentang dua baris.
    cek('nama GUNI hanya ditulis sekali', (teks.match(/GUNI/g) || []).length === 1,
        String((teks.match(/GUNI/g) || []).length));
    cek('sel customernya membentang dua baris', tabel.includes('rowspan="2"'), 'tidak ditemukan');
    cek('jumlah penolakannya disebut', teks.includes('2× ditolak'), 'tidak ditemukan');
    cek('yang hanya sekali tidak diberi lencana', teks.includes('1 penolakan'), 'tidak ditemukan');

    console.log('\n=== 4. Nama sama TAPI orang berbeda tidak digabung ===');

    // Inilah yang membedakan pengelompokan lewat id dari pengelompokan lewat nama.
    cek('"Andi Saputra" tetap dua baris terpisah',
        (teks.match(/Andi Saputra/g) || []).length === 2,
        String((teks.match(/Andi Saputra/g) || []).length));
    cek('keduanya bukan satu kelompok ber-rowspan',
        ! tabel.includes('rowspan="3"'), 'ada kelompok yang terlalu besar');

    console.log('\n=== 5. Baris tanpa customer tidak saling menempel ===');

    // Dua baris tanpa customer harus berdiri sendiri-sendiri, bukan tergabung jadi satu
    // kelompok "—" yang menyiratkan keduanya orang yang sama.
    const emDash = (teks.match(/—/g) || []).length;
    cek('dua baris tanpa customer tetap terpisah', emDash >= 2, String(emDash));

    console.log('\n=== 6. Isi tiap penolakan tetap lengkap ===');

    ['KPR/JRES/2608/00006', 'KPR/JRES/2607/00006', 'Bank Rakyat Indonesia', 'BSN',
        'ETSS GAJADI', 'REJECT BANK', 'JRK3-004-XVIII-17'].forEach((v) => {
        cek('"' + v + '" tercetak', teks.includes(v), 'tidak ditemukan');
    });
    cek('penolakan tanpa alasan dikatakan apa adanya', teks.includes('tanpa alasan'), 'tidak ditemukan');

    console.log('\n=== 7. Aksi & pilihan massal tidak hilang ===');

    // Tiap penolakan tetap punya kotak centang & tombol memonya sendiri — memo dibuat
    // per pengajuan, bukan per customer.
    const centang = (tabel.match(/type="checkbox"/g) || []).length;
    // 7 penolakan + 6 kelompok (dua penolakan GUNI bergabung jadi satu) + 1 pilih-semua.
    cek('kotak centang per baris + per customer + pilih semua',
        centang === 7 + 6 + 1, String(centang));
    cek('tombol Memo Rekom Batal ada di tiap baris',
        (tabel.match(/Memo Rekom Batal/g) || []).length === 7,
        String((tabel.match(/Memo Rekom Batal/g) || []).length));
    cek('saringan kolom masih terpasang',
        (fs.readFileSync(BERKAS, 'utf8').match(/ColumnFilter v-model="col\./g) || []).length === 7,
        'jumlah saringan berubah');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
