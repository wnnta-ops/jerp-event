// Jalankan setup() VizHStack.vue yang SEBENARNYA dan periksa tata letaknya.
//
// Tiga hal yang dijaga:
//   1. Angka SELALU di luar ujung batang — tidak ada lagi angka di dalam segmen yang bisa
//      terpotong, dan seluruh angka berbaris pada satu garis rata kiri.
//   2. Talang kiri & kanan DIUKUR dari isinya, bukan angka tetap — nama baris yang pendek
//      tidak boleh menyisakan ruang kosong yang memendekkan batangnya.
//   3. Tidak ada angka yang melewati tepi kanan bidang gambar.
//
// Jalankan:  node tests/Manual/uji-hstack-label.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { effectScope } = require('vue');

const ROOT = process.argv[2] || '.';
const BERKAS = path.join(ROOT, 'resources/js/Components/Charts/VizHStack.vue');

const vuePlugin = {
    name: 'vue',
    setup(build) {
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

let Comp;
let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

function jalankan(props) {
    const scope = effectScope();
    let b;
    scope.run(() => { b = Comp.setup({ hitung: false, satuan: '', ...props }, { attrs: {}, slots: {}, emit() {}, expose() {} }); });

    return { b, scope };
}

const KEYS = [
    { k: 'b', n: 'Akumulasi penyusutan', c: 3 },
    { k: 'a', n: 'Nilai buku', c: 2 },
];
// Angka dari kartu "Overview Aset Tetap" pada tangkapan layar.
const ASET = [
    { n: 'Bangunan', b: 900_000_000, a: 11_558_898_007 },
    { n: 'Inventaris', b: 300_000_000, a: 2_057_301_583 },
    { n: 'Kendaraan', b: 200_000_000, a: 1_454_027_517 },
];

const KAR = 6.2, SELA = 9, W = 560;

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [vuePlugin],
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    Comp = modul.exports.default;

    console.log('\n=== 1. Semua angka di LUAR batang ===');
    {
        const { b, scope } = jalankan({ rows: ASET, keys: KEYS });
        const baris = b.baris.value;

        cek('ketiga baris tergambar', baris.length === 3, String(baris.length));
        // Tidak boleh ada angka di dalam segmen lagi — itulah yang dulu bisa terpotong.
        cek('tidak ada satu pun angka di dalam segmen',
            baris.every((br) => br.seg.every((s) => s.dalam === undefined)));
        cek('setiap baris punya tepat satu angka di luar',
            baris.every((br) => br.seg.filter((s) => s.luar).length === 1),
            baris.map((br) => br.seg.filter((s) => s.luar).length).join(','));
        cek('angka yang dicetak adalah nilai segmen terakhir',
            baris.map((br) => br.seg[br.seg.length - 1].luar).join(' | ')
                === 'Rp 11.558.898.007 | Rp 2.057.301.583 | Rp 1.454.027.517',
            baris.map((br) => br.seg[br.seg.length - 1].luar).join(' | '));

        console.log('\n=== 2. Angka berbaris rata & tetap di dalam bidang gambar ===');
        // Semua angka bermula pada jarak yang sama dari ujung batangnya masing-masing,
        // dan tak satu pun melewati tepi kanan.
        let lewat = '';
        for (const br of baris) {
            for (const s of br.seg) {
                if (! s.luar) continue;
                const ujung = s.x + s.w + SELA - 2 + s.luar.length * KAR;
                if (ujung > W + 0.5) lewat = br.n + ' → ' + ujung.toFixed(0) + ' > ' + W;
            }
        }
        cek('tidak ada angka yang melewati tepi kanan', lewat === '', lewat);
        cek('talang kanan selebar angka terpanjang + selanya',
            Math.abs(b.PR.value - Math.ceil('Rp 11.558.898.007'.length * KAR + SELA * 2)) < 1,
            String(b.PR.value));

        console.log('\n=== 3. Grafik bergeser ke kiri ===');
        // Nama baris di sini pendek, jadi talang kirinya harus jauh lebih sempit daripada
        // 144 satuan yang dulu dipatok tetap — itulah yang memanjangkan batangnya.
        cek('talang kiri jauh lebih sempit daripada 144 yang lama',
            b.PL.value < 100, String(b.PL.value));
        cek('talang kiri masih cukup untuk nama terpanjang',
            b.PL.value >= 'Inventaris'.length * 5.9 + 12 - 1, String(b.PL.value));
        cek('semua batang mulai dari talang kiri yang sama',
            baris.every((br) => Math.abs(br.seg[0].x - b.PL.value) < 0.01));
        cek('baris terbesar memakai lebar penuh bidang batang',
            Math.abs((baris[0].xAkhir - b.PL.value) - (W - b.PL.value - b.PR.value)) < 0.5,
            (baris[0].xAkhir - b.PL.value).toFixed(1));

        console.log('\n=== 4. Batang tetap benar ===');
        cek('segmen berurut tanpa tumpang tindih',
            baris.every((br) => br.seg.every((s, i) => i === 0 || s.x >= br.seg[i - 1].x + br.seg[i - 1].w - 0.01)));
        cek('panjang batang sebanding dengan totalnya',
            Math.abs((baris[1].xAkhir - b.PL.value) / (baris[0].xAkhir - b.PL.value)
                - (300e6 + 2_057_301_583) / (900e6 + 11_558_898_007)) < 0.01);
        cek('keterangan tooltip tetap membawa nama & angkanya',
            baris[1].seg[1].judul === 'Inventaris · Nilai buku: Rp 2.057.301.583',
            baris[1].seg[1].judul);
        cek('segmen pertama tidak diberi angka (tempatnya sama, akan bertabrakan)',
            baris.every((br) => br.seg[0].luar === ''),
            baris.map((br) => br.seg[0].luar).join('|'));

        scope.stop();
    }

    console.log('\n=== 5. Talang menyesuaikan panjang namanya ===');
    {
        const { b, scope } = jalankan({
            rows: [{ n: 'A', b: 1, a: 1 }, { n: 'B', b: 1, a: 1 }], keys: KEYS, hitung: true, satuan: 'unit',
        });
        cek('nama sangat pendek → talang kiri di batas bawah', b.PL.value === 56, String(b.PL.value));
        scope.stop();
    }
    {
        const panjang = 'Kategori Aset Tetap Berwujud Yang Sangat Panjang Sekali Namanya';
        const { b, scope } = jalankan({ rows: [{ n: panjang, b: 1, a: 1 }], keys: KEYS, hitung: true });
        cek('nama sangat panjang → talang kiri dibatasi 168, tidak menelan batangnya',
            b.PL.value === 168, String(b.PL.value));
        scope.stop();
    }

    console.log('\n=== 6. Keadaan tepi ===');
    {
        const { b, scope } = jalankan({ rows: [{ n: 'A', b: 0, a: 0 }], keys: KEYS });
        cek('semua nol → dianggap belum ada data', b.hampa.value === true);
        scope.stop();
    }
    {
        // Nilai negatif tidak boleh menggeser kursor mundur & membuat segmen tumpang tindih.
        const { b, scope } = jalankan({ rows: [{ n: 'A', b: -5, a: 100 }], keys: KEYS });
        const seg = b.baris.value[0].seg;
        cek('nilai negatif diabaikan, bukan menggeser segmen', seg.length === 1 && seg[0].key === 'a');
        cek('segmen tersisa tetap mulai dari talang kiri', Math.abs(seg[0].x - b.PL.value) < 0.01);
        cek('angkanya tetap dicetak di luar', seg[0].luar === 'Rp 100');
        scope.stop();
    }
    {
        // Angka sangat panjang: talang kanan harus ikut melebar, bukan membiarkannya terpotong.
        const { b, scope } = jalankan({ rows: [{ n: 'A', b: 0, a: 999_999_999_999 }], keys: KEYS });
        const s = b.baris.value[0].seg[0];
        const ujung = s.x + s.w + SELA - 2 + s.luar.length * KAR;
        cek('angka sangat panjang tetap muat di bidang gambar', ujung <= W + 0.5, ujung.toFixed(0));
        scope.stop();
    }

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main();
