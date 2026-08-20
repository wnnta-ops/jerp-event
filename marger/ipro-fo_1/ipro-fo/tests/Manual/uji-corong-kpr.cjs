// Jalankan setup() VizFunnel.vue yang SEBENARNYA dan periksa geometri corongnya.
//
// Yang diperiksa bukan tampilannya melainkan ARTI bentuknya: lebar batang harus sebanding
// dengan jumlahnya, pita penghubung harus benar-benar menyambungkan tepi batang atas ke
// tepi batang bawah (itulah yang membuatnya terbaca satu aliran), selisih antar tahap harus
// jumlah mutlak dengan katanya — bukan persen, dan bukan angka bertanda minus — serta nama
// tahap tidak boleh dipenggal.
//
// Jalankan:  node tests/Manual/uji-corong-kpr.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { effectScope } = require('vue');

const ROOT = process.argv[2] || '.';
const BERKAS = path.join(ROOT, 'resources/js/Components/Charts/VizFunnel.vue');

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

let Comp;   // diisi di dalam main(), sebab esbuild dengan plugin hanya tersedia asinkron

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

/** Jalankan setup() dengan props tertentu, pulangkan binding-nya. */
function jalankan(props) {
    const scope = effectScope();
    let b;
    scope.run(() => { b = Comp.setup({ satuan: 'berkas', warna: null, tebal: 22, sela: 15, ...props }, { attrs: {}, slots: {}, emit() {}, expose() {} }); });

    return { b, scope };
}

