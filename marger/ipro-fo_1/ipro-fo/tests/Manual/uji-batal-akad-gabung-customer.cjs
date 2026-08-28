// Render BatalAkad/Index.vue YANG SEBENARNYA dan periksa penggabungan per customer.
//
// Logikanya sama dengan Reject Proses KPR, dan pemeriksaan intinya juga sama: KEUTUHAN TABEL.
// Tiap baris harus menempati lebar yang sama persis setelah rowspan dari baris di atasnya
// diperhitungkan. Rowspan yang keliru tidak merusak tabelnya — ia hanya menggeser isinya satu
// kolom sehingga plafon duduk di bawah judul Tahap Sebelum Batal, dan itu tidak terlihat.
//
// Layar ini menambah satu jebakan yang tidak ada di Reject Proses KPR: kolom "Customer / Unit"
// yang dulu SATU kolom kini dipecah dua — Customer ikut kelompoknya, Unit tetap milik tiap
// pengajuan. Baris saringan yang colspan-nya tidak ikut disesuaikan akan menggeser seluruh
// kepala kolom tanpa satu pun galat.
//
// Jalankan:  node tests/Manual/uji-batal-akad-gabung-customer.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BusinessSupport/BatalAkad/Index.vue');

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

const batal = (id, custId, nama, no, unit, bank, status, tahap, tgl, plafon, alasan, akadAt) => ({
    id, customer_id: custId, customer: nama, kpr_number: no, unit, booking: 'SPR-' + id, bank,
    status, status_label: status === 'cancelled' ? 'Dibatalkan' : 'Ditolak',
    batal_at: tgl, tahap_sebelum: tahap, akad_at: akadAt ?? null, sempat_akad: !! akadAt,
    alasan, plafon,
});

