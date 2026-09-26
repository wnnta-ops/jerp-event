// COGS → Komponen COGS — sisi layar, di-render SUNGGUHAN lewat SSR.
//
// Yang dijaga:
//  1. KARTU RINGKASAN MEMAKAI ANGKA SERVER, bukan menjumlah baris tabel. Tabelnya
//     dipaginasi 100 baris; menjumlah baris di layar membuat total komponen berubah setiap
//     pindah halaman. Uji ini sengaja memberi ringkasan yang BERBEDA dari jumlah baris.
//  2. DUA BENTUK SUMBER DI SATU HALAMAN. Tanah & License Permit (AP) punya tombol atur
//     tautan; Housing & Infrastruktur (SPK) TIDAK — jenisnya diatur di registrasi SPK, dan
//     tombol atur di sana hanya akan menghasilkan 404.
//  3. POSISI MENU: "Komponen COGS" tepat di bawah "Transaksi HPP RealCost", keempat
//     sub-modulnya berurutan dan masing-masing menunjuk komponen yang benar.
//
// Jalankan:  node tests/Manual/uji-komponen-cogs-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HALAMAN = 'BusinessSupport/Cogs/Komponen/Show';
const BERKAS = path.join(ROOT, 'resources/js/Pages', HALAMAN + '.vue');
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');
const RUTE = path.join(ROOT, 'routes/web.php');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BusinessSupport/Cogs/CogsComponentController.php');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){} };'
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

function adaPersis(akar, relatif) {
    let kini = akar;
    for (const bagian of relatif.split('/')) {
        let isi;
        try { isi = fs.readdirSync(kini); } catch { return false; }
        if (! isi.includes(bagian)) return false;
        kini = path.join(kini, bagian);
    }

    return true;
}

const DAFTAR = [
    { slug: 'tanah', label: 'Tanah' }, { slug: 'housing', label: 'Housing' },
    { slug: 'infrastruktur', label: 'Infrastruktur' }, { slug: 'license-permit', label: 'License Permit' },
];
const kosongLinks = { data: [], links: [] };

function propsAp(extra = {}) {
    return {
        komponen: { slug: 'tanah', label: 'Tanah', cogs: 'tanah', sumber: 'ap', cogsLabel: 'HPP Tanah' },
        daftar: DAFTAR,
        filters: { tahun: null, search: '' },
        bisaAtur: true,
        kategori: [
            { id: 1, code: 'TANAH-C1', name: 'TANAH C1', aktif: true, tertaut: true, milikLain: null, disarankan: false },
            { id: 2, code: 'TANAH-C2', name: 'TANAH C2', aktif: true, tertaut: false, milikLain: null, disarankan: true },
            { id: 3, code: 'LICENSE-PERMIT-C1', name: 'LICENSE & PERMIT C1', aktif: true, tertaut: false, milikLain: 'License Permit', disarankan: false },
        ],
        // Dua baris senilai 10 jt + 20 jt, tetapi RINGKASAN server 987.654.321 (seolah ada
        // ratusan baris di halaman lain). Layar yang menjumlah baris akan menampilkan 30 jt.
        baris: {
            data: [
                { id: 11, nomor: 'INV-UJI-001', tanggal: '2026-03-15', supplier: 'PT Uji', kategori: 'TANAH-C1 — TANAH C1', keterangan: 'Pelunasan tanah', nilai: 10000000, terbayar: 4000000, sisa: 6000000, status: 'partial' },
                { id: 12, nomor: 'INV-UJI-002', tanggal: '2026-03-16', supplier: 'PT Uji', kategori: 'TANAH-C1 — TANAH C1', keterangan: 'Uang muka', nilai: 20000000, terbayar: 0, sisa: 20000000, status: 'open' },
            ],
            links: [],
        },
        ringkas: { jumlah: 321, nilai: 987654321, terbayar: 123456789, sisa: 864197532 },
        ...extra,
    };
}

