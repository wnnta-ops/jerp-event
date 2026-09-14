// Layar Kartu Bon Sementara (lima kolom nilai) & layar baru Saldo Debit Card.
//
// KENAPA PERLU UJI:
//  · Kartu Bon Sementara kini punya LIMA kolom nilai berurutan. Kolom yang bergeser satu posisi
//    tidak menimbulkan galat apa pun — hanya angka yang tampil di judul yang salah.
//  · Baris filternya harus punya <th> sebanyak kolom headernya. Bila tertinggal, seluruh kotak
//    filter bergeser dan kotak Status pindah ke bawah kolom nilai — tanpa satu pun galat.
//  · Baris TOTAL memakai colspan; menambah kolom tanpa menambah sel total membuat angkanya
//    berbaris di kolom yang salah.
//  · Saldo Debit Card harus memakai empat kolom keterangan YANG SAMA dengan Kartu Bon Sementara,
//    dan riwayat alokasinya harus menjumlah persis sebesar kolom Pemakaian.
//
// Jalankan:  node tests/Manual/uji-kartu-bon-saldo-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const KARTU = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Card.vue');
const SALDO = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/CardBalance.vue');
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');

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
                  + ' export const router = { get(){}, post(){}, delete(){}, visit(){} };'
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {}, post(){}, put(){}, delete(){}, reset(){} });'
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

