// Sub-modul Alokasi Bon Sementara — sisi layar, di-render SUNGGUHAN lewat SSR.
//
// Yang dijaga:
//  1. BLOK ALOKASI BENAR-BENAR PINDAH. Layar Realisasi tidak boleh lagi punya form tambah
//     alokasi — kalau tersisa, ada DUA tempat menulis alokasi yang sama dan keduanya
//     terlihat sah.
//  2. TOMBOL REALISASIKAN BON DIGERBANGI, DAN ALASANNYA DIKATAKAN. Tombol mati tanpa
//     keterangan membuat orang mengira wewenangnya yang kurang, lalu menghubungi admin.
//  3. TOMBOL "LANJUT REALISASIKAN BON" MATI SAAT BELUM ADA ALOKASI — dan alasannya juga
//     dikatakan. Ini pintu satu-satunya menuju Realisasi.
//  4. POSISI MENU: "Alokasi Bon Sementara" TEPAT di antara Pengeluaran dan Realisasi, di
//     ketiga daftar menu yang menyusun dirinya sendiri (sidebar, Hub, Section) — daftar yang
//     tertinggal membuat sub-modulnya hilang dari salah satu pintu masuk.
//
// Jalankan:  node tests/Manual/uji-alokasi-bon-sementara-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HAL_ALOK = 'BusinessSupport/CashBook/Advances/Allocation/Show';
const HAL_DAFTAR = 'BusinessSupport/CashBook/Advances/Allocation/Index';
const HAL_REAL = 'BusinessSupport/CashBook/Advances/Show';
const NAV = path.join(ROOT, 'resources/js/Components/Layout/navGroups.ts');
const HUB = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Hub.vue');
const SECTION = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Section.vue');
const RUTE = path.join(ROOT, 'routes/web.php');
const CTL = path.join(ROOT, 'app/Http/Controllers/Web/BusinessSupport/CashBook/CashAdvanceController.php');
const SVC = path.join(ROOT, 'app/Domain/BusinessSupport/Services/CashAdvanceService.php');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){}, put(){}, delete(){}, visit(){} };'
                  + ' export const useForm = (d) => ({ ...(d || {}), errors: {}, processing: false, reset(){}, post(){}, put(){} });'
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


/** Apakah ADA tombol berlabel ini, dan apakah ia nonaktif? Diperiksa pada elemen <button>-nya
 *  sendiri: label yang sama juga dipakai sebagai judul, dan memotong HTML di kemunculan
 *  pertama akan memeriksa bagian halaman yang bukan tombolnya. */
function tombol(html, label) {
    const semua = [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)]
        .filter((m) => m[2].replace(/<[^>]+>/g, ' ').includes(label));

    return { ada: semua.length > 0, mati: semua.some((m) => /(?:^|\s)disabled(?:=|\s|$)/.test(m[1])) };
}

async function render(halaman, props) {
    const hasil = await esbuild.build({
        entryPoints: [path.join(ROOT, 'resources/js/Pages', halaman + '.vue')],
        bundle: true, write: false, format: 'cjs', platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);
    const app = createSSRApp({ render: () => h(modul.exports.default, props) });
    app.config.warnHandler = () => {};
    app.config.globalProperties.route = (n, p) => '/' + n + (p !== undefined ? '/' + (p?.slug ?? p) : '');
    const html = await renderToString(app);

    return { html, teks: html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/\s+/g, ' ') };
}

const BON = {
    id: 7, advance_number: 'BS/2033/0007', advance_date: '2033-07-01', holder: 'UJI Pemegang',
    purpose: 'Uji alokasi', amount: 2000000, status: 'outstanding', payment_method: 'debit_va',
    credit_card: { id: 1, name: 'KARTU UJI' }, approvals: {}, settlement_status: null, lines: [],
};
const AKUN = [{ value: 1, label: '10101010101 — KAS' }, { value: 2, label: '10101020204 — BCA' }];
const ALOKASI_ISI = {
    rows: [
        { id: 11, alloc_date: '2033-07-10', description: 'Beli ATK', amount: 500000, account_id: 1, account: '10101010101 — KAS', journal_id: 21 },
        { id: 12, alloc_date: '2033-07-12', description: 'Transport', amount: 300000, account_id: 2, account: '10101020204 — BCA', journal_id: 22 },
    ],
    total: 800000, sisa: 1200000,
};
const ALOKASI_KOSONG = { rows: [], total: 0, sisa: 2000000 };

