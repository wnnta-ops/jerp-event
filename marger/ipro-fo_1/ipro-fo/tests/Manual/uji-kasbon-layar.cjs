// Layar: Alokasi Kasbon pada Bon Debit VA · Set Up Debit Card per bulan.
//
// KENAPA PERLU UJI:
//  · Kolom "Sumber Bank/Kas" harus HILANG pada Debit VA. Bila ia tetap ada, operator mengisi
//    rekening yang tidak pernah dipakai — dan tombolnya dulu mati selama kolom itu kosong,
//    sehingga pembayaran Debit VA sama sekali tidak bisa disimpan.
//  · Blok ALOKASI KASBON adalah satu-satunya jalan mengeluarkan uang bon Debit VA dari bank.
//    Kalau ia tidak muncul, bonnya menggantung sebagai outstanding tanpa cara menyelesaikannya.
//  · Empat kolom kartu (Saldo Awal · Penambahan · Pemakaian · Sisa Saldo) mudah tertukar
//    urutannya; angka yang bergeser satu kolom tidak menimbulkan galat apa pun.
//  · Layar Outstanding Kasbon diuji tersendiri di uji-outstanding-kasbon-layar.cjs.
//
// Jalankan:  node tests/Manual/uji-kasbon-layar.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const SHOW = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Show.vue');
const KARTU = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/CreditCards/Index.vue');

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
                  + ' export const useForm = (d) => reactive({ ...d, processing: false, errors: {},'
                  + '   post(){}, put(){}, delete(){}, reset(){} });'
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