async function render(halaman, props, tag) {
    const out = path.join(ROOT, 'kartubon-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [halaman], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    const rute = (n, p) => '/' + n + (p != null ? '/' + (p?.id ?? p) : '');
    globalThis.route = rute;
    const app = createSSRApp({ render: () => require('vue').h(Komponen, props) });
    // route() dipakai di dalam TEMPLATE, jadi ia dicari pada _ctx — globalThis saja tidak cukup.
    app.config.globalProperties.route = rute;
    const html = await renderToString(app);

    return html.replace(/<!--[\s\S]*?-->/g, '');
}

/** Isi <td> baris data pertama, berurutan — inilah yang membuktikan kolom tidak bergeser. */
function selBarisPertama(html) {
    const tbody = (html.match(/<tbody[\s\S]*?<\/tbody>/) || [''])[0];
    const tr = (tbody.match(/<tr[\s\S]*?<\/tr>/) || [''])[0];

    return [...tr.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
}

(async () => {
    console.log('=== 1. KARTU BON SEMENTARA — lima kolom nilai, berurutan ===');

    const BARIS = {
        no: 'BON/2609/0028', date: '2026-09-10', holder: 'Dewi Anggraini — Sales Executive',
        department: 'Marketing', purpose: 'fsdfsfs',
        amount: 9999999, realized: 9500000, refund: 111111, unused: 388888, outstanding: 0,
        status: 'realized',
    };
    const kartu = await render(KARTU, { rows: [BARIS], statuses: { requested: 'Diajukan', outstanding: 'Outstanding', realized: 'Realized' } }, 'kartu');

    const thead = (kartu.match(/<thead[\s\S]*?<\/thead>/) || [''])[0];
    const judul = [...thead.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());

    cek('kolom "Nilai" sudah berganti nama menjadi "Kasbon Awal"',
        judul.includes('Kasbon Awal') && ! judul.includes('Nilai'), JSON.stringify(judul.filter(Boolean)));

    /* Diperiksa pada URUTANNYA, bukan sekadar "namanya ada": Refund & Tidak Terpakai memang
       diminta berada di antara Realisasi dan Outstanding, dan kolom yang benar tetapi salah
       tempat akan memasangkan angka dengan judul yang keliru. */
    const nilai = judul.filter((t) => ['Kasbon Awal', 'Realisasi', 'Refund', 'Tidak Terpakai', 'Outstanding'].includes(t));
    cek('urutannya Kasbon Awal → Realisasi → Refund → Tidak Terpakai → Outstanding',
        nilai.join(' | ') === 'Kasbon Awal | Realisasi | Refund | Tidak Terpakai | Outstanding', nilai.join(' | '));

    const sel = selBarisPertama(kartu);
    cek('baris datanya 10 sel (4 keterangan + 5 nilai + status)', sel.length === 10, JSON.stringify(sel));
    cek('Refund tampil di sel ke-7 sebesar 111.111', sel[6] === '111.111', String(sel[6]));
    cek('Tidak Terpakai tampil di sel ke-8 sebesar 388.888', sel[7] === '388.888', String(sel[7]));
    cek('Outstanding tampil di sel ke-9 sebesar 0', sel[8] === '0', String(sel[8]));

    /* Baris filter harus selebar baris judul. Bila <th> kosongnya kurang, kotak Status naik ke
       bawah kolom nilai dan orang menyaring kolom yang bukan maksudnya. */
    const barisTh = [...thead.matchAll(/<tr[\s\S]*?<\/tr>/g)].map((m) => (m[0].match(/<th\b/g) || []).length);
    cek('baris filter punya <th> sebanyak baris judulnya',
        barisTh.length >= 2 && barisTh[0] === barisTh[1], JSON.stringify(barisTh));

    const tfoot = (kartu.match(/<tfoot[\s\S]*?<\/tfoot>/) || [''])[0];
    const selTotal = [...tfoot.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());
    cek('baris TOTAL memuat kelima angkanya',
        selTotal.includes('9.999.999') && selTotal.includes('9.500.000') && selTotal.includes('111.111') && selTotal.includes('388.888'),
        JSON.stringify(selTotal));
    cek('colspan TOTAL menutup keempat kolom keterangan', /colspan="4"/.test(tfoot), tfoot.slice(0, 120));

    console.log('\n=== 2. SALDO DEBIT CARD — tiga angka kartu, per nomor bon ===');

    const BON = {
        no: 'BON/2609/0028', date: '2026-09-10', holder: 'Dewi Anggraini — Sales Executive',
        purpose: 'fsdfsfs', card: 'MARCOMM — MARCOMM',
        penambahan: 9999999, pemakaian: 9611111, sisa: 388888,
        riwayat: [
            { date: '2026-09-10', description: 'Alokasi 1', amount: 9000000, bank: '10101020202 — MANDIRI' },
            { date: '2026-09-11', description: 'Alokasi 2', amount: 611111, bank: '10101020210 — BCA' },
        ],
    };
    const saldo = await render(SALDO, { rows: [BON] }, 'saldo');

    const judulSaldo = [...(saldo.match(/<thead[\s\S]*?<\/thead>/) || [''])[0]
        .matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]+>/g, '').trim());

    cek('judulnya Saldo Debit Card', /<h1[^>]*>[\s\S]*?Saldo Debit Card/.test(saldo));
    /* Keempat kolom keterangannya memang harus sama persis dengan Kartu Bon Sementara — itulah
       yang membuat kedua layar bisa dibaca berdampingan. */
    cek('empat kolom keterangannya sama dengan Kartu Bon Sementara',
        judulSaldo.slice(0, 4).join(' | ') === 'No Bon | Tanggal | Pemegang | Keperluan', judulSaldo.slice(0, 4).join(' | '));
    cek('tiga kolom nilainya Penambahan → Pemakaian → Sisa Saldo',
        judulSaldo.slice(4, 7).join(' | ') === 'Penambahan | Pemakaian | Sisa Saldo', judulSaldo.slice(4, 7).join(' | '));

    cek('ada saringan tanggal awal & akhir', (saldo.match(/type="date"/g) || []).length === 2,
        String((saldo.match(/type="date"/g) || []).length));

    const selSaldo = selBarisPertama(saldo);
    cek('Penambahan 9.999.999 · Pemakaian 9.611.111 · Sisa 388.888',
        selSaldo[4] === '9.999.999' && selSaldo[5] === '9.611.111' && selSaldo[6] === '388.888', JSON.stringify(selSaldo));

    console.log('\n=== 3. RIWAYAT PER NOMOR BON ===');

    /* Riwayat tertutup sampai barisnya diklik — kalau terbuka sejak awal, daftar bonnya sendiri
       tenggelam. Diperiksa pada ISI riwayatnya, bukan sekadar kata "Riwayat" yang juga muncul
       sebagai judul kolom di layar lain. */
    cek('riwayat belum terbuka sebelum barisnya diklik',
        ! saldo.includes('Riwayat Alokasi Kasbon'), 'riwayat tampil padahal barisnya belum diklik');

    /* Mekanismenya diperiksa di SUMBER, bukan di hasil render: membukanya perlu klik, dan SSR
       tidak mengklik apa pun. Diperiksa pada blok riwayatnya sendiri supaya tidak lulus hanya
       karena kata yang sama muncul di tempat lain pada berkas itu. */
    const sumber = fs.readFileSync(SALDO, 'utf8');
    /* Dipotong sampai penutup v-for-nya, BUKAN sampai </tr> pertama: di dalam blok ini ada tabel
       riwayat dengan <tr>-nya sendiri, sehingga potongan yang tidak rakus berhenti terlalu dini
       dan memeriksa blok yang nyaris kosong. */
    const mulai = sumber.indexOf('<tr v-if="dibuka[r.no]"');
    const akhir = sumber.indexOf('</template>', mulai);
    const blokRiwayat = mulai === -1 ? '' : sumber.slice(mulai, akhir);
    cek('riwayatnya digerbangi v-if="dibuka[r.no]"', blokRiwayat !== '', 'blok riwayat tidak ditemukan');
    cek('barisnya bisa diklik untuk membuka riwayat', /@click="toggle\(r\.no\)"/.test(sumber), 'tidak ada pemicu toggle');
    cek('riwayat menampilkan tanggal · keterangan · bank · jumlah',
        ['a.date', 'a.description', 'a.bank', 'a.amount'].every((f) => blokRiwayat.includes(f)), blokRiwayat.slice(0, 160));
    cek('total riwayat memakai angka Pemakaian yang sama dengan barisnya',
        blokRiwayat.includes('fmtRp(r.pemakaian)'), 'total riwayat dihitung terpisah dari kolom Pemakaian');
    cek('bon tanpa alokasi tetap menerangkan seluruh saldonya masih di kartu',
        blokRiwayat.includes('v-else') && blokRiwayat.includes('fmtRp(r.penambahan)'), blokRiwayat.slice(-200));

    console.log('\n=== 4. SIDEBAR ===');

    const nav = fs.readFileSync(NAV, 'utf8');
    const sesudahKartu = (nav.match(/\{ label: 'Kartu Bon Sementara', href: 'bs\.advance\.card' \},\s*\n(?:\s*\/\/[^\n]*\n)*\s*\{ label: '([^']+)'/) || [])[1];
    cek('menu Saldo Debit Card berada TEPAT di bawah Kartu Bon Sementara',
        sesudahKartu === 'Saldo Debit Card', sesudahKartu ?? '(tidak ada menu sesudahnya)');
    cek('menunya menunjuk rute Saldo Debit Card', nav.includes("href: 'bs.advance.card-balance'"));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