async function main() {
    const nav = fs.readFileSync(NAV, 'utf8');
    const hub = fs.readFileSync(HUB, 'utf8');
    const section = fs.readFileSync(SECTION, 'utf8');
    const rute = fs.readFileSync(RUTE, 'utf8');
    const ctl = fs.readFileSync(CTL, 'utf8');
    const svc = fs.readFileSync(SVC, 'utf8');
    const srcReal = fs.readFileSync(path.join(ROOT, 'resources/js/Pages', HAL_REAL + '.vue'), 'utf8');

    console.log('\n=== 1. Layar Alokasi Kasbon (sub-modul baru) ===');

    const isi = await render(HAL_ALOK, {
        advance: BON, cashAccounts: AKUN, allocations: ALOKASI_ISI,
        lanjutAlokasi: { perlu: true, sudah: false, pada: null }, bisaUbah: true,
    });
    cek('judulnya menyebut nomor bon', isi.teks.includes('Alokasi Kasbon · BS/2033/0007'), isi.teks.slice(0, 160));
    cek('ketiga kartu angka ada', isi.teks.includes('Nominal Bon') && isi.teks.includes('Sudah Dialokasikan') && isi.teks.includes('Belum Dialokasikan'));
    cek('total alokasi tampil', isi.teks.includes('800.000'));
    cek('sisa tampil & artinya disebutkan', isi.teks.includes('1.200.000') && /tetap menjadi saldo kartu/.test(isi.teks));
    cek('kedua baris alokasi ter-render', isi.teks.includes('Beli ATK') && isi.teks.includes('Transport'));
    cek('form tambah alokasi punya keempat isiannya',
        /Tanggal \*/.test(isi.teks) && /Keterangan \*/.test(isi.teks) && /Jumlah \*/.test(isi.teks) && /Bank Pengeluaran \*/.test(isi.teks));
    cek('jurnal alokasi dijelaskan', /Dr Uang Muka Operasional · Cr bank pengeluaran/.test(isi.teks));
    cek('tombol Lanjut Realisasikan Bon ada', isi.teks.includes('Lanjut Realisasikan Bon'));
    cek('ditandai belum dilanjutkan', isi.teks.includes('Belum dilanjutkan'));

    console.log('\n=== 2. Tombol Lanjut mati saat belum ada alokasi, dan alasannya dikatakan ===');

    const kosong = await render(HAL_ALOK, {
        advance: BON, cashAccounts: AKUN, allocations: ALOKASI_KOSONG,
        lanjutAlokasi: { perlu: true, sudah: false, pada: null }, bisaUbah: true,
    });
    cek('peringatan belum ada alokasi tampil', /Belum ada alokasi/.test(kosong.teks), kosong.teks.slice(0, 300));
    cek('alasan tombol Lanjut mati dikatakan',
        /Belum ada Alokasi Kasbon — Uang Muka Operasional baru terbentuk dari alokasi/.test(kosong.teks),
        'tombol mati tanpa keterangan — orang mengira wewenangnya yang kurang');
    const tLanjut = tombol(kosong.html, 'Lanjut Realisasikan Bon');
    cek('tombolnya ada dan memang dinonaktifkan', tLanjut.ada && tLanjut.mati,
        tLanjut.ada ? 'tombolnya tetap bisa ditekan' : 'tombolnya tidak ada sama sekali');

    const tanpaIzin = await render(HAL_ALOK, {
        advance: BON, cashAccounts: AKUN, allocations: ALOKASI_ISI,
        lanjutAlokasi: { perlu: true, sudah: false, pada: null }, bisaUbah: false,
    });
    cek('tanpa izin ubah: alasannya menyebut izinnya', /Butuh izin cashbook\.advance\.realize/.test(tanpaIzin.teks));
    cek('tanpa izin ubah: form tambah alokasi tidak ada', ! /Bank Pengeluaran \*/.test(tanpaIzin.teks));

    const sudah = await render(HAL_ALOK, {
        advance: BON, cashAccounts: AKUN, allocations: ALOKASI_ISI,
        lanjutAlokasi: { perlu: true, sudah: true, pada: '2033-07-15 09:00:00' }, bisaUbah: true,
    });
    cek('sesudah dilanjutkan: ditandai sudah', sudah.teks.includes('Sudah dilanjutkan'));
    cek('  waktunya disebutkan', sudah.teks.includes('2033-07-15 09:00:00'));
    cek('  alokasinya dikunci (form hilang)', ! /Bank Pengeluaran \*/.test(sudah.teks),
        'alokasi masih bisa diubah sesudah dilanjutkan — acuan realisasi berubah di belakang');
    cek('  dan disediakan jalan ke Realisasi', sudah.teks.includes('Buka Realisasi Bon'));

    console.log('\n=== 3. Blok alokasi BENAR-BENAR pindah dari layar Realisasi ===');

    cek('layar Realisasi tidak lagi punya form tambah alokasi', ! /Bank Pengeluaran \*/.test(srcReal),
        'ada DUA tempat menulis alokasi, dan keduanya terlihat sah');
    cek('  tidak ada lagi tombol Tambah Alokasi', ! /Tambah Alokasi/.test(srcReal));
    cek('  tidak ada lagi fungsi hapus alokasi', ! /hapusAlokasi/.test(srcReal));
    cek('  ringkasannya tetap ada di sana', /Sudah Dialokasikan/.test(srcReal),
        'operator kehilangan angka alokasi saat menyiapkan realisasi');
    cek('  beserta pintu ke sub-modulnya', /bs\.advance\.allocation\.show/.test(srcReal));

    console.log('\n=== 4. Tombol Realisasikan Bon digerbangi ===');

    const terkunci = await render(HAL_REAL, {
        advance: BON, expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline', creditCards: [],
        allocations: ALOKASI_ISI, allocationMode: true,
        lanjutAlokasi: { perlu: true, sudah: false, pada: null },
    });
    cek('alasan terkuncinya dikatakan', /belum dilanjutkan dari Alokasi Kasbon/.test(terkunci.teks), terkunci.teks.slice(0, 400));
    cek('  beserta tautan ke tempat membukanya', /Buka Alokasi Bon Sementara/.test(terkunci.teks));
    const tReal = tombol(terkunci.html, 'Realisasikan Bon');
    cek('  tombolnya ada dan dinonaktifkan', tReal.ada && tReal.mati,
        tReal.ada ? 'tombol Realisasikan Bon masih bisa ditekan' : 'tombolnya tidak ada');

    const bebas = await render(HAL_REAL, {
        advance: BON, expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline', creditCards: [],
        allocations: ALOKASI_ISI, allocationMode: true,
        lanjutAlokasi: { perlu: true, sudah: true, pada: '2033-07-15 09:00:00' },
    });
    cek('sesudah dilanjutkan: peringatannya hilang', ! /belum dilanjutkan dari Alokasi Kasbon/.test(bebas.teks));
    cek('  dan tombolnya hidup', tombol(bebas.html, 'Realisasikan Bon').ada && ! tombol(bebas.html, 'Realisasikan Bon').mati);

    // Bon reguler tidak punya tahap alokasi sama sekali — tidak boleh ikut terkunci.
    const reguler = await render(HAL_REAL, {
        advance: { ...BON, payment_method: 'reguler' }, expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline', creditCards: [],
        allocations: ALOKASI_KOSONG, allocationMode: false,
        lanjutAlokasi: { perlu: false, sudah: false, pada: null },
    });
    cek('bon reguler: tidak ada peringatan gerbang', ! /belum dilanjutkan dari Alokasi Kasbon/.test(reguler.teks));
    cek('  dan tombolnya hidup', tombol(reguler.html, 'Realisasikan Bon').ada && ! tombol(reguler.html, 'Realisasikan Bon').mati,
        'bon biasa ikut terkunci padahal tidak pernah punya alokasi');
    cek('  serta tanpa kartu ringkasan alokasi', ! /Sudah Dialokasikan/.test(reguler.teks));

    console.log('\n=== 5. Daftar sub-modul ===');

    const daftar = await render(HAL_DAFTAR, {
        rows: {
            data: [
                { id: 7, nomor: 'BS/2033/0007', tanggal: '2033-07-01', pemegang: 'UJI Pemegang', keperluan: 'Uji alokasi', nominal: 2000000, jumlahAlokasi: 2, dialokasikan: 800000, belum: 1200000, sudahLanjut: false },
                { id: 8, nomor: 'BS/2033/0008', tanggal: '2033-07-02', pemegang: 'UJI Dua', keperluan: 'Uji dua', nominal: 500000, jumlahAlokasi: 1, dialokasikan: 500000, belum: 0, sudahLanjut: true },
            ],
            links: [],
        },
    });
    cek('judul halamannya benar', daftar.teks.includes('Alokasi Bon Sementara'));
    cek('kedua bon tampil', daftar.teks.includes('BS/2033/0007') && daftar.teks.includes('BS/2033/0008'));
    cek('nomor bon menaut ke layar alokasinya', /href="\/bs\.advance\.allocation\.show\/7"/.test(daftar.html));
    cek('tahapnya dibedakan', daftar.teks.includes('Belum dilanjutkan') && daftar.teks.includes('Sudah dilanjutkan'));
    cek('angka dialokasikan & belum tampil', daftar.teks.includes('800.000') && daftar.teks.includes('1.200.000'));
    const kosongDaftar = await render(HAL_DAFTAR, { rows: { data: [], links: [] } });
    cek('daftar kosong menjelaskan siapa yang masuk ke sini',
        /Bon reguler tidak melewati tahap alokasi/.test(kosongDaftar.teks), kosongDaftar.teks.slice(0, 300));

    console.log('\n=== 6. Gerbangnya ada di SERVICE, bukan cuma di layar ===');

    // Menyembunyikan tombol tidak menghentikan POST-nya; penjaga sesungguhnya harus di service.
    cek('service menolak realisasi tanpa penanda',
        /allocation_done_at === null/.test(svc) && /ADV_ALLOCATION_NOT_CONFIRMED/.test(svc),
        'gerbangnya hanya di layar/controller — sebelas pemanggil langsung melewatinya');
    cek('cakupannya lewat perluLanjutAlokasi()', /perluLanjutAlokasi\(\$adv\)/.test(svc));
    cek('controller memakai definisi yang SAMA, tidak menulis syaratnya sendiri',
        /perluLanjutAlokasi\(\$advance\)/.test(ctl) && ! /payment_method === 'debit_va' && \$advance->journal_id === null/.test(ctl),
        'ada dua definisi "bon yang perlu dialokasikan" yang bisa berbeda');

    console.log('\n=== 7. Rute & ketiga daftar menu ===');

    for (const n of ['bs.advance.allocation.index', 'bs.advance.allocation.show', 'bs.advance.allocation.lanjut']) {
        cek('rute ' + n + ' terdaftar', rute.includes("->name('" + n + "')"));
    }

    for (const [nama, src, pola] of [
        ['sidebar', nav, /'Pengeluaran Bon Sementara'[\s\S]{0,600}?'Alokasi Bon Sementara'[\s\S]{0,600}?'Realisasi Bon Sementara'/],
        ['Hub', hub, /'Pengeluaran Bon Sementara'[\s\S]{0,600}?'Alokasi Bon Sementara'[\s\S]{0,600}?'Realisasi Bon Sementara'/],
        ['Section', section, /'Pengeluaran Bon Sementara'[\s\S]{0,600}?'Alokasi Bon Sementara'[\s\S]{0,600}?'Realisasi Bon Sementara'/],
    ]) {
        cek(nama + ': Alokasi TEPAT di antara Pengeluaran dan Realisasi', pola.test(src),
            'daftar menu ini menyusun dirinya sendiri — yang tertinggal membuat sub-modulnya hilang dari satu pintu masuk');
    }
    cek('ketiganya menunjuk rute yang sama',
        (nav + hub + section).match(/bs\.advance\.allocation\.index/g)?.length === 3,
        'ada daftar menu yang menunjuk rute lain');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