// Roronoa Zorro & Siti Aminah masing-masing muncul DUA kali pada tangkapan layar.
const DATA = [
    batal(6, 11, 'GUNI', 'KPR/JRES/2608/00006', 'JRK3-004-XVIII-17', 'Bank Rakyat Indonesia', 'rejected', null, '2026-08-07', 389200000, 'ETSS GAJADI', null),
    batal(4, 12, 'Yogi', 'KPR/JRES/2608/00004', 'JRK3-004-XVIII-17', 'BSN', 'cancelled', 'Akad Done', '2026-08-07', 350000000, null, null),
    batal(34, 13, 'Roronoa Zorro', 'KPR/JRES/2607/00034', 'JRK3-004-XV-12A', 'Bank Mandiri', 'cancelled', 'Akad Done', '2026-07-29', 500000000, null, '2026-08-23'),
    batal(26, 13, 'Roronoa Zorro', 'KPR/JRES/2607/00026', 'JRK3-004-XV-12A', 'Bank Tabungan Negara', 'cancelled', 'SI', '2026-07-29', 400000000, null, null),
    batal(20, 14, 'Siti Aminah', 'KPR/JRES/2607/00020', 'JRK3-004-XV-3A', 'Bank Mandiri', 'cancelled', 'SI', '2026-07-29', 426300000, null, null),
    batal(16, 14, 'Siti Aminah', 'KPR/JRES/2607/00016', 'JRK3-004-XV-3A', null, 'cancelled', 'Submitted', '2026-07-29', 426300000, null, null),
    // Nama SAMA, ORANG BERBEDA.
    batal(9, 15, 'Budi Santoso', 'KPR/JRES/2607/00011', 'JRK3-004-XV-2', 'BSN', 'cancelled', null, '2026-07-03', 5000000000, null, null),
    batal(8, 16, 'Budi Santoso', 'KPR/JRES/2607/00010', 'JRK3-004-XV-9', 'BSN', 'rejected', null, '2026-07-02', 300000000, 'RPC KURANG', null),
    // Tanpa customer — tidak boleh saling menempel.
    batal(2, null, null, 'KPR/JRES/2605/00002', 'JRK3-004-XV-10', 'Bank Mandiri', 'cancelled', null, '2026-05-11', 250000000, null, null),
    batal(1, null, null, 'KPR/JRES/2605/00001', 'JRK3-004-XV-11', 'BSN', 'rejected', null, '2026-05-10', 250000000, null, null),
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

    const app = createSSRApp({
        render: () => h(modul.exports.default, {
            rows: DATA,
            totals: { count: DATA.length, cancelled: 7, rejected: 3, sempat_akad: 1 },
        }),
    });
    app.config.globalProperties.route = (n, p) => '/' + n + (p ? '/' + JSON.stringify(p) : '');
    const html = await renderToString(app);
    // Komentar template ikut ter-render pada SSR (di build produksi ia dibuang) — dibuang di
    // sini supaya perhitungan elemen tidak ikut menghitung teks di dalam komentar.
    const tabel = ((html.match(/<table[\s\S]*?<\/table>/) || [''])[0]).replace(/<!--[\s\S]*?-->/g, '');
    const teks = tabel.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    if (process.env.DUMP) { fs.writeFileSync(process.env.DUMP, tabel); }

    console.log('\n=== 1. Urutan kolom — Customer paling kiri ===');

    const th = [...tabel.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((m) => m[1].trim()).filter(Boolean);
    cek('Customer mendahului No. KPR',
        th.indexOf('Customer') === 0 && th.indexOf('No. KPR') === 1, th.join('|'));
    // "Customer / Unit" dulu satu kolom; Unit kini berdiri sendiri karena ia milik tiap
    // pengajuan, bukan milik customernya.
    cek('Unit jadi kolom tersendiri',
        th.join('|').startsWith('Customer|No. KPR|Unit|Bank|Tanggal Batal Akad|Status|Tahap Sebelum Batal'),
        th.join('|'));
    cek('tidak ada lagi kolom gabungan "Customer / Unit"',
        ! th.includes('Customer / Unit'), th.join('|'));

    console.log('\n=== 2. Keutuhan tabel — tiap baris selebar sama ===');

    // Baris saringan memakai colspan; kalau tidak ikut disesuaikan saat kolom bertambah,
    // seluruh kepala kolom bergeser tanpa satu pun galat.
    const lebar = lebarPerBaris(tabel);
    const unik = [...new Set(lebar)];
    cek('semua baris selebar sama', unik.length === 1,
        'lebar yang muncul: ' + unik.join(',') + ' → ' + lebar.join(','));
    cek('lebarnya sembilan kolom', unik[0] === 9, String(unik[0]));

    console.log('\n=== 3. Pengajuan digabungkan per customer ===');

    const trIsi = (tabel.split('</thead>')[1] || '').match(/<tr[\s\S]*?<\/tr>/g) || [];
    cek('sepuluh baris pembatalan tetap utuh', trIsi.length === 10, String(trIsi.length));
    cek('nama Roronoa Zorro hanya ditulis sekali',
        (teks.match(/Roronoa Zorro/g) || []).length === 1, String((teks.match(/Roronoa Zorro/g) || []).length));
    cek('nama Siti Aminah hanya ditulis sekali',
        (teks.match(/Siti Aminah/g) || []).length === 1, String((teks.match(/Siti Aminah/g) || []).length));
    cek('selnya membentang dua baris', tabel.includes('rowspan="2"'), 'tidak ditemukan');
    cek('jumlahnya disebut', teks.includes('2× batal akad'), 'tidak ditemukan');
    cek('yang hanya sekali tidak diberi lencana', teks.includes('1 pembatalan'), 'tidak ditemukan');

    console.log('\n=== 4. Nama sama TAPI orang berbeda tidak digabung ===');

    cek('"Budi Santoso" tetap dua baris terpisah',
        (teks.match(/Budi Santoso/g) || []).length === 2,
        String((teks.match(/Budi Santoso/g) || []).length));
    cek('tidak ada kelompok yang terlalu besar', ! tabel.includes('rowspan="3"'), 'ada rowspan 3');

    console.log('\n=== 5. Baris tanpa customer tidak saling menempel ===');

    // Menghitung em-dash TIDAK cukup: '—' juga terbit dari alasan & tahap yang kosong, jadi
    // jumlahnya tetap banyak walau kedua baris itu tergabung. Yang diukur jumlah kelompoknya:
    // sepuluh pembatalan → delapan kelompok (Zorro 2, Siti 2, sisanya sendiri-sendiri).
    cek('sepuluh pembatalan terbagi jadi delapan kelompok',
        (tabel.match(/<tbody/g) || []).length === 8, String((tabel.match(/<tbody/g) || []).length));
    cek('hanya dua sel yang membentang', (tabel.match(/rowspan="2"/g) || []).length === 2,
        String((tabel.match(/rowspan="2"/g) || []).length));

    console.log('\n=== 6. Isi tiap pembatalan tetap lengkap ===');

    ['KPR/JRES/2607/00034', 'KPR/JRES/2607/00026', 'Bank Tabungan Negara', 'JRK3-004-XV-12A',
        'Akad Done', 'ETSS GAJADI', 'Rp 500.000.000'].forEach((v) => {
        cek('"' + v + '" tercetak', teks.includes(v), 'tidak ditemukan');
    });
    // Penanda "akad sempat terjadi" milik satu pengajuan, bukan milik customernya.
    cek('penanda akad tetap di barisnya sendiri', teks.includes('akad 2026-08-23'), 'tidak ditemukan');
    cek('kedua status masih tergambar',
        teks.includes('Dibatalkan') && teks.includes('Ditolak'), 'tidak lengkap');

    console.log('\n=== 7. Saringan tidak hilang ===');

    const src = fs.readFileSync(BERKAS, 'utf8');
    cek('kotak pencarian masih ada', src.includes('v-model="f.q"'));
    cek('saringan status masih ada', src.includes('v-model="f.status"'));
    cek('saringan akad masih ada', src.includes('v-model="f.akad"'));
    cek('tombol Excel masih ada', src.includes("route('bs.batal-akad.export'"));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