function propsSpk(extra = {}) {
    return {
        komponen: { slug: 'housing', label: 'Housing', cogs: 'bangunan', sumber: 'spk', cogsLabel: 'HPP Bangunan' },
        daftar: DAFTAR,
        filters: { tahun: null, search: '' },
        bisaAtur: true,
        jenisLabel: 'Housing',
        baris: {
            data: [
                { id: 21, nomor: 'SPK/UJI/001', tanggal: '2026-04-10', kontraktor: 'CV Bangun', lokasi: 'C1 · A-01', judul: 'Pembangunan rumah', kontrak: 100000000, addendum: 20000000, nilai: 120000000, progres: 50000000, sisa: 70000000, status: 'active' },
            ],
            links: [],
        },
        ringkas: { jumlah: 42, kontrak: 4000000000, addendum: 555000000, nilai: 4555000000, progres: 1000000000, sisa: 3555000000 },
        ...extra,
    };
}

async function render(props) {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs', platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = () => {};
    app.config.globalProperties.route = (n, p) => '/' + n + (p !== undefined ? '/' + p : '');
    const html = await renderToString(app);

    return { html, teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ') };
}

const rp = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

async function main() {
    const src = fs.readFileSync(BERKAS, 'utf8');
    const kode = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1').replace(/<!--[\s\S]*?-->/g, '');
    const nav = fs.readFileSync(NAV, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');
    const ctl = fs.readFileSync(CTL, 'utf8');

    console.log('\n=== 1. Komponen bersumber AP (Tanah) ===');

    const ap = await render(propsAp());
    cek('judul memuat nama komponen', ap.teks.includes('Komponen COGS · Tanah'), ap.teks.slice(0, 160));
    cek('sumbernya disebut: Account Payable → Kategori Invoice', /Account Payable.*Kategori Invoice/.test(ap.teks));
    cek('label komponen HPP ditampilkan', ap.teks.includes('HPP Tanah'));
    cek('kategori yang ditautkan tampil', ap.teks.includes('TANAH-C1'));
    cek('kategori yang BELUM ditautkan tidak tampil sebagai tautan', ! /TANAH-C2/.test(ap.teks.split('Atur Kategori Invoice')[0]),
        'kategori yang hanya disarankan terlihat seolah sudah ditautkan');
    cek('tombol Atur Kategori Invoice ada (boleh mengatur)', ap.teks.includes('Atur Kategori Invoice'));
    cek('nomor invoice tampil', ap.teks.includes('INV-UJI-001') && ap.teks.includes('INV-UJI-002'));
    cek('nomor invoice menaut ke detail invoice AP', /href="\/bs\.ap\.show\/11"/.test(ap.html), 'invoice tidak bisa dibuka dari sini');
    cek('status invoice diterjemahkan', ap.teks.includes('Sebagian') && ap.teks.includes('Belum Lunas'));

    console.log('\n=== 2. Kartu ringkasan = angka SERVER, bukan jumlah baris ===');

    cek('Total Nilai = ringkasan server (987.654.321)', ap.teks.includes(rp(987654321)), ap.teks.slice(0, 600));
    cek('  BUKAN jumlah baris di halaman (30.000.000)', ! ap.teks.includes('Total Nilai ' + rp(30000000)),
        'total dijumlah dari halaman yang sedang tampil');
    cek('jumlah invoice = 321 (bukan 2 baris)', /Invoice 321/.test(ap.teks), ap.teks.slice(0, 400));
    cek('Terbayar = ringkasan server', ap.teks.includes(rp(123456789)));
    cek('Sisa Hutang = ringkasan server', ap.teks.includes(rp(864197532)));
    cek('persentase terbayar dihitung dari ringkasan (12.5%)', ap.teks.includes('12.5%'), ap.teks.slice(0, 700));

    const tanpaIzin = await render(propsAp({ bisaAtur: false }));
    cek('tanpa izin atur: tombol Atur TIDAK ada', ! tanpaIzin.teks.includes('Atur Kategori Invoice'));

    const belum = await render(propsAp({
        kategori: propsAp().kategori.map((k) => ({ ...k, tertaut: false })),
        baris: kosongLinks, ringkas: { jumlah: 0, nilai: 0, terbayar: 0, sisa: 0 },
    }));
    /* DUA tempat mengatakan hal yang mirip: peringatan di kartu tautan dan judul EmptyState.
       Memeriksa kalimat awalnya saja membuat penghapusan salah satu tetap hijau — masing-masing
       diperiksa lewat kalimat yang hanya dimilikinya sendiri. */
    cek('belum ada tautan: peringatan di kartu tautan',
        belum.teks.includes('komponen ini belum menampilkan biaya apa pun'), belum.teks.slice(0, 500));
    cek('belum ada tautan: judul EmptyState-nya juga mengatakannya',
        belum.teks.includes('Belum ada Kategori Invoice yang ditautkan'), belum.teks.slice(0, 500));
    cek('  dan mengarahkan ke tombol Atur', belum.teks.includes('Klik Atur Kategori Invoice untuk memilih'),
        'orang tidak tahu harus berbuat apa');
    cek('  dan EmptyState-nya bukan "tidak ada invoice" biasa', ! belum.teks.includes('Tidak ada invoice hutang'),
        'layar menyiratkan kategorinya memang tanpa biaya, padahal belum ditautkan');

    console.log('\n=== 3. Komponen bersumber SPK (Housing) ===');

    const spk = await render(propsSpk());
    cek('judul memuat nama komponen', spk.teks.includes('Komponen COGS · Housing'));
    cek('sumbernya disebut: Contractor Management → Surat Perintah Kerja', /Contractor Management.*Surat Perintah Kerja/.test(spk.teks));
    cek('jenis SPK disebut', spk.teks.includes('jenis Housing'));
    // Jenis diatur di registrasi SPK. Tombol atur di sini akan menuju rute yang sengaja 404.
    cek('TIDAK ada tombol Atur Kategori Invoice walau bisaAtur', ! spk.teks.includes('Atur Kategori Invoice'));
    cek('nomor SPK menaut ke detail SPK', /href="\/bs\.spk\.show\/21"/.test(spk.html));
    cek('kolom Addendum ada', spk.teks.includes('Addendum'));
    cek('Nilai SPK = ringkasan server', spk.teks.includes(rp(4555000000)), spk.teks.slice(0, 700));
    cek('  dan rinciannya menyebut kontrak + addendum', spk.teks.includes('kontrak ' + rp(4000000000)) && spk.teks.includes('addendum ' + rp(555000000)));
    cek('jumlah SPK = 42 (bukan 1 baris)', /SPK 42/.test(spk.teks), spk.teks.slice(0, 400));
    cek('tidak ada kolom Terbayar/Sisa Hutang milik AP', ! spk.teks.includes('Sisa Hutang'));

    console.log('\n=== 4. Pindah antar komponen ===');

    for (const d of DAFTAR) {
        cek('tab ' + d.label + ' menaut ke komponennya', ap.html.includes('href="/bs.cogs.komponen.show/' + d.slug + '"'));
    }
    // Tepat SATU tab bertanda aria-current, dan itu tab komponen yang sedang dibuka — bukan
    // sekadar "ada yang bertanda", yang tetap hijau walau yang ditandai tab yang salah.
    const tabAktif = [...ap.html.matchAll(/<a\b[^>]*aria-current="page"[^>]*>/g)].map((x) => x[0]);
    cek('tepat satu tab ditandai aktif', tabAktif.length === 1, String(tabAktif.length));
    cek('  dan itu tab Tanah', tabAktif.length === 1 && tabAktif[0].includes('href="/bs.cogs.komponen.show/tanah"'), tabAktif.join(' | '));
    const tabAktifSpk = [...spk.html.matchAll(/<a\b[^>]*aria-current="page"[^>]*>/g)].map((x) => x[0]);
    cek('  di halaman Housing, tab Housing-lah yang aktif',
        tabAktifSpk.length === 1 && tabAktifSpk[0].includes('href="/bs.cogs.komponen.show/housing"'), tabAktifSpk.join(' | '));

    console.log('\n=== 5. Tidak ada angka yang dijumlah di layar ===');

    cek('layar tidak menjumlah baris tabel (reduce)', ! /baris\.data[\s\S]{0,40}\.reduce\(/.test(kode) && ! /\.reduce\([^)]*nilai/.test(kode),
        'total dijumlah dari halaman yang tampil');
    cek('ringkasan dibaca dari prop ringkas', /props\.ringkas\.nilai/.test(kode));

    console.log('\n=== 6. Nama halaman & rute ===');

    const m = /Inertia::render\('([^']+)'/.exec(ctl);
    cek('controller merender ' + HALAMAN, m && m[1] === HALAMAN, m ? m[1] : '-');
    cek('berkas .vue-nya ada dengan ejaan sama persis', m && adaPersis(path.join(ROOT, 'resources/js/Pages'), m[1] + '.vue'));
    const dipakai = [...new Set([...kode.matchAll(/route\('([^']+)'/g)].map((x) => x[1]))];
    cek('layar memakai nama rute', dipakai.length >= 4, JSON.stringify(dipakai));
    for (const n of dipakai) {
        cek('rute ' + n + ' terdaftar', rute.includes("->name('" + n + "')"));
    }

    console.log('\n=== 7. Sidebar COGS ===');

    // Modul COGS yang lama berganti nama menjadi "COGS Lama" sejak modul "COGS Baru" lahir.
    // Jangkarnya dicari APA ADANYA supaya uji ini berhenti (dan terlihat) bila namanya berubah
    // lagi — bukan diam-diam memulangkan blok kosong dan menjatuhkan delapan pemeriksaan di
    // bawahnya tanpa menyebut sebabnya.
    const iCogs = nav.indexOf("title: 'COGS Lama'");
    if (iCogs < 0) {
        cek("blok modul COGS ketemu di navGroups (title: 'COGS Lama')", false,
            'nama modulnya berubah — perbarui jangkar di berkas uji ini');
    }
    const akhirCogs = nav.indexOf("title: '", iCogs + 10);
    const blokCogs = nav.slice(iCogs, akhirCogs > 0 ? akhirCogs : undefined);
    const atas = [...blokCogs.matchAll(/\{ label: '([^']+)', icon: /g)].map((x) => x[1]);
    const iHpp = atas.indexOf('Transaksi HPP RealCost');
    const iKomp = atas.indexOf('Komponen COGS');
    cek('menu Komponen COGS ada di grup COGS', iKomp >= 0, JSON.stringify(atas));
    cek('  TEPAT di bawah Transaksi HPP RealCost', iHpp >= 0 && iKomp === iHpp + 1, 'hpp=' + iHpp + ' komponen=' + iKomp + ' → ' + JSON.stringify(atas));

    const mulai = blokCogs.indexOf("{ label: 'Komponen COGS'");
    const potong = blokCogs.slice(mulai, blokCogs.indexOf(']},', mulai));
    cek('memakai izin cogs.component.*', /permission: \['cogs\.component\.\*'\]/.test(potong), potong.slice(0, 120));
    const anak = [...potong.matchAll(/\{ label: '([^']+)', href: '([^']+)', params: \{ komponen: '([^']+)' \} \}/g)]
        .map((x) => ({ label: x[1], href: x[2], slug: x[3] }));
    cek('empat sub-modul, urutannya Tanah → Housing → Infrastruktur → License Permit',
        anak.map((a) => a.label).join(' → ') === 'Tanah → Housing → Infrastruktur → License Permit',
        JSON.stringify(anak.map((a) => a.label)));
    for (const d of DAFTAR) {
        const a = anak.find((x) => x.label === d.label);
        cek('  ' + d.label + ' → komponen ' + d.slug, a && a.href === 'bs.cogs.komponen.show' && a.slug === d.slug, JSON.stringify(a));
    }
    // Setiap slug di menu harus lolos batasan where() pada rutenya; kalau tidak, menunya 404.
    const mWhere = /cogs-komponen\/\{komponen\}'[^;]*?->where\('komponen', '([^']+)'\)->name\('bs\.cogs\.komponen\.show'\)/s.exec(rute);
    cek('rute membatasi slug komponen', !! mWhere, '(where tidak ditemukan)');
    const boleh = mWhere ? mWhere[1].split('|') : [];
    for (const a of anak) {
        cek('  slug menu "' + a.slug + '" diterima rute', boleh.includes(a.slug), JSON.stringify(boleh));
    }

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
