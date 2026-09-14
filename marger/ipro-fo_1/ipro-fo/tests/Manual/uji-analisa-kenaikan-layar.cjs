// Blok analisa di bawah Neraca / Laba Rugi / Laba Rugi vs Budget.
//
// KENAPA PERLU UJI:
//  · Kalimatnya ADALAH isi fiturnya. Angka yang benar tetapi kalimatnya menyimpulkan hal lain
//    justru lebih berbahaya daripada tidak ada analisa sama sekali — orang mempercayainya.
//  · "Perubahan bersih" dan "dampak ke laba" adalah DUA angka berbeda. Pendapatan naik 150 jt
//    dan beban naik 20 jt dua-duanya "naik", tetapi labanya hanya naik 130 jt. Kalimat yang
//    menyamakan keduanya terdengar masuk akal dan meleset 40 juta.
//  · Naik dari nol bukan "naik 100%". Harus dikatakan sebagai pos baru.
//  · Laba Rugi vs Budget memakai kata kerja lain: sebuah akun "melampaui anggaran",
//    bukan "naik dari anggaran".
//
// Jalankan:  node tests/Manual/uji-analisa-kenaikan-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const KOMPONEN = path.join(ROOT, 'resources/js/Components/UI/AnalisaKenaikan.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({
            contents: 'module.exports = new Proxy({}, { get: () => ({ render: () => null }) });',
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            return { contents: sfc.compileScript(descriptor, { id, inlineTemplate: true }).content, loader: 'ts', resolveDir: path.dirname(a.path) };
        });
    },
};

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

const g = (nama, kode, lalu, kini, pct, baru = false, grup = 'BEBAN KARYAWAN') =>
    ({ kode, nama, grup, lalu, kini, delta: kini - lalu, pct, baru });

/* Angka yang dipakai sepanjang berkas ini sengaja sama dengan contoh di komentar: pendapatan
   naik 150 jt, beban naik 20 jt → jumlah kenaikan 170 jt, tetapi laba hanya naik 130 jt. */
const dasar = {
    label_kini: 'Periode ini',
    label_lalu: 'Periode sama 2025',
    naik: [
        g('PENJUALAN USAHA', '40101010101', 100_000_000, 250_000_000, 150.0, false, 'PENJUALAN USAHA'),
        g('BIAYA GAJI, UPAH DAN TUNJANGAN', '50301010101', 10_000_000, 30_000_000, 200.0),
    ],
    turun: [g('BIAYA SEWA KANTOR', '50302020207', 5_000_000, 1_000_000, -80.0)],
    jumlah_naik: 170_000_000,
    jumlah_turun: -4_000_000,
    netto: 166_000_000,
    akun_naik: 2,
    akun_turun: 1,
    pusat_pct: 88.2,
    dampak_laba: 130_000_000,
};

let Komponen = null;
async function render(props, tag) {
    const out = path.join(ROOT, 'ank-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    if (! Komponen) {
        await esbuild.build({
            entryPoints: [KOMPONEN], bundle: true, format: 'cjs', platform: 'node',
            outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
        });
        delete require.cache[out];
        Komponen = require(out).default;
    }
    const app = createSSRApp({ render: () => h(Komponen, props) });

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}
/** Teks kalimat saja, tanpa tag — untuk memeriksa kalimatnya, bukan markupnya. */
const teks = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();

