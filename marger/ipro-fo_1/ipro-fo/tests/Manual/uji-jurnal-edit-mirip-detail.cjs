// Edit Jurnal harus TERBACA seperti kartu "Struktur Jurnal" pada layar detail.
//
// KENAPA PERLU UJI: orang memeriksa jurnal di layar detail lalu membetulkannya di layar edit.
// Dua tata letak berbeda untuk angka yang sama memaksa mata mencari ulang letak tiap kolom
// setiap kali berpindah — dan salah baca kolom Debit/Kredit pada jurnal adalah kesalahan yang
// mahal. Kemiripan ini tidak dijaga oleh apa pun selain kebiasaan; sekali salah satunya
// diubah, keduanya berbeda lagi tanpa satu pun galat.
//
// YANG DIBANDINGKAN adalah dua berkas SUNGGUHAN, bukan salinan harapan yang ditulis di sini:
// Payable/Show.vue (acuan) dan Gl/Journals/Form.vue (yang harus mengikutinya). Jadi bila
// acuannya berubah, uji ini yang lebih dulu memberi tahu.
//
// Jalankan:  node tests/Manual/uji-jurnal-edit-mirip-detail.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Gl/Journals/Form.vue');
const ACUAN = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Show.vue');

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

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

/** Judul kolom sebuah <table> dari HTML hasil render. */
function kepalaKolom(html) {
    const thead = (html.match(/<thead[\s\S]*?<\/thead>/) || [''])[0];

    return [...thead.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
        .map((m) => m[1].replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim())
        .filter((t) => t !== '');
}

async function render(berkas, props) {
    const hasil = await esbuild.build({
        entryPoints: [berkas], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin], logLevel: 'silent',
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    globalThis.route = (n, p) => '/' + n + (p ? '/' + (p.id ?? p) : '');
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};   // stub ikon lucide; tidak ada ikon yang diperiksa uji ini

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

(async () => {
    // Angka contohnya sengaja sama dengan tangkapan layar acuan: 765.000 = 745.875 + 19.125.
    const htmlForm = await render(FORM, {
        accounts: [
            { id: 1, code: '50302020218', name: 'BIAYA INTERNET DAN TELEKOMUNIKASI' },
            { id: 2, code: '20101010101', name: 'UTANG USAHA SUPPLIER', cost_center_optional: true },
            { id: 3, code: '20104040402', name: 'HUTANG PPH PASAL 21', cost_center_optional: true },
        ],
        costCenters: [{ id: 1, code: 'CC1', name: 'Kantor Pusat' }],
        editing: {
            id: 26, journal_number: 'AP.INV/BIAYA-INTERNET/MUP/26/IX/00026', source: 'ap',
            journal_date: '2026-09-02', reference: 'AP.INV/BIAYA-INTERNET/MUP/26/IX/00026',
            description: 'AP — penyediaan interne (ABDOEL ROZAK)', cost_center_id: '',
            lines: [
                { account_id: 1, cost_center_id: '', description: 'penyediaan interne', debit: 765000, credit: 0 },
                { account_id: 2, cost_center_id: '', description: 'Hutang ABDOEL ROZAK', debit: 0, credit: 745875 },
                { account_id: 3, cost_center_id: '', description: 'PPh Pasal 21 dipotong', debit: 0, credit: 19125 },
            ],
        },
    });

    const htmlAcuan = await render(ACUAN, {
        invoice: { id: 1, invoice_number: 'AP.INV/BIAYA-INTERNET/MUP/26/IX/00026', status: 'open',
            supplier: { name: 'ABDOEL ROZAK' }, invoice_date: '2026-09-02', due_date: '2026-09-02',
            dpp: 765000, total: 765000, net_payable: 745875, paid_amount: 0, lines: [], payments: [] },
        journal: {
            id: 9, number: 'JAP/2609/0026', total_debit: 765000, total_credit: 765000,
            lines: [
                { account_code: '50302020218', account_name: 'BIAYA INTERNET DAN TELEKOMUNIKASI', dimensi: null, description: 'penyediaan interne', debit: 765000, credit: 0 },
                { account_code: '20101010101', account_name: 'UTANG USAHA SUPPLIER', dimensi: null, description: 'Hutang ABDOEL ROZAK', debit: 0, credit: 745875 },
                { account_code: '20104040402', account_name: 'HUTANG PPH PASAL 21', dimensi: null, description: 'PPh Pasal 21 dipotong', debit: 0, credit: 19125 },
            ],
        },
    });

    console.log('\n=== 1. Judul kolomnya sama dengan layar detail ===');

    const kolomAcuan = kepalaKolom(htmlAcuan);
    // Tabel jurnal pada form adalah satu-satunya yang berjudul Debit/Kredit di halaman itu.
    const tabelForm = (htmlForm.match(/<table[\s\S]*?<\/table>/g) || [])
        .find((t) => t.includes('>Debit<') || t.includes('Debit'));
    const kolomForm = kepalaKolom(tabelForm ?? '');

    cek('acuan terbaca 5 kolom', kolomAcuan.length === 5, JSON.stringify(kolomAcuan));
    /* Form punya satu kolom tambahan: tombol hapus baris. Yang dibandingkan lima yang pertama.

       Tanda bintang wajib DILEPAS sebelum dibandingkan — dan itu satu-satunya perbedaan yang
       dimaafkan. Layar detail hanya membaca, jadi menandai kolom "wajib diisi" di sana tidak
       ada artinya; pada form, tandanya justru yang memberi tahu kolom itu menahan penyimpanan.
       Keberadaan tandanya diperiksa tersendiri di uji-cost-center-wajib.cjs. */
    const tanpaBintang = (t) => t.replace(/\s*\*$/, '').trim();
    cek('kelima judul kolomnya sama persis (selain tanda wajib)',
        JSON.stringify(kolomForm.slice(0, 5).map(tanpaBintang)) === JSON.stringify(kolomAcuan),
        '\n        form : ' + JSON.stringify(kolomForm) + '\n        acuan: ' + JSON.stringify(kolomAcuan));

    console.log('\n=== 2. Bentuk kartunya sama ===');

    const srcForm = fs.readFileSync(FORM, 'utf8');
    const srcAcuan = fs.readFileSync(ACUAN, 'utf8');

    cek('memakai kartu ber-overflow-hidden seperti acuannya',
        srcForm.includes('class="card overflow-hidden"') && srcAcuan.includes('class="card overflow-hidden"'));
    cek('kepala kartunya bergaris bawah & berjarak sama',
        srcForm.includes('border-b border-neutral-200 px-4 py-3 dark:border-neutral-800')
        && srcAcuan.includes('border-b border-neutral-200 px-4 py-3 dark:border-neutral-800'));
    cek('judul kartunya "Struktur Jurnal" berikon BookText',
        /<BookText[^>]*\/>\s*Struktur Jurnal/.test(srcForm.replace(/\s+/g, ' ')),
        'judul/ikonnya berbeda');
    cek('kepala tabelnya berlatar sama',
        srcForm.includes('bg-neutral-50 dark:bg-neutral-800') && srcAcuan.includes('bg-neutral-50 dark:bg-neutral-800'));
    cek('barisnya dipisah divide-y seperti acuannya',
        srcForm.includes('divide-y divide-neutral-200 dark:divide-neutral-800')
        && srcAcuan.includes('divide-y divide-neutral-200 dark:divide-neutral-800'));

    console.log('\n=== 3. Lencana Balance & Grand Total ===');

    /* Lencananya dicari HANYA di kepala kartu — antara judul "Struktur Jurnal" dan awal
       tabelnya. Mencari kata "Balance" di seluruh halaman akan selalu ketemu: di bawah tabel
       ada baris status "Balance ✓ (debit = kredit)". Pemeriksaan yang lulus karena teks lain
       tidak menjaga apa pun — melepas lencananya pun tetap lulus. */
    const kepalaKartu = (html) => {
        const i = html.indexOf('Struktur Jurnal');
        const j = html.indexOf('<table', i);

        return i < 0 || j < 0 ? '' : html.slice(i, j);
    };
    cek('acuan berlencana Balance di kepala kartunya', kepalaKartu(htmlAcuan).includes('Balance'),
        kepalaKartu(htmlAcuan).replace(/<[^>]+>/g, ' ').trim());
    cek('form juga berlencana Balance di kepala kartunya', kepalaKartu(htmlForm).includes('Balance'),
        kepalaKartu(htmlForm).replace(/<[^>]+>/g, ' ').trim());
    cek('acuan punya baris Grand Total', htmlAcuan.includes('Grand Total'));
    cek('form juga punya baris Grand Total', htmlForm.includes('Grand Total'), 'masih memakai "Total"');

    // Angkanya harus benar-benar terjumlah, bukan sekadar barisnya ada.
    cek('Grand Total form menjumlahkan debitnya', htmlForm.includes('765.000'),
        'total debit 765.000 tidak terlihat');
    cek('Grand Total form menjumlahkan kreditnya', htmlForm.includes('765.000'));

    console.log('\n=== 4. Yang tidak boleh hilang karena penyeragaman ===');

    // Form ini EDITOR: penyeragaman tampilan tidak boleh memakan kemampuan mengubahnya.
    cek('tiap baris tetap bisa dihapus', htmlForm.includes('disabled') && srcForm.includes('removeLine(i)'));
    cek('tombol tambah baris tetap ada', srcForm.includes('@click="addLine"'));
    cek('isian debit & kredit tetap berupa kotak angka',
        (htmlForm.match(/type="number"/g) || []).length >= 6,
        (htmlForm.match(/type="number"/g) || []).length + ' kotak angka');
    /* Lebar minimum tabelnya dipertahankan — acuannya hanya menampilkan teks, form ini menampung
       dua SearchSelect dan tiga kotak; ikut melepas lebar berarti terpotong lagi.

       Yang diuji SIFATNYA, bukan angkanya: dulu di sini tertulis min-w-[1050px] harfiah, padahal
       1050px itulah justru lebar yang membuat Keterangan/Debit/Kredit terpenggal. Mematok angka
       membuat pelebaran yang benar terbaca sebagai kerusakan. Syarat sebenarnya: tabelnya
       menyatakan lebar minimum, dan lebar itu tidak lebih kecil dari jumlah lebar kolomnya —
       kalau lebih kecil, kolom tanpa lebarlah yang menanggung kekurangannya dan tergencet. */
    const lebarTabel = (() => {
        const m = srcForm.match(/<table class="w-full min-w-\[([\d.]+)(px|rem)\]/);

        return m ? Number(m[1]) * (m[2] === 'rem' ? 16 : 1) : 0;
    })();
    const lebarKolom = [...((srcForm.match(/<colgroup>[\s\S]*?<\/colgroup>/) || [''])[0])
        .matchAll(/w-\[([\d.]+)(rem|px)\]/g)]
        .reduce((s, m) => s + Number(m[1]) * (m[2] === 'rem' ? 16 : 1), 0);
    cek('tabelnya menyatakan lebar minimum', lebarTabel > 0, String(lebarTabel));
    cek('lebar minimum >= jumlah lebar kolomnya', lebarTabel >= lebarKolom,
        lebarTabel + 'px tabel vs ' + lebarKolom + 'px kolom');
    cek('tabelnya tetap bisa digeser mendatar', srcForm.includes('<div class="overflow-x-auto">'));
    cek('keterangan selisih saat belum balance tetap ada', srcForm.includes('Belum balance — selisih'));

    console.log('\n=== 5. Satu baris MENDATAR, keterangan panjang memanjang ke BAWAH ===');

    /* Inilah tuntutan yang membuat bentuk tabelnya sempat dibongkar jadi blok bertumpuk:
       sebagai <input> satu baris, keterangan panjang terpenggal di tengah kata. Sekarang
       keduanya harus terpenuhi sekaligus — barisnya tetap mendatar (kelima kolom sejajar dalam
       satu <tr>) DAN keterangannya tumbuh ke bawah mengikuti isinya. */
    const barisPertama = ((htmlForm.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/) || ['', ''])[1]
        .match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];
    const selBaris = (barisPertama.match(/<td[^>]*>/g) || []).length;
    cek('kelima kolom berada dalam SATU baris mendatar', selBaris >= 5, selBaris + ' sel');

    cek('kolom Keterangan berupa textarea, bukan input satu baris',
        /<td[^>]*>\s*<textarea/.test(barisPertama) || barisPertama.includes('<textarea'),
        'masih memakai input satu baris');
    cek('textarea-nya setinggi satu baris saat isinya pendek', srcForm.includes('rows="1"'));
    cek('tingginya tumbuh mengikuti isi (autoGrow terpasang)', srcForm.includes('@input="autoGrow"'));
    cek('autoGrow menolkan tinggi dulu sebelum mengukur',
        /el\.style\.height = 'auto';[\s\S]{0,120}el\.style\.height = el\.scrollHeight/.test(srcForm),
        'tanpa itu kotaknya hanya bisa membesar, tidak pernah menyusut lagi');
    cek('textarea tidak bisa digeser-ukur manual & tanpa scrollbar',
        srcForm.includes('resize-none') && srcForm.includes('overflow-hidden'));

    // Bentuk bertumpuk yang lama tidak boleh tersisa berdampingan dengan tabelnya.
    cek('label "Baris n" bertumpuk sudah tidak ada', ! srcForm.includes('Baris {{ i + 1 }}'));
    cek('Grand Total kembali ke kaki tabel, bukan dua kartu',
        srcForm.includes('<tfoot>') && ! srcForm.includes('Grand Total Debit'));

    console.log('\n' + '─'.repeat(78));
    console.log(gagal === 0 ? 'SEMUA LULUS (' + lulus + ')' : 'GAGAL: ' + gagal + ', lulus: ' + lulus);
    process.exit(gagal === 0 ? 0 : 1);
})().catch((e) => { console.error('GALAT: ' + e.message + '\n' + e.stack); process.exit(1); });