async function render(berkas, props, tag) {
    const out = path.join(ROOT, 'kasbon-' + tag + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [berkas], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    globalThis.route = (n, p) => '/' + n + (p != null ? '/' + (p?.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

/** Judul kolom dari <tr> pertama pada <thead> ke-i. */
function judulKolom(html, i = 0) {
    const theads = [...html.matchAll(/<thead[^>]*>([\s\S]*?)<\/thead>/g)].map((m) => m[1]);
    const trPertama = ((theads[i] ?? '').match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];

    return [...trPertama.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
        .map((m) => m[1].replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim());
}
/** Potongan template sebuah berkas (komentar dibuang). */
function template(file) {
    const s = fs.readFileSync(file, 'utf8');

    return ((s.match(/<template>[\s\S]*<\/template>/) || [''])[0]).replace(/<!--[\s\S]*?-->/g, '');
}

const BON_DASAR = {
    id: 9, advance_number: 'BON/3205/0001', advance_date: '2032-05-01', holder: 'Eko Prasetyo',
    purpose: 'Uji alokasi', amount: 2000000, cost_center_id: 1, approvals: {},
    payment_method: 'debit_va', va_expired_date: '2032-12-31', credit_card_id: 3, journal_id: null,
    cash_account_id: null, paid_date: '2032-05-10', realized_amount: 0, settlement: 0,
    settlement_status: null, lines: [], credit_card: { card_number: 'UJI-VA-001', name: 'KARTU UJI' },
};
const AKUN = [{ id: 1, label: '10101010101 — KAS' }, { id: 2, label: '10101020204 — BCA' }];

(async () => {
    console.log('\n=== 1. PEMBAYARAN — Sumber Bank/Kas hanya untuk reguler ===');

    const bayar = await render(SHOW, {
        advance: { ...BON_DASAR, status: 'requested' },
        expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline',
        creditCards: [{ id: 3, label: 'UJI-VA-001 — KARTU UJI', balance: 0 }],
        allocations: { rows: [], total: 0, sisa: 2000000 }, allocationMode: true,
    }, 'bayar');

    // Bawaan formnya 'reguler', jadi kolomnya MEMANG harus tampil di sini.
    cek('pada pembayaran reguler, Sumber Bank/Kas tetap ada', bayar.includes('Sumber Bank/Kas'));

    /* Yang tidak bisa dilihat lewat SSR (radio-nya tidak bisa ditekan di sini) diperiksa pada
       templatenya — dan DISKOPKAN ke div kolom itu saja, sebab untai 'debit_va' muncul belasan
       kali di berkas ini. */
    const tShow = template(SHOW);
    const divSumber = (tShow.match(/<div[^>]*>\s*<label[^>]*>Sumber Bank\/Kas[\s\S]*?<\/div>/) || [''])[0];
    const bukaDivSumber = (tShow.match(/<div[^>]*>(?=\s*<label[^>]*>Sumber Bank\/Kas)/) || [''])[0];
    cek('kolom Sumber Bank/Kas disembunyikan saat Debit VA',
        /v-if="payForm\.payment_method !== 'debit_va'"/.test(bukaDivSumber), bukaDivSumber || '(div tidak ketemu)');
    cek('kolom itu memang kolom rekening (SearchSelect cashAccounts)',
        divSumber.includes(':options="cashAccounts"'), divSumber.slice(0, 120));

    // Tombol simpan TIDAK boleh lagi menuntut cash_account_id pada Debit VA.
    const tombolBayar = (tShow.match(/<Button type="submit"[^>]*>[\s\S]*?Pembayaran[\s\S]*?<\/Button>/) || [''])[0];
    cek('tombol simpan tidak menuntut bank saat Debit VA',
        /payForm\.payment_method !== 'debit_va' && !payForm\.cash_account_id/.test(tombolBayar), tombolBayar.slice(0, 200));
    cek('ringkasan menyebut Debit VA tidak berjurnal',
        /Tidak ada jurnal/.test(tShow) && /Alokasi Kasbon/.test(tShow));

    console.log('\n=== 2. BLOK ALOKASI KASBON ===');

    const alokasi = await render(SHOW, {
        advance: { ...BON_DASAR, status: 'outstanding' },
        expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline',
        creditCards: [],
        allocations: {
            rows: [
                { id: 11, alloc_date: '2032-05-10', description: 'Beli ATK', amount: 500000, account_id: 1, account: '10101010101 — KAS', journal_id: 21 },
                { id: 12, alloc_date: '2032-05-12', description: 'Transport', amount: 300000, account_id: 2, account: '10101020204 — BCA', journal_id: 22 },
            ],
            total: 800000, sisa: 1200000,
        },
        allocationMode: true,
    }, 'alokasi');

    cek('blok Alokasi Kasbon tampil pada bon outstanding', alokasi.includes('Alokasi Kasbon'));
    const judulAlok = judulKolom(alokasi, 0);
    cek('kolomnya: Tanggal, Keterangan, Bank Pengeluaran, Jumlah, Aksi',
        JSON.stringify(judulAlok) === JSON.stringify(['Tanggal', 'Keterangan', 'Bank Pengeluaran', 'Jumlah', 'Aksi']),
        JSON.stringify(judulAlok));
    cek('kedua baris alokasi ter-render', alokasi.includes('Beli ATK') && alokasi.includes('Transport'));
    cek('bank pengeluaran tiap baris ditampilkan',
        alokasi.includes('10101010101 — KAS') && alokasi.includes('10101020204 — BCA'));
    cek('total alokasi ditampilkan', alokasi.includes('800.000'));
    cek('sisa yang belum dialokasikan ditampilkan', alokasi.includes('1.200.000'));
    cek('disebutkan sisanya tetap menjadi saldo kartu', /tetap menjadi saldo kartu/.test(alokasi));
    cek('form tambah alokasi punya keempat isiannya',
        /Tanggal \*/.test(alokasi) && /Keterangan \*/.test(alokasi) && /Jumlah \*/.test(alokasi) && /Bank Pengeluaran \*/.test(alokasi));
    cek('jurnal alokasi dijelaskan di formnya', /Dr Uang Muka Operasional · Cr bank pengeluaran/.test(alokasi));

    // Bon REGULER tidak boleh punya blok ini — uangnya sudah keluar saat dibayar.
    const reguler = await render(SHOW, {
        advance: { ...BON_DASAR, status: 'outstanding', payment_method: 'reguler', credit_card_id: null, journal_id: 5, cash_account_id: 1 },
        expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline',
        creditCards: [], allocations: { rows: [], total: 0, sisa: 0 }, allocationMode: false,
    }, 'reguler');
    cek('bon reguler TIDAK menampilkan blok Alokasi Kasbon', ! reguler.includes('Alokasi Kasbon'));
    cek('bon reguler tetap bisa direalisasi', reguler.includes('Realisasikan Bon'));

    console.log('\n=== 3. MASUK SALDO — hanya bon Debit VA model lama ===');

    const dasarRealized = {
        expenseAccounts: [], costCenters: [], cashAccounts: AKUN,
        signers: {}, requiredSigners: [], fullyApproved: true, approvalMode: 'offline', creditCards: [],
    };
    const baru = await render(SHOW, {
        ...dasarRealized,
        advance: { ...BON_DASAR, status: 'realized', settlement: 200000, settlement_status: 'pending', realized_amount: 600000 },
        allocations: { rows: [], total: 800000, sisa: 1200000 }, allocationMode: true,
    }, 'realized-baru');
    const lama = await render(SHOW, {
        ...dasarRealized,
        advance: { ...BON_DASAR, status: 'realized', settlement: 200000, settlement_status: 'pending', realized_amount: 600000, journal_id: 77 },
        allocations: { rows: [], total: 0, sisa: 0 }, allocationMode: false,
    }, 'realized-lama');

    cek('bon model baru TIDAK menawarkan Masuk Saldo', ! baru.includes('Masuk Saldo'));
    cek('bon model baru tetap menawarkan Refund', baru.includes('Refund Sisa'));
    cek('bon model lama masih menawarkan Masuk Saldo', lama.includes('Masuk Saldo'));

    /* Ringkasan realisasi tidak ter-render sebelum tombol "Realisasikan Bon" ditekan, jadi ia
       diperiksa pada sumbernya — dan DISKOPKAN ke definisi acuannya, sebab 'allocationMode'
       muncul di banyak tempat pada berkas ini. */
    const skripShow = fs.readFileSync(SHOW, 'utf8');
    const defAcuan = (skripShow.match(/const acuanPanjar = computed\([\s\S]*?\)\);/) || [''])[0];
    cek('acuan realisasi bon Debit VA = total alokasi, bukan nominal bon',
        /allocationMode/.test(defAcuan) && /allocations\?\.total/.test(defAcuan), defAcuan || '(tidak ada)');
    const barisAcuan = (tShow.match(/<div class="flex justify-between"><span>\{\{ allocationMode[\s\S]*?<\/div>/) || [''])[0];
    cek('ringkasan realisasi menampilkan acuan itu',
        barisAcuan.includes('fmtRp(acuanPanjar)'), barisAcuan || '(tidak ada)');

    console.log('\n=== 4. SET UP CREDIT CARD — empat kolom per bulan ===');

    const kartu = await render(KARTU, {
        cards: [{
            id: 3, account_id: 2, account_code: '10101020210', account_name: 'MANDIRI', card_number: 'UJI-VA-001',
            name: 'KARTU UJI', label: 'UJI-VA-001 — KARTU UJI', is_active: true, balance: 0,
            expired_date: '2032-12-31', expired: false, usable: true, note: null,
            saldo_awal: 1000000, penambahan: 2000000, pemakaian: 800000, sisa_saldo: 2200000,
            closed: false, closed_at: null, prev_closed: true,
        }],
        requests: [], accounts: [{ id: 2, label: '10101020210 — MANDIRI' }],
        types: { topup: 'Penambahan Saldo', reduce: 'Pengurangan Saldo', expiry: 'Masa Berlaku (Expired)' },
        canApprove: true,
        period: { year: 2032, month: 5, label: 'Mei 2032' },
        riwayat: {
            card_id: 3, label: 'UJI-VA-001 — KARTU UJI',
            rows: [
                { year: 2032, month: 4, label: 'April 2032', saldo_awal: 0, penambahan: 1500000, pemakaian: 500000, sisa_saldo: 1000000, closed: true, closed_at: '2032-05-01 09:00:00', prev_closed: false },
                { year: 2032, month: 5, label: 'Mei 2032', saldo_awal: 1000000, penambahan: 2000000, pemakaian: 800000, sisa_saldo: 2200000, closed: false, closed_at: null, prev_closed: true },
            ],
        },
    }, 'kartu');

    const judulKartu = judulKolom(kartu, 0);
    cek('urutan kolom kartu: Saldo Awal, Penambahan, Pemakaian, Sisa Saldo',
        JSON.stringify(judulKartu.slice(2, 6)) === JSON.stringify(['Saldo Awal', 'Penambahan', 'Pemakaian', 'Sisa Saldo']),
        JSON.stringify(judulKartu));
    cek('kolom Saldo lama sudah tidak ada lagi', ! judulKartu.includes('Saldo'), JSON.stringify(judulKartu));
    cek('ada kolom Closing', judulKartu.includes('Closing'), JSON.stringify(judulKartu));
    /* Diskopkan ke judul kartunya: "Mei 2032" juga muncul sebagai salah satu BARIS riwayat, jadi
       pencarian se-halaman akan lulus walaupun periodenya sudah tidak disebutkan di judul. */
    const judulTabelKartu = (kartu.match(/<h2 class="font-semibold">Kartu[\s\S]*?<\/h2>/) || [''])[0];
    cek('periodenya disebutkan di judul tabel', judulTabelKartu.includes('Mei 2032'), judulTabelKartu || '(judul tidak ketemu)');

    const barisKartu = ((kartu.match(/<tbody[^>]*>([\s\S]*?)<\/tbody>/) || ['', ''])[1].match(/<tr[^>]*>[\s\S]*?<\/tr>/) || [''])[0];
    const selKartu = [...barisKartu.matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => m[1].replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim());
    cek('nilai Saldo Awal di kolomnya sendiri', selKartu[2] === 'Rp 1.000.000', selKartu[2]);
    cek('nilai Penambahan di kolomnya sendiri', selKartu[3] === 'Rp 2.000.000', selKartu[3]);
    cek('nilai Pemakaian di kolomnya sendiri', selKartu[4] === 'Rp 800.000', selKartu[4]);
    cek('nilai Sisa Saldo di kolomnya sendiri', selKartu[5] === 'Rp 2.200.000', selKartu[5]);
    cek('tombol Closing tampil untuk bulan yang belum ditutup', /Closing<\/button>/.test(barisKartu));

    // Peringatan "bulan sebelumnya belum closing" harus muncul HANYA bila memang begitu.
    const belum = await render(KARTU, {
        cards: [{
            id: 3, account_id: 2, account_code: '10101020210', account_name: 'MANDIRI', card_number: 'UJI-VA-001',
            name: 'KARTU UJI', label: 'UJI-VA-001 — KARTU UJI', is_active: true, balance: 0,
            expired_date: null, expired: false, usable: true, note: null,
            saldo_awal: 0, penambahan: 0, pemakaian: 0, sisa_saldo: 0,
            closed: false, closed_at: null, prev_closed: false,
        }],
        requests: [], accounts: [], types: {}, canApprove: true,
        period: { year: 2032, month: 5, label: 'Mei 2032' }, riwayat: null,
    }, 'kartu-belum');
    cek('ditandai bila bulan sebelumnya belum di-closing', belum.includes('blm closing'));
    cek('penanda itu TIDAK muncul bila sudah closing', ! kartu.includes('blm closing'));

    console.log('\n=== 5. RIWAYAT DETAIL KARTU PER BULAN ===');

    cek('tabel riwayat tampil bila kartunya dipilih', kartu.includes('Riwayat Detail'));
    const judulRiwayat = judulKolom(kartu, 1);
    cek('kolom riwayat: Bulan + keempat angkanya + Closing',
        JSON.stringify(judulRiwayat) === JSON.stringify(['Bulan', 'Saldo Awal', 'Penambahan', 'Pemakaian', 'Sisa Saldo', 'Closing']),
        JSON.stringify(judulRiwayat));
    cek('kedua bulan riwayat ter-render', kartu.includes('April 2032') && kartu.includes('Mei 2032'));
    cek('bulan yang sudah ditutup ditandai Closed', /Closed<\/button>/.test(kartu));
    cek('riwayat tidak tampil bila tidak ada kartu yang dipilih', ! belum.includes('Riwayat Detail'));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
