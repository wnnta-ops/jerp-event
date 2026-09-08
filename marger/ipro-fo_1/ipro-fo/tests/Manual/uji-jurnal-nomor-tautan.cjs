// Detail Transaksi Jurnal — nomor jurnal yang bisa diklik menggantikan tombol "Lihat →".
//
// KENAPA PERLU UJI:
//  · Tabel ini selebar ~125rem. Tombol "Lihat →" berada di kolom PALING KANAN, yang pada layar
//    biasa harus digeser dulu untuk terlihat — jalan menuju rincian justru bersembunyi. Setelah
//    dipindahkan ke nomor jurnal (kolom paling kiri, selalu tampak), yang harus dijaga adalah:
//    tautannya benar-benar ada, menunjuk jurnal yang BENAR, dan kolom aksinya benar-benar hilang.
//  · Menghapus satu kolom tanpa menyesuaikan <colgroup> dan colspan tidak menimbulkan galat apa
//    pun — kolomnya hanya bergeser diam-diam, dan biasanya baru ketahuan saat ada yang mencetak.
//  · Nomor jurnal bergaya font-mono, sama seperti kolom COA di sebelahnya. Kalau penandanya
//    hanya warna, tidak ada yang membedakan mana yang bisa diklik — jadi garis bawah saat
//    disentuh ikut dikunci.
//
// Jalankan:  node tests/Manual/uji-jurnal-nomor-tautan.cjs .
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
                  // Link dirender sebagai <a href> supaya tautannya benar-benar bisa diperiksa,
                  // bukan sekadar "komponennya dipakai".
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

/* Jurnal uji SENGAJA BERBARIS BANYAK. Dengan rincian terbuka (defaultDetail), tiap baris akun
   menjadi satu <tr> tersendiri — dan hanya dengan begitu perbedaan antara "tautan di tiap baris"
   dan "tautan hanya di baris pertama" bisa terlihat. Fikstur berbaris-nol membuat i === 0 selalu
   benar, sehingga keduanya tampak sama; uji mutasi menemukan celah itu. */
const baris = (id, kode, nama, debit, credit) => ({
    id, account: { code: kode, name: nama }, description: 'baris ' + kode, debit, credit,
});
const JURNAL = [
    { id: 7, journal_number: 'JKB/2609/0165', journal_date: '2026-09-30', source: 'cash',
      description: 'Reimburse Bon Sementara BON/2609/0010', reference: 'BON/2609/0010',
      status: 'posted', lines_count: 2, total_debit: 500000, total_credit: 500000,
      period_closed: false, cost_centers: [], cost_center_kurang: [],
      lines: [baris(101, '10107030303', 'UANG MUKA OPERASIONAL', 500000, 0),
              baris(102, '10101020201', 'BTN REK.393-01-30-000374-9', 0, 500000)] },
    { id: 12, journal_number: 'JKB/2609/0170', journal_date: '2026-09-07', source: 'cash',
      description: 'Realisasi Bon Sementara BON/2609/0016', reference: 'BON/2609/0016',
      status: 'posted', lines_count: 3, total_debit: 900000, total_credit: 900000,
      period_closed: false, cost_centers: ['CC-FIN — Finance & Accounting'], cost_center_kurang: [],
      lines: [baris(201, '10106030305', 'PREPAID BIAYA SALES & MARKETING', 600000, 0),
              baris(202, '70101010102', 'BIAYA ADMINISTRASI BANK', 300000, 0),
              baris(203, '10101020210', 'MANDIRI REK. 141-00-9988886-9', 0, 900000)] },
];

