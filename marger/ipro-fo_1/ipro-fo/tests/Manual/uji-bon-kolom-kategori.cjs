// Kolom "Kategori" pada daftar Bon Sementara — Reguler / Debit VA.
//
// KENAPA PERLU UJI: kategorinya dibaca dari payment_method, yang BERBAWAAN 'reguler' sejak bon
// dibuat — jauh sebelum ada pembayaran. Membacanya sendirian akan melabeli bon yang masih
// "Diajukan" sebagai "Pembayaran Reguler", padahal belum satu rupiah pun keluar dan caranya
// bahkan belum dipilih. Penandanya paid_date. Itulah yang dijaga di sini, di kedua tabel.
//
// Dijaga juga LEBAR TABELNYA: menambah satu kolom tanpa menyesuaikan colspan pada baris Total
// tidak menimbulkan galat apa pun — garis totalnya hanya berhenti sebelum ujung tabel, dan
// biasanya tak ada yang menyadarinya sampai ada yang mencetak halamannya.
//
// Jalankan:  node tests/Manual/uji-bon-kolom-kategori.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Index.vue');

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
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {}, post(){}, put(){} });'
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

// Empat keadaan yang harus terbaca berbeda pada kolom Kategori.
const ROWS = [
    { id: 1, advance_number: 'BON/2609/0015', advance_date: '2026-09-04', holder: 'Citra Lestari',
      purpose: 'belum dibayar', amount: 2500000, status: 'requested', settlement: 0,
      payment_method: 'reguler', paid_date: null, received_document_date: null },
    { id: 2, advance_number: 'BON/2609/0011', advance_date: '2026-09-03', holder: 'Andi Wijaya',
      purpose: 'dibayar reguler', amount: 10000000, status: 'outstanding', settlement: 0,
      payment_method: 'reguler', paid_date: '2026-09-05', received_document_date: null },
    { id: 3, advance_number: 'BON/2609/0010', advance_date: '2026-09-03', holder: 'Eko Prasetyo',
      purpose: 'dibayar via VA', amount: 6300000, status: 'outstanding', settlement: 0,
      payment_method: 'debit_va', paid_date: '2026-09-05', va_expired_date: '2026-09-20',
      received_document_date: null },
    // Bon lama: payment_method belum pernah terisi sama sekali.
    { id: 4, advance_number: 'BON/2607/0005', advance_date: '2026-07-19', holder: 'Eko Prasetyo',
      purpose: 'bon lama', amount: 50000, status: 'outstanding', settlement: 0,
      payment_method: null, paid_date: '2026-07-20', received_document_date: null },
];
const BUCKET_ROWS = [
    { id: 5, advance_number: 'BON/2607/0004', advance_date: '2026-07-04', realized_date: '2026-07-19',
      holder: 'Budi Santoso', purpose: 'realisasi VA', amount: 10000, realized_amount: 10000,
      settlement: 0, status: 'realized', payment_method: 'debit_va', paid_date: '2026-07-05',
      received_document_date: '2026-07-19' },
];
const BUCKETS = {
    realisasi: { rows: BUCKET_ROWS, count: 1, total: 10000 },
    refund: { rows: [], count: 0, total: 0 },
    reimburse: { rows: [], count: 0, total: 0 },
};