// Angka nyata dari dashboard pada tangkapan layar.
const INISIASI = [
    { n: 'SPRS', v: 30 }, { n: 'BI Checking', v: 19 }, { n: 'Collect Berkas', v: 17 },
    { n: 'Analisa', v: 16 }, { n: 'Profiling Customer', v: 15 }, { n: 'Proses KPR', v: 8 },
];
const PROSES = [
    { n: 'Diajukan', v: 40 }, { n: 'Dalam Review', v: 32 }, { n: 'SP3K Terbit', v: 30 },
    { n: 'SI Dicetak', v: 26 }, { n: 'Akad', v: 22 }, { n: 'Pencairan', v: 13 },
];

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [vuePlugin],
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    Comp = modul.exports.default;
    console.log('\n=== 1. Lebar batang sebanding dengan jumlahnya ===');
    {
        const { b, scope } = jalankan({ rows: INISIASI });
        const t = b.tahap.value;

        cek('keenam tahap tergambar', t.length === 6, String(t.length));
        cek('tahap terbesar memakai lebar penuh bidang corong',
            Math.abs(t[0].w - (560 - 168 - 62)) < 0.5, String(t[0].w));
        // Perbandingan lebar HARUS mengikuti perbandingan angkanya, kalau tidak bentuknya berbohong.
        const rasio = t[5].w / t[0].w;
        cek('lebar tahap 6 : tahap 1 = 8 : 30', Math.abs(rasio - 8 / 30) < 0.01, rasio.toFixed(3));
        cek('corong menyempit ke bawah — tidak ada batang yang melebar',
            t.every((x, i) => i === 0 || x.w <= t[i - 1].w + 0.01),
            t.map((x) => x.w.toFixed(0)).join(' → '));
        cek('semua batang berada di satu sumbu tengah',
            t.every((x) => Math.abs((x.x + x.w / 2) - b.CX) < 0.01));

        console.log('\n=== 2. Pita benar-benar menyambungkan tepi batang ===');
        const p = b.pita.value;
        cek('ada lima pita untuk enam tahap', p.length === 5, String(p.length));
        // Inilah yang membedakan "satu aliran" dari "enam batang yang kebetulan berdekatan":
        // tepi atas pita harus persis tepi bawah batang di atasnya, begitu pula sebaliknya.
        const titik = (d) => d.match(/-?\d+(?:\.\d+)?/g).map(Number);
        let nyambung = true, rincian = '';
        for (let i = 0; i < p.length; i++) {
            const k = titik(p[i].d);                 // M x1,y1 L x2,y1 L x3,y2 L x4,y2
            const atas = t[i], bawah = t[i + 1];
            const cocok = Math.abs(k[0] - atas.x) < 0.15
                && Math.abs(k[2] - (atas.x + atas.w)) < 0.15
                && Math.abs(k[4] - (bawah.x + bawah.w)) < 0.15
                && Math.abs(k[6] - bawah.x) < 0.15
                && Math.abs(k[1] - (atas.y + 22)) < 0.15
                && Math.abs(k[5] - bawah.y) < 0.15;
            if (!cocok) { nyambung = false; rincian = 'pita ' + (i + 1) + ' → ' + p[i].d; }
        }
        cek('tiap pita bertepi sama dengan batang yang dihubungkannya', nyambung, rincian);

        console.log('\n=== 3. Selisih berbunyi "sedang proses n", bukan angka minus ===');
        // Berkasnya tidak hilang — ia masih dikerjakan di tahap atasnya. Tanda minus
        // menyiratkan kehilangan, jadi tidak boleh muncul di mana pun.
        cek('selisih tahap 1→2 berbunyi "sedang proses 11"', p[0].teks === 'sedang proses 11', p[0].teks);
        cek('selisih tahap 5→6 berbunyi "sedang proses 7"', p[4].teks === 'sedang proses 7', p[4].teks);
        cek('tidak ada tanda minus di label mana pun',
            !p.some((x) => x.teks.includes('−') || x.teks.includes('-')), p.map((x) => x.teks).join(' | '));
        cek('tidak ada tanda persen di mana pun',
            !p.some((x) => x.teks.includes('%')) && !t.some((x) => x.teks.includes('%')));
        cek('penurunan ditandai turun', p.every((x) => x.turun === true));
        cek('keterangan pita menyebut berapa yang sedang proses dan di mana',
            p[0].judul.includes('11 berkas sedang proses di SPRS'), p[0].judul);

        console.log('\n=== 4. Nama tahap tidak dipenggal ===');
        cek('"Profiling Customer" tercetak utuh',
            t[4].nama === 'Profiling Customer', t[4].nama);
        cek('nomor tahapnya berurut 1..6', t.map((x) => x.no).join(',') === '1,2,3,4,5,6');
        cek('jumlah per tahap ditulis apa adanya', t.map((x) => x.teks).join(',') === '30,19,17,16,15,8');
        cek('keterangan batang membawa nomor, nama, jumlah, dan satuannya',
            t[0].judul === '1. SPRS: 30 berkas', t[0].judul);

        scope.stop();
    }

    console.log('\n=== 5. Kartu kedua (Proses KPR) ===');
    {
        const { b, scope } = jalankan({ rows: PROSES });
        const t = b.tahap.value, p = b.pita.value;
        cek('skala dipatok pada tahap terbesar (40)',
            Math.abs(t[0].w - (560 - 168 - 62)) < 0.5, String(t[0].w));
        cek('lebar Pencairan : Diajukan = 13 : 40',
            Math.abs(t[5].w / t[0].w - 13 / 40) < 0.01, (t[5].w / t[0].w).toFixed(3));
        cek('selisih terbesar ada di Akad → Pencairan (sedang proses 9)',
            p[4].teks === 'sedang proses 9', p[4].teks);
        cek('"SP3K Terbit" tercetak utuh', t[2].nama === 'SP3K Terbit', t[2].nama);
        scope.stop();
    }

    console.log('\n=== 6. Keadaan tepi ===');
    {
        // Aliran yang justru BERTAMBAH harus dikatakan apa adanya, bukan dipaksa menyempit.
        const { b, scope } = jalankan({ rows: [{ n: 'A', v: 10 }, { n: 'B', v: 25 }] });
        const p = b.pita.value[0];
        cek('tahap yang bertambah ditandai naik, bukan turun', p.turun === false);
        cek('pertambahan berbunyi "masuk 15"', p.teks === 'masuk 15', p.teks);
        cek('keterangannya menyebut masuk, bukan tertahan',
            p.judul.includes('15 berkas masuk di B'), p.judul);
        scope.stop();
    }
    {
        const { b, scope } = jalankan({ rows: [{ n: 'A', v: 0 }, { n: 'B', v: 0 }] });
        cek('semua nol → dianggap belum ada data', b.hampa.value === true);
        scope.stop();
    }
    {
        // Satu nilai rusak tidak boleh meracuni seluruh skala.
        const { b, scope } = jalankan({ rows: [{ n: 'A', v: 30 }, { n: 'B', v: NaN }, { n: 'C', v: 5 }] });
        const t = b.tahap.value;
        cek('nilai bukan bilangan dijadikan 0, bukan NaN', t[1].v === 0 && t[1].teks === '0');
        cek('koordinatnya tetap bilangan sah', t.every((x) => Number.isFinite(x.w) && Number.isFinite(x.x)));
        cek('tahap bernilai 0 tetap terlihat (lebar minimum)', t[1].w >= 6, String(t[1].w));
        scope.stop();
    }
    {
        // Tahap sangat kecil dibanding tahap terbesar tetap harus kelihatan.
        const { b, scope } = jalankan({ rows: [{ n: 'A', v: 5000 }, { n: 'B', v: 1 }] });
        cek('tahap 1 dari 5000 tetap punya lebar minimum', b.tahap.value[1].w >= 6, String(b.tahap.value[1].w));
        scope.stop();
    }

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);

}

main();