async function render() {
    const out = path.join(ROOT, 'nomor-tautan.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [BERKAS], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    // route() ditiru apa adanya: nama rute + id, supaya href-nya bisa dicocokkan persis.
    globalThis.route = (n, p) => '/' + n + (p != null ? '/' + (p?.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, {
        journals: { data: JURNAL, total: JURNAL.length, links: [], from: 1, to: JURNAL.length },
        sources: { cash: 'Kas/Bank' }, statuses: { posted: 'Approved', final: 'Final', draft: 'Baru' },
        filters: {}, defaultDetail: true, listRoute: 'bs.gl.journal.detail',
        title: 'Detail Transaksi Jurnal', canApprove: true,
        jumlahStatus: { baru: 0, final: 16, approved: 8, closed: 0 }, tahunRekap: 2026,
        opsiKolom: {}, batasOpsi: 500,
    }) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

/** Isi satu baris <tr> yang memuat sebuah untai. */
function barisMemuat(html, untai) {
    for (const m of html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)) {
        if (m[1].includes(untai)) return m[1];
    }

    return null;
}

(async () => {
    const html = await render();
    const src = fs.readFileSync(BERKAS, 'utf8');

    console.log('\n=== 1. Nomor jurnal menjadi tautan ke rinciannya ===');

    const b1 = barisMemuat(html, 'JKB/2609/0165');
    const b2 = barisMemuat(html, 'JKB/2609/0170');
    cek('kedua baris jurnal ter-render', !!b1 && !!b2);

    // href-nya harus menunjuk jurnal MASING-MASING, bukan sekadar "ada tautan".
    const href = (baris) => (baris.match(/<a href="([^"]*)"[^>]*>[^<]*JKB\//) || [])[1]
        ?? (baris.match(/<a href="([^"]*)"/) || [])[1];
    cek('nomor jurnal 0165 menaut ke jurnal id 7',
        href(b1) === '/bs.gl.journal.show/7', href(b1));
    cek('nomor jurnal 0170 menaut ke jurnal id 12',
        href(b2) === '/bs.gl.journal.show/12', href(b2));
    cek('teks tautannya adalah nomor jurnalnya',
        /<a [^>]*>JKB\/2609\/0165<\/a>/.test(b1), 'teks tautan tidak sama dengan nomornya');

    /* Kolom milik jurnal tidak lagi di-rowspan (tiap baris akun mengulang nomornya sendiri),
       jadi tautannya harus ikut terulang. Tombol "Lihat →" dulu hanya ada di baris PERTAMA:
       kalau tautannya hanya menggantikan itu, baris akun kedua dan seterusnya kehilangan jalan
       ke rinciannya tanpa ada yang menandainya. */
    const barisIsiSemua = [...html.matchAll(/<tr class="align-top[^"]*"[^>]*>[\s\S]*?<\/tr>/g)].map((m) => m[0]);
    const tanpaTautan = barisIsiSemua.filter((b) => ! /<a href="\/bs\.gl\.journal\.show\//.test(b));
    cek('SETIAP baris isi membawa tautan nomor jurnalnya',
        barisIsiSemua.length > 0 && tanpaTautan.length === 0,
        tanpaTautan.length + ' dari ' + barisIsiSemua.length + ' baris tanpa tautan');

    console.log('\n=== 2. Tombol "Lihat →" benar-benar hilang ===');

    cek('tidak ada lagi teks "Lihat" di halaman', ! html.includes('Lihat →'));
    // Diperiksa pada TEMPLATE-nya, bukan seluruh berkas: komentar penjelas di atas kolomnya
    // menyebut "Lihat →" secara harfiah, jadi pencarian se-berkas akan lulus palsu.
    const template = (src.match(/<template>[\s\S]*<\/template>/) || [''])[0]
        .replace(/<!--[\s\S]*?-->/g, '');
    cek('template tidak lagi memuat tombol Lihat', ! template.includes('Lihat'), 'masih ada di template');
    cek('rute show tetap dipakai (pindah, bukan hilang)',
        (template.match(/bs\.gl\.journal\.show/g) || []).length === 1,
        (template.match(/bs\.gl\.journal\.show/g) || []).length + ' kemunculan');

    console.log('\n=== 3. Lebar & colspan ikut menyusut satu kolom ===');

    const judul = [...(src.match(/<tr class="text-left text-xs uppercase tracking-wide text-neutral-500">[\s\S]*?<\/tr>/) || [''])[0]
        .matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]*>/g, '').trim());
    const kolom = [...((src.match(/<colgroup>[\s\S]*?<\/colgroup>/) || [''])[0])
        .matchAll(/<col(\s+class="w-\[?([\d.]+)(rem)?\]?")?\s*\/>/g)];
    cek('jumlah <col> = jumlah kepala kolom', kolom.length === judul.length,
        kolom.length + ' col vs ' + judul.length + ' th');
    cek('kepala kolom terakhir adalah Kredit (bukan kolom aksi kosong)',
        judul[judul.length - 1] === 'Kredit', JSON.stringify(judul.slice(-2)));

    // Tiap baris <tr> di tbody harus punya jumlah sel yang sama dengan jumlah kolom.
    const barisIsi = [...html.matchAll(/<tr class="align-top[^"]*"[^>]*>([\s\S]*?)<\/tr>/g)]
        .map((m) => [...m[1].matchAll(/<t[dh]([^>]*)>/g)]
            .reduce((n, c) => n + (Number((c[1].match(/colspan="(\d+)"/) || [])[1]) || 1), 0));
    /* Kotak centang & tombol buka-tutup masih di-rowspan (keduanya milik JURNALNYA, bukan
       barisnya), jadi baris LANJUTAN memang berisi 2 sel lebih sedikit. Yang dijaga polanya:
       baris pertama tiap jurnal selebar penuh, lanjutannya selebar penuh dikurangi 2 — dan
       jumlah baris "penuh" sama dengan jumlah jurnalnya. Menuntut semuanya selebar penuh akan
       menuduh struktur yang benar sebagai salah. */
    const PENUH = judul.length;
    const LANJUT = judul.length - 2;
    cek('ada baris isi yang terbaca', barisIsi.length > 0, barisIsi.length + ' baris');
    cek('tiap baris isi selebar ' + PENUH + ' (baris pertama) atau ' + LANJUT + ' (lanjutan) sel',
        barisIsi.every((n) => n === PENUH || n === LANJUT), JSON.stringify(barisIsi));
    cek('jumlah baris penuh = jumlah jurnal',
        barisIsi.filter((n) => n === PENUH).length === JURNAL.length,
        barisIsi.filter((n) => n === PENUH).length + ' vs ' + JURNAL.length);
    cek('jumlah seluruh baris = jumlah baris akun semua jurnal',
        barisIsi.length === JURNAL.reduce((s, j) => s + j.lines.length, 0),
        barisIsi.length + ' baris');

    // Baris keterangan saringan memakai colspan; jumlahnya harus pas, kalau tidak garisnya
    // berhenti sebelum ujung tabel tanpa ada yang menyadarinya.
    const barisKet = (src.match(/<tr class="bg-white\/60 text-\[10px\][\s\S]*?<\/tr>/) || [''])[0];
    const totalColspan = [...barisKet.matchAll(/colspan="(\d+)"/g)].reduce((s, m) => s + Number(m[1]), 0)
        + [...barisKet.matchAll(/<th(?![^>]*colspan)[^>]*>/g)].length;
    cek('colspan baris keterangan saringan = jumlah kolom', totalColspan === judul.length,
        totalColspan + ' vs ' + judul.length);

    // Baris SARINGAN juga: kalau jumlah selnya tidak pas, tiap kotak saringan berdiri di bawah
    // kolom yang bukan miliknya — dan tidak ada galat apa pun yang menandainya.
    const barisSar = (src.match(/<tr class="border-t border-neutral-200 bg-white\/60[\s\S]*?<\/tr>/) || [''])[0];
    const selSar = [...barisSar.matchAll(/colspan="(\d+)"/g)].reduce((s, m) => s + Number(m[1]), 0)
        + [...barisSar.matchAll(/<th(?![^>]*colspan)[^>]*>/g)].length;
    cek('jumlah sel baris saringan = jumlah kolom', selSar === judul.length,
        selSar + ' vs ' + judul.length);

    console.log('\n=== 4. Penanda "bisa diklik" bukan warna semata ===');

    cek('tautan nomor jurnal digarisbawahi saat disentuh', src.includes('hover:underline'));
    cek('tautannya bertitle penjelas', src.includes("'Buka rincian jurnal '"));

    console.log('\n=== 5. Kolom lain tidak ikut berubah ===');

    cek('kolom Cost Center masih ada', judul.includes('Cost Center'));
    cek('kolom Baris tetap tidak ada', ! judul.includes('Baris'));
    cek('tombol buka/tutup rincian tetap ada', src.includes('toggle(j.id)'));
    cek('kotak centang aksi massal tetap ada', src.includes('semuaTercentang'));

    console.log('\n=== HASIL ===');
    console.log('  lulus: ' + lulus + ' · gagal: ' + gagal);
    console.log(gagal === 0 ? '  SEMUA LULUS' : '  ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