/** Jumlah sel per baris <tr> pada sebuah bagian tabel (memperhitungkan colspan). */
function lebarBaris(bagian) {
    return [...bagian.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((m) =>
        [...m[1].matchAll(/<t[dh]([^>]*)>/g)]
            .reduce((n, c) => n + (Number((c[1].match(/colspan="(\d+)"/) || [])[1]) || 1), 0));
}

async function render(props) {
    const out = path.join(ROOT, 'kategori.bundle.tmp.cjs');
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
    app.config.warnHandler = () => {};   // stub ikon lucide

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

(async () => {
    const props = {
        advances: { data: ROWS, total: ROWS.length, links: [], from: 1, to: ROWS.length },
        filters: {}, summary: { outstanding: 0, count: 0 },
        buckets: BUCKETS, canEditDocDate: false,
    };
    const html = await render(props);

    console.log('\n=== 1. Kolom Kategori ada di kedua tabel ===');

    const src = fs.readFileSync(BERKAS, 'utf8');
    cek('judul kolom Kategori muncul di daftar utama', html.includes('Kategori'));
    cek('kolomnya juga dipasang pada tabel bucket',
        (src.match(/>Kategori<\/th>/g) || []).length === 2,
        (src.match(/>Kategori<\/th>/g) || []).length + ' judul kolom');

    console.log('\n=== 2. Isinya sesuai keadaan tiap bon ===');

    // Baris dipetakan lewat nomor bonnya supaya urutannya tidak jadi asumsi tersembunyi.
    const baris = {};
    for (const m of html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)) {
        const no = (m[1].match(/BON\/[\d/]+/) || [])[0];
        if (no) baris[no] = m[1];
    }
    cek('keempat baris utama ter-render', Object.keys(baris).length >= 4, Object.keys(baris).join(', '));

    cek('bon BELUM dibayar → "—", bukan Reguler',
        baris['BON/2609/0015'] && ! baris['BON/2609/0015'].includes('>Reguler<')
            && baris['BON/2609/0015'].includes('—'),
        'baris bon belum dibayar salah label');
    cek('bon dibayar reguler → Reguler', (baris['BON/2609/0011'] || '').includes('>Reguler<'));
    cek('bon dibayar VA → Debit VA', (baris['BON/2609/0010'] || '').includes('>Debit VA<'));
    // payment_method NULL pada bon lama yang sudah dibayar: dibaca reguler, bukan kosong —
    // bawaan kolomnya memang reguler, dan bon itu memang dibayar dengan cara biasa.
    cek('bon lama (payment_method kosong) → Reguler', (baris['BON/2607/0005'] || '').includes('>Reguler<'));

    console.log('\n=== 3. Keterangan lengkapnya ada di tooltip ===');

    // Kolomnya sempit; yang dicari sekilas JENISnya, keterangan penuh menyusul saat disinggahi.
    cek('tooltip menyebut Pembayaran Debit VA', html.includes('Pembayaran Debit VA'));
    cek('tooltip VA menyertakan tanggal expired-nya', html.includes('expired'), 'tanggal expired tidak disebut');
    cek('tooltip reguler menyebut transfer / tunai', html.includes('transfer / tunai'));

    console.log('\n=== 4. Lebar tabelnya tetap konsisten ===');

    // Menambah kolom tanpa menyesuaikan colspan baris Total tidak menimbulkan galat apa pun —
    // garis totalnya hanya berhenti sebelum ujung tabel.
    const tabelUtama = (html.match(/<table[\s\S]*?<\/table>/g) || [])[0] ?? '';
    const lebarUtama = lebarBaris(tabelUtama);
    const unikUtama = [...new Set(lebarUtama)];
    cek('daftar utama: semua barisnya selebar sama', unikUtama.length === 1, unikUtama.join(','));
    cek('daftar utama selebar 8 kolom', unikUtama[0] === 8, String(unikUtama[0]));

    /* Tabel bucket (tab Realisasi/Refund/Reimburse) diukur dari MARKAHNYA, bukan dari render:
       tab aktifnya sebuah ref lokal berbawaan 'all' dan tidak bisa disetel dari props, jadi
       tabel itu tidak pernah ter-render pada uji ini. Yang diperiksa justru tempat cacatnya
       berada — kesepakatan antara jumlah kolom di <thead> dan colspan di baris Total. */
    const tabelKedua = (src.match(/<table[\s\S]*?<\/table>/g) || [])[1] ?? '';
    const thead = (tabelKedua.match(/<thead[\s\S]*?<\/thead>/) || [''])[0];
    const semuaTh = (thead.match(/<th[\s>]/g) || []).length;
    // Dua <th> di dalamnya saling meniadakan (v-if refund / v-else-if reimburse): pada tab
    // Realisasi tak satu pun tampil, pada dua tab lainnya tepat satu.
    const lebarRealisasi = semuaTh - 2;
    const lebarRefund = semuaTh - 1;
    cek('kepala tabel bucket terbaca', semuaTh > 0, String(semuaTh));

    const tfoot = (tabelKedua.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    // colspan pertama bercabang (realisasi ? 6 : 7); sisanya angka tetap.
    const cabang = tfoot.match(/:colspan="activeTab === 'realisasi' \? (\d+) : (\d+)"/);
    const tetap = [...tfoot.matchAll(/\scolspan="(\d+)"/g)].reduce((n, m) => n + Number(m[1]), 0);
    const selPolos = (tfoot.match(/<td(?![^>]*colspan)[^>]*>/g) || []).length;
    cek('baris Total memakai colspan bercabang', cabang !== null, tfoot.replace(/\s+/g, ' ').slice(0, 120));

    const totalRealisasi = Number(cabang?.[1] ?? 0) + tetap + selPolos;
    const totalRefund = Number(cabang?.[2] ?? 0) + tetap + selPolos;
    cek('tab Realisasi: lebar baris Total = jumlah kolomnya',
        totalRealisasi === lebarRealisasi, totalRealisasi + ' vs ' + lebarRealisasi + ' kolom');
    cek('tab Refund/Reimburse: lebar baris Total = jumlah kolomnya',
        totalRefund === lebarRefund, totalRefund + ' vs ' + lebarRefund + ' kolom');

    console.log('\n=== 5. Sisi server mengirim datanya ===');

    // Baris bucket dibentuk dari daftar kolom yang ditulis tangan di controller — bila
    // payment_method/paid_date tidak ikut, kolomnya diam-diam selalu "—".
    const ctrl = fs.readFileSync(path.join(ROOT,
        'app/Http/Controllers/Web/BusinessSupport/CashBook/CashAdvanceController.php'), 'utf8');
    cek('payment_method ikut dikirim pada baris bucket', ctrl.includes("'payment_method'         => $a->payment_method,"));
    cek('paid_date ikut dikirim pada baris bucket', ctrl.includes("'paid_date'              => $a->paid_date?->toDateString(),"));

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message + '\n' + e.stack); process.exit(1); });
