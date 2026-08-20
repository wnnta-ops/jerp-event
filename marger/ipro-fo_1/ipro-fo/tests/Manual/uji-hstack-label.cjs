// Jalankan setup() VizHStack.vue yang SEBENARNYA dan periksa penempatan angkanya.
//
// Yang dijaga: angka TIDAK PERNAH lebih lebar daripada tempat ia ditulis. Angka di dalam
// segmen berwarna putih, jadi bagian yang melewati tepi segmen jatuh ke latar putih kartu
// dan hilang sama sekali — bukan sekadar terlihat sesak. Karena itu tiap angka yang
// ditempatkan di dalam harus terbukti muat, dan yang tidak muat harus pindah ke luar,
// bukan dihapus diam-diam.
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
// Angka dari kartu "Overview Aset Tetap" pada tangkapan layar: total perolehan
// Rp 15.313.184.085, dan nilai buku Bangunan Rp 11.558.898.007.
const ASET = [
    { n: 'Bangunan', b: 900_000_000, a: 11_558_898_007 },
    { n: 'Inventaris', b: 300_000_000, a: 2_057_301_583 },
    { n: 'Kendaraan', b: 200_000_000, a: 296_984_495 },
];

const KAR = 6.2;        // taksiran lebar karakter yang dipakai komponen
const PAD = 10;
const W = 560, PL = 144;

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [vuePlugin],
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    Comp = modul.exports.default;

    console.log('\n=== 1. Tidak ada angka yang terpotong ===');
    {
        const { b, scope } = jalankan({ rows: ASET, keys: KEYS });
        const baris = b.baris.value;

        cek('ketiga baris tergambar', baris.length === 3, String(baris.length));

        // Inti perkaranya: setiap angka DI DALAM harus benar-benar muat di segmennya.
        let sesak = '';
        for (const br of baris) {
            for (const s of br.seg) {
                if (s.dalam && s.dalam.length * KAR + PAD > s.w + 0.01) {
                    sesak = br.n + ' · "' + s.dalam + '" (' + (s.dalam.length * KAR).toFixed(0)
                        + ' satuan) di segmen selebar ' + s.w.toFixed(0);
                }
            }
        }
        cek('setiap angka di dalam segmen benar-benar muat', sesak === '', sesak);

        // Dan tidak ada segmen bernilai yang kehilangan angkanya sama sekali pada segmen akhir.
        const akhirTanpaAngka = baris
            .map((br) => ({ n: br.n, s: br.seg[br.seg.length - 1] }))
            .filter((x) => x.s && x.s.w > 0 && ! x.s.dalam && ! x.s.luar)
            .map((x) => x.n);
        cek('tidak ada nilai buku yang hilang angkanya', akhirTanpaAngka.length === 0,
            akhirTanpaAngka.join(', '));

        console.log('\n=== 2. Angka yang tidak muat pindah ke LUAR, bukan dihapus ===');
        const luar = baris.flatMap((br) => br.seg.filter((s) => s.luar).map((s) => br.n + ': ' + s.luar));
        cek('ada angka yang dipindah ke luar', luar.length > 0, luar.join(' | '));
        cek('Inventaris & Kendaraan angkanya ditulis di luar',
            luar.some((x) => x.startsWith('Inventaris')) && luar.some((x) => x.startsWith('Kendaraan')),
            luar.join(' | '));
        cek('Bangunan tetap ditulis di dalam segmennya',
            !! baris[0].seg[baris[0].seg.length - 1].dalam,
            baris[0].seg[baris[0].seg.length - 1].dalam || '(kosong)');

        console.log('\n=== 3. Angka luar tetap di dalam bidang gambar ===');
        // Talang kanan harus melebar cukup; kalau tidak, angkanya keluar viewBox dan terpotong
        // oleh tepi SVG — persis masalah yang sama, hanya berpindah tempat.
        let lewat = '';
        for (const br of baris) {
            for (const s of br.seg) {
                if (! s.luar) continue;
                const ujung = s.x + s.w + 8 - 2 + s.luar.length * KAR;
                if (ujung > W + 0.5) lewat = br.n + ' → ' + ujung.toFixed(0) + ' > ' + W;
            }
        }
        cek('tidak ada angka luar yang melewati tepi kanan', lewat === '', lewat);
        cek('talang kanan melebar karena ada angka di luar', b.hasil.value.pr > 16, String(b.hasil.value.pr));

        console.log('\n=== 4. Batang tetap benar ===');
        cek('segmen berurut tanpa tumpang tindih',
            baris.every((br) => br.seg.every((s, i) => i === 0 || s.x >= br.seg[i - 1].x + br.seg[i - 1].w - 0.01)));
        cek('semua batang mulai dari talang kiri yang sama',
            baris.every((br) => Math.abs(br.seg[0].x - PL) < 0.01));
        cek('baris terbesar memakai lebar penuh bidang batang',
            Math.abs((baris[0].xAkhir - PL) - (W - PL - b.hasil.value.pr)) < 0.5,
            (baris[0].xAkhir - PL).toFixed(1));
        cek('keterangan tooltip tetap membawa nama & angkanya',
            baris[1].seg[1].judul === 'Inventaris · Nilai buku: Rp 2.057.301.583',
            baris[1].seg[1].judul);

        scope.stop();
    }

    console.log('\n=== 5. Semua angka muat → talang kanan tidak dilebarkan ===');
    {
        // Nilai yang seimbang dan pendek: keduanya muat di dalam, jadi batang tidak perlu
        // dipotong demi ruang yang tak terpakai.
        const { b, scope } = jalankan({
            rows: [{ n: 'A', b: 500, a: 500 }, { n: 'B', b: 400, a: 600 }],
            keys: KEYS, hitung: true, satuan: 'unit',
        });
        cek('talang kanan tetap sempit', b.hasil.value.pr === 16, String(b.hasil.value.pr));
        cek('tidak ada angka yang dipindah ke luar',
            b.baris.value.every((br) => br.seg.every((s) => ! s.luar)));
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
        cek('segmen tersisa tetap mulai dari talang kiri', Math.abs(seg[0].x - PL) < 0.01);
        scope.stop();
    }
    {
        // Angka yang lebih panjang daripada seluruh lebar batang tetap harus terbaca.
        const { b, scope } = jalankan({ rows: [{ n: 'A', b: 0, a: 999_999_999_999 }], keys: KEYS });
        const s = b.baris.value[0].seg[0];
        cek('angka sangat panjang dipindah ke luar bila perlu', !! s.dalam || !! s.luar,
            'dalam=' + s.dalam + ' luar=' + s.luar);
        scope.stop();
    }

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main();