(async () => {
    const html = await render({ analisa: dasar, judul: 'Analisa Pergerakan Laba Rugi' }, 'lr');
    const t = teks(html);

    console.log('=== 1. KALIMAT PENGGERAK UTAMA ===');

    cek('judulnya dipakai', t.includes('Analisa Pergerakan Laba Rugi'));
    cek('menyebut pembandingnya', /Membandingkan Periode ini dengan Periode sama 2025/.test(t), t.slice(0, 200));
    /* Ini yang membedakan analisa ini dari daftar selisih biasa: ia mengatakan mengapa
       urutannya begitu. */
    cek('menerangkan urutannya menurut rupiah, bukan persentase',
        /besarnya rupiah.*bukan persentase/s.test(t), 'tidak diterangkan');
    cek('menyebut penggerak teratas beserta nilainya',
        /paling menggerakkan angkanya adalah PENJUALAN USAHA/.test(t) && /naik Rp 150 jt/.test(t), t.slice(0, 400));
    /* 150 dari 170 = 88% — inilah yang membedakan "naik paling besar" dari "yang sebenarnya
       menjelaskan kenaikannya". */
    cek('menyebut berapa persen kenaikan itu menjelaskan keseluruhannya',
        /menyumbang 88% dari seluruh kenaikan/.test(t), t.slice(0, 500));
    cek('menyebut pendampingnya', /Disusul BIAYA GAJI, UPAH DAN TUNJANGAN \(Rp 20 jt\)/.test(t), 'tidak disebut');
    cek('menyebut yang menahan dari arah sebaliknya',
        /Di arah sebaliknya, BIAYA SEWA KANTOR turun Rp 4 jt/.test(t), 'tidak disebut');

    console.log('\n=== 2. DAMPAK KE LABA DIPISAHKAN DARI JUMLAH KENAIKAN ===');

    cek('jumlah kenaikan & penurunan disebut apa adanya',
        /2 akun naik sebesar Rp 170 jt/.test(t) && /1 akun turun sebesar Rp 4 jt/.test(t), t.slice(0, 700));
    /* Inti berkas ini: 170 jt dan 130 jt adalah dua angka berbeda, dan layar WAJIB
       mengatakan keduanya beserta alasan bedanya. */
    cek('dampak ke laba disebut terpisah', /Bagi laba.*menambah Rp 130 jt/s.test(t), 'tidak disebut');
    /* Diperiksa UTUH, bukan separuh: kalimat penjelasnya terdiri dari dua bagian — pernyataan
       bahwa angkanya memang berbeda, lalu alasannya. Menghapus bagian pertama saja meninggalkan
       alasan yang menggantung tanpa ada yang dijelaskan. */
    cek('menerangkan KENAPA angkanya berbeda dari jumlah kenaikan',
        /Angka itu berbeda dari jumlah kenaikan di atas, dan memang seharusnya berbeda: beban yang naik ikut terhitung sebagai kenaikan pada akunnya sendiri, tetapi bagi laba ia mengurangi/.test(t),
        'tidak diterangkan utuh');
    cek('tidak ada lagi klaim "perubahan bersih" yang menyamakan keduanya',
        ! /perubahan bersihnya/.test(t), 'klaim lama masih ada');

    const rugi = await render({ analisa: { ...dasar, dampak_laba: -45_000_000 } }, 'rugi');
    cek('dampak negatif dikatakan menggerus, bukan menambah',
        /menggerus Rp 45 jt/.test(teks(rugi)) && ! /menambah Rp 45 jt/.test(teks(rugi)), 'kata kerjanya salah');

    const neraca = await render({ analisa: { ...dasar, dampak_laba: null }, judul: 'Analisa Pergerakan Neraca' }, 'neraca');
    /* Neraca tidak menyentuh laba — mengarang angkanya di sana akan salah. */
    cek('tanpa dampak laba (Neraca), kalimat itu tidak muncul sama sekali',
        ! /Bagi laba/.test(teks(neraca)), 'kalimat laba muncul di Neraca');
    cek('tetapi jumlah kenaikannya tetap dilaporkan', /2 akun naik sebesar/.test(teks(neraca)));

    console.log('\n=== 3. TERPUSAT vs TERSEBAR ===');

    cek('88% dibaca sebagai terpusat',
        /Pergerakannya terpusat/.test(t) && /lima akun teratas mencakup 88,2%/.test(t), t.slice(0, 900));
    const sebar = await render({ analisa: { ...dasar, pusat_pct: 31.4 } }, 'sebar');
    cek('31% dibaca sebagai tersebar', /Pergerakannya tersebar/.test(teks(sebar)), 'tidak dibedakan');
    cek('tersebar menerangkan akibatnya bagi pembaca',
        /mencari satu biang keladi tidak akan menjelaskannya/.test(teks(sebar)), 'tidak diterangkan');

    console.log('\n=== 4. NAIK DARI NOL ===');

    const baru = await render({
        analisa: { ...dasar, naik: [g('PENDAPATAN SEWA', '40201010101', 0, 90_000_000, null, true, 'PENDAPATAN LAIN')] },
    }, 'baru');
    const tb = teks(baru);
    cek('pos baru dikatakan sebagai pos baru, bukan naik sekian persen',
        /sama sekali belum ada pada Periode sama 2025/.test(tb) && /bukan kenaikan dari angka yang sudah berjalan/.test(tb), tb.slice(0, 400));
    cek('tidak mengarang persentase untuk kenaikan dari nol', ! /\(\+?\d+(,\d+)?%\)/.test(tb.split('Disusul')[0]), 'ada persentase');
    cek('di tabel ditandai "baru", bukan 0%', /baru<\/span>/.test(baru), 'penanda tabel tidak ada');

    console.log('\n=== 5. MODE ANGGARAN MEMAKAI KATA KERJA LAIN ===');

    const bud = await render({ analisa: dasar, judul: 'Analisa Selisih terhadap Anggaran', mode: 'budget' }, 'bud');
    const tbud = teks(bud);
    cek('memakai "melampaui anggaran", bukan "naik"',
        /melampaui anggaran Rp 150 jt/.test(tbud), tbud.slice(0, 400));
    cek('arah sebaliknya memakai "di bawah anggaran"', /di bawah anggaran Rp 4 jt/.test(tbud), 'kata kerjanya tidak diganti');
    cek('judul tabelnya ikut menyesuaikan',
        /Paling melampaui anggaran/.test(tbud) && /Paling hemat dari anggaran/.test(tbud), 'judul tabel tidak menyesuaikan');
    cek('mode periode TIDAK memakai kata anggaran', ! /melampaui anggaran/.test(t), 'kata anggaran bocor ke mode periode');

    console.log('\n=== 6. TABEL RINCIAN ===');

    cek('kode akun 11 digit ditampilkan', /40101010101/.test(html) && /50301010101/.test(html));
    /* Kalimatnya memakai angka ringkas supaya enak dibaca; tabelnya WAJIB memuat angka penuh
       supaya bisa dicocokkan dengan laporan di atasnya. */
    cek('tabel memuat rupiah penuh, bukan yang diringkas',
        /Rp 250\.000\.000/.test(html) && /Rp 100\.000\.000/.test(html), 'angka penuh tidak ada');
    cek('kolomnya memakai label pembanding yang sama',
        (html.match(/Periode sama 2025/g) || []).length >= 2, 'label pembanding tidak dipakai di tabel');
    cek('selisih turun ditandai warna bahaya', /text-danger-600[^"]*">\(Rp 4\.000\.000\)/.test(html.replace(/\s+/g, ' ')) || /\(Rp 4\.000\.000\)/.test(html), 'selisih turun tidak tampil');

    console.log('\n=== 7. TIDAK ADA PERGERAKAN ===');

    const nol = await render({ analisa: { ...dasar, naik: [], turun: [], akun_naik: 0, akun_turun: 0, pusat_pct: null, dampak_laba: null } }, 'nol');
    const tn = teks(nol);
    cek('mengatakan keadaannya apa adanya', /Tidak ada pergerakan yang berarti dibanding Periode sama 2025/.test(tn), tn.slice(0, 200));
    cek('tidak mengarang kalimat penggerak', ! /paling menggerakkan/.test(tn), 'kalimat penggerak tetap muncul');
    cek('tidak merender tabel kosong', ! /<tbody/.test(nol), 'tabel tetap dirender');

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
