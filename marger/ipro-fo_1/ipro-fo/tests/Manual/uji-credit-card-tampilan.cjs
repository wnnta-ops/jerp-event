// Tampilan Set Up Credit Card + blok Debit VA pada layar bon sementara.
//
// KENAPA PERLU UJI:
//  · Tiga keadaan kartu (belum aktif · expired · aktif) berakibat BERBEDA saat membayar, tetapi
//    di layar ketiganya sama-sama "sebuah baris kartu bersaldo". Kalau bedanya tidak terbaca,
//    orang akan memilih kartu yang pasti ditolak servernya dan menyangka aplikasinya rusak.
//  · Saldo & masa berlaku TIDAK BOLEH ada sebagai kolom isian pada form Ubah Kartu. Kolom
//    semacam itu tidak menimbulkan galat apa pun — ia hanya diam-diam menjadi jalan pintas yang
//    menghapus seluruh jejak persetujuannya.
//  · Pemilih kartu hanya boleh muncul pada Debit VA. Kolom kosong yang menetap pada pembayaran
//    reguler mengundang isian yang tidak berarti apa-apa.
//
// Jalankan:  node tests/Manual/uji-credit-card-tampilan.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const HAL_KARTU = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/CreditCards/Index.vue');
const HAL_BON = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Advances/Show.vue');

/* Stub useForm menghormati globalThis.__ubahForm supaya cabang "debit_va" bisa dirender.
   Tanpa itu SSR selalu berhenti pada keadaan awal (reguler), dan separuh layar yang justru
   diuji di sini tidak pernah ikut ter-render. */
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
                  + " export const Link = { props: ['href'], setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                  + ' export const usePage = () => ({ props: { flash: {} } });'
                  + ' export const useForm = (d) => reactive({ ...d,'
                  + ' ...((globalThis.__ubahForm && globalThis.__ubahForm(d)) || {}),'
                  + ' processing: false, errors: {}, post(){}, put(){}, reset(){} });'
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

async function render(berkas, props) {
    // Nama bundel diturunkan dari path-nya: dua Index.vue berbeda akan saling menimpa bila
    // dinamai dari basename, dan require() lalu mengembalikan komponen yang salah tanpa galat.
    const out = path.join(ROOT, 'cc-' + require('crypto').createHash('sha1').update(berkas).digest('hex').slice(0, 10) + '.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({
        entryPoints: [berkas], bundle: true, format: 'cjs', platform: 'node',
        outfile: out, plugins: [plugin], external: ['vue'], logLevel: 'silent',
    });
    delete require.cache[out];
    const Komponen = require(out).default;
    globalThis.route = (n, p) => '/' + n + (p ? '/' + (p.id ?? p) : '');
    const app = createSSRApp({ render: () => h(Komponen, props) });
    app.config.globalProperties.route = globalThis.route;
    app.config.globalProperties.$page = { props: { flash: {} } };
    app.config.warnHandler = () => {};

    return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '');
}

const KARTU = [
    { id: 1, account_id: 18, account_code: '10101020210', account_name: 'MANDIRI REK. 141-00-9988886-9',
      card_number: '141-00-9988886-9', name: 'MANDIRI REK. 141-00-9988886-9', label: '141-00-9988886-9 — MANDIRI REK. 141-00-9988886-9',
      is_active: true, balance: 7500000, expired_date: '2027-01-31', expired: false, usable: true, note: null,
      saldo_awal: 7500000, penambahan: 2500000, pemakaian: 4000000, sisa_saldo: 6000000,
      closed: false, closed_at: null, prev_closed: true },
    { id: 2, account_id: 19, account_code: '10101020211', account_name: 'BCA VA',
      card_number: '888-1234', name: 'BCA VA', label: '888-1234 — BCA VA',
      is_active: false, balance: 0, expired_date: null, expired: false, usable: false, note: null,
      saldo_awal: 0, penambahan: 0, pemakaian: 0, sisa_saldo: 0,
      closed: false, closed_at: null, prev_closed: false },
    { id: 3, account_id: 20, account_code: '10101020212', account_name: 'BNI VA',
      card_number: '777-9999', name: 'BNI VA', label: '777-9999 — BNI VA',
      is_active: true, balance: 1250000, expired_date: '2026-01-01', expired: true, usable: false, note: null,
      saldo_awal: 0, penambahan: 250000, pemakaian: 250000, sisa_saldo: 0,
      closed: false, closed_at: null, prev_closed: false },
];
const PERMINTAAN = [
    { id: 11, credit_card_id: 1, card: '141-00-9988886-9 — MANDIRI REK. 141-00-9988886-9', type: 'topup',
      type_label: 'Penambahan Saldo', amount: 6000000, expired_date: null, status: 'pending', status_label: 'pending',
      reference: 'BON/2609/0021', source_type: 'cash_advance', note: null, created_at: null, approved_at: null },
    { id: 12, credit_card_id: 1, card: '141-00-9988886-9 — MANDIRI REK. 141-00-9988886-9', type: 'expiry',
      type_label: 'Masa Berlaku (Expired)', amount: null, expired_date: '2026-10-19', status: 'pending', status_label: 'pending',
      reference: 'BON/2609/0021', source_type: 'cash_advance', note: null, created_at: null, approved_at: null },
    { id: 13, credit_card_id: 1, card: '141-00-9988886-9 — MANDIRI REK. 141-00-9988886-9', type: 'reduce',
      type_label: 'Pengurangan Saldo', amount: 2500000, expired_date: null, status: 'approved', status_label: 'approved',
      reference: null, source_type: null, note: null, created_at: null, approved_at: null },
];
const TYPES = { topup: 'Penambahan Saldo', reduce: 'Pengurangan Saldo', expiry: 'Masa Berlaku (Expired)' };

/** Isi satu baris <tr> yang memuat sebuah untai. */
function barisMemuat(html, untai) {
    for (const m of html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)) {
        if (m[1].includes(untai)) return m[1];
    }

    return null;
}

const bonDasar = {
    id: 7, advance_number: 'BON/2609/0021', advance_date: '2026-09-04', holder: 'Eko Prasetyo',
    purpose: 'Operasional proyek', amount: 6000000, status: 'requested', settlement: 0,
    realized_amount: 0, approvals: {}, approval_mode: 'offline', payment_method: 'reguler',
    paid_date: null, va_expired_date: null, credit_card_id: null, credit_card: null,
    received_document_date: null, cash_account: null, lines: [],
};
const propsBon = (adv, kartu) => ({
    advance: adv, expenseAccounts: [], costCenters: [], cashAccounts: [{ id: 5, code: '1010', name: 'BCA' }],
    signers: {}, requiredSigners: [], fullyApproved: true, canApprove: false, canEditDocDate: false,
    approvalMode: 'offline', creditCards: kartu,
});

(async () => {
    console.log('\n=== 1. HALAMAN SET UP CREDIT CARD ===');
    globalThis.__ubahForm = null;
    const html = await render(HAL_KARTU, {
        cards: KARTU, requests: PERMINTAAN,
        accounts: [{ id: 21, label: '10101020213 — MANDIRI LAIN' }], types: TYPES, canApprove: true,
        period: { year: 2026, month: 9, label: 'September 2026' }, riwayat: null,
    });

    cek('judul halaman muncul', html.includes('Set Up Credit Card'));

    const bMandiri = barisMemuat(html, '141-00-9988886-9');
    const bBca = barisMemuat(html, '888-1234');
    const bBni = barisMemuat(html, '777-9999');
    cek('ketiga kartu terbaca', !!bMandiri && !!bBca && !!bBni);

    /* Ketiga keadaan HARUS terbaca berbeda — inilah yang menentukan kartu mana yang bisa dipakai.
       Diperiksa pada SEL STATUS saja (kolom ke-5), bukan pada seluruh barisnya: kata "Expired"
       juga muncul di tombol "Ajukan Saldo / Expired", sehingga pencarian se-baris akan
       menyatakan setiap kartu kedaluwarsa. */
    /* Letak kolom Status DIHITUNG dari kepala tabelnya, bukan ditulis sebagai angka tetap.
       Indeks tetap diam-diam menunjuk kolom yang salah begitu ada kolom ditambah — persis yang
       terjadi saat kolom Pemakaian & Sisa Saldo masuk: pemeriksaan lencana status berpindah ke
       sel rupiah dan ketiganya gagal tanpa ada yang benar-benar rusak. */
    const judulKartu = [...((html.match(/<tr class="text-left text-xs uppercase text-neutral-500">[\s\S]*?<\/tr>/) || [''])[0])
        .matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map((m) => m[1].replace(/<[^>]*>/g, '').trim());
    const iStatus = judulKartu.indexOf('Status');
    cek('kolom Status ditemukan di kepala tabel', iStatus >= 0, JSON.stringify(judulKartu));
    const selStatus = (baris) => (baris.match(/<td[^>]*>[\s\S]*?<\/td>/g) || [])[iStatus] || '';
    const sMandiri = selStatus(bMandiri);
    const sBca = selStatus(bBca);
    const sBni = selStatus(bBni);
    cek('kartu aktif & belum kedaluwarsa berlabel "Aktif"',
        sMandiri.includes('Aktif') && !sMandiri.includes('Belum Aktif') && !sMandiri.includes('Expired'), sMandiri);
    cek('kartu nonaktif berlabel "Belum Aktif"', sBca.includes('Belum Aktif'), sBca);
    cek('kartu kedaluwarsa berlabel "Expired"', sBni.includes('Expired') && !sBni.includes('Belum Aktif'), sBni);

    cek('saldo tampil terformat rupiah', bMandiri && bMandiri.includes('7.500.000'));

    /* Tiga angka berbeda asalnya pada baris yang sama: Saldo (yang boleh dipakai), Pemakaian
       (dari mutasi akun GL kartunya), dan Sisa Saldo (yang dikembalikan lewat Masuk Saldo).
       Diperiksa PER SEL menurut letak kolomnya, bukan "ada di baris ini" — ketiganya sama-sama
       rupiah, jadi mencarinya se-baris akan lulus meski nilainya tertukar antar kolom. */
    const selKe = (baris, nama) => (baris.match(/<td[^>]*>[\s\S]*?<\/td>/g) || [])[judulKartu.indexOf(nama)] || '';
    cek('keempat kolom bulanannya ada di kepala tabel',
        ['Saldo Awal','Penambahan','Pemakaian','Sisa Saldo'].every((k) => judulKartu.includes(k)), JSON.stringify(judulKartu));
    cek('Pemakaian tampil pada kolomnya sendiri', selKe(bMandiri, 'Pemakaian').includes('4.000.000'),
        selKe(bMandiri, 'Pemakaian'));
    cek('Sisa Saldo tampil pada kolomnya sendiri', selKe(bMandiri, 'Sisa Saldo').includes('6.000.000'),
        selKe(bMandiri, 'Sisa Saldo'));
    cek('Saldo Awal tampil pada kolomnya sendiri', selKe(bMandiri, 'Saldo Awal').includes('7.500.000'),
        selKe(bMandiri, 'Saldo Awal'));
    cek('Penambahan tampil pada kolomnya sendiri', selKe(bMandiri, 'Penambahan').includes('2.500.000'),
        selKe(bMandiri, 'Penambahan'));
    cek('saldo kartu nonaktif tampil 0', bBca && bBca.includes('Rp 0'));

    // Lebar tabel kartu: kepala & isi harus sama, kalau tidak kolomnya bergeser diam-diam.
    // Jumlah selnya dicocokkan dengan jumlah kepala kolomnya, bukan dengan angka tetap.
    const selMandiri = (bMandiri.match(/<td[^>]*>/g) || []).length;
    cek('jumlah sel baris kartu = jumlah kepala kolom', selMandiri === judulKartu.length,
        selMandiri + ' sel vs ' + judulKartu.length + ' kolom');
    cek('tabelnya punya kepala kolom', judulKartu.length > 0);

    console.log('\n=== 2. SALDO TIDAK BISA DISUNTING LANGSUNG ===');
    const src = fs.readFileSync(HAL_KARTU, 'utf8');
    const awalUbah = src.indexOf('formUbah = useForm');
    const barisUbah = src.slice(awalUbah, src.indexOf(';', src.indexOf('})', awalUbah)));
    cek('form Ubah Kartu tidak memuat kolom balance', !barisUbah.includes('balance'), barisUbah.trim());
    cek('form Ubah Kartu tidak memuat kolom expired_date', !barisUbah.includes('expired_date'));
    /* Saldo kartu kini dihitung per bulan dari dokumennya (pembayaran Debit VA & Alokasi
       Kasbon), jadi yang dijelaskan layar adalah RUMUSNYA — bukan lagi 'lewat persetujuan'. */
    cek('layar menjelaskan rumus saldo bulanannya',
        html.includes('Saldo Awal') && html.includes('Penambahan') && html.includes('Pemakaian') && html.includes('Sisa Saldo'));
    cek('form Ubah Kartu tetap tanpa jalan pintas menyunting saldo',
        !barisUbah.includes('balance') && !barisUbah.includes('expired_date'));

    console.log('\n=== 3. PERMINTAAN MENUNGGU & PERSETUJUANNYA ===');
    cek('bagian Menunggu Persetujuan ada', html.includes('Menunggu Persetujuan'));
    cek('permintaan pending dari bon tampil dengan nomor bonnya', html.includes('BON/2609/0021'));
    cek('tombol Setujui & Tolak muncul untuk yang berhak', html.includes('Setujui') && html.includes('Tolak'));
    cek('permintaan yang sudah diproses masuk Riwayat', html.includes('Riwayat Permintaan'));

    const tanpaHak = await render(HAL_KARTU, {
        cards: KARTU, requests: PERMINTAAN, accounts: [], types: TYPES, canApprove: false,
        period: { year: 2026, month: 9, label: 'September 2026' }, riwayat: null,
    });
    cek('tanpa hak approve, tombol Setujui tidak muncul', !tanpaHak.includes('Setujui'));
    cek('tanpa hak approve, alasannya disebut', tanpaHak.includes('tanpa hak approve'));

    console.log('\n=== 3b. AJUKAN PERMINTAAN MASA BERLAKU (EXPIRED) ===');

    /* BUG YANG DIJAGA DI SINI. Kolom "Nilai (Rp)" hanya DISEMBUNYIKAN saat jenisnya Masa Berlaku,
       bukan dikosongkan — formulirnya tetap memegang amount bawaan 0. Selama nilai sisa itu ikut
       terkirim, servernya menolaknya (min:0.01) dan pesannya menempel pada kolom yang justru
       sedang tidak tampil: tombol "Ajukan" tampak tidak mengerjakan apa pun.

       Diperiksa pada BADAN kirimMinta()-nya sendiri, bukan se-berkas: untai 'expiry' muncul di
       banyak tempat pada halaman ini (daftar jenis, label, tabel permintaan). */
    const badanKirim = (src.match(/function kirimMinta\(\)[\s\S]*?\n}/) || [''])[0];
    cek('badan kirimMinta() terbaca', badanKirim !== '');
    cek('kiriman disaring menurut jenisnya (transform)',
        badanKirim.includes('.transform('), badanKirim);
    // Kedua cabangnya dipisah dulu, lalu diperiksa sendiri-sendiri: yang dijaga adalah apa yang
    // TIDAK ikut terkirim, dan itu hanya terbaca dari isi cabangnya masing-masing.
    const cabangExpiry = (badanKirim.match(/\?\s*\{[^}]*expired_date[^}]*\}/) || [''])[0];
    const cabangSaldo = (badanKirim.match(/:\s*\{[^}]*amount[^}]*\}/) || [''])[0];
    cek('jenis Masa Berlaku mengirim tanggalnya', cabangExpiry.includes('expired_date: d.expired_date'), cabangExpiry);
    cek('jenis Masa Berlaku TIDAK ikut mengirim nilai saldo', ! cabangExpiry.includes('amount'), cabangExpiry);
    cek('jenis saldo mengirim nilainya', cabangSaldo.includes('amount: d.amount'), cabangSaldo);
    cek('jenis saldo TIDAK ikut mengirim tanggal expired', ! cabangSaldo.includes('expired_date'), cabangSaldo);

    /* Penolakan yang tidak punya kolom tempat menampilkannya harus tetap terbaca — itulah yang
       dulu mengubah "permintaan ditolak" menjadi "tombolnya rusak". */
    cek('ada penampung galat yang tak berkolom', src.includes('galatTersembunyi'));
    cek('penampung itu dirender di formnya',
        /<p v-if="galatTersembunyi"[^>]*>\{\{ galatTersembunyi \}\}<\/p>/.test(src));
    const badanGalat = (src.match(/const galatTersembunyi = computed\([\s\S]*?\n\}\);/) || [''])[0];
    cek('yang ditampilkan hanya galat di luar kolom yang sedang tampil',
        badanGalat.includes("formMinta.type === 'expiry' ? ['expired_date'] : ['amount']"), badanGalat);

    // Formnya sendiri: kedua kolom saling menggantikan menurut jenisnya.
    cek('kolom Tanggal Expired muncul untuk jenis expiry',
        /v-else>\s*<label[^>]*>Tanggal Expired \*<\/label>/.test(src.replace(/\n\s*/g, ' ')));
    cek('kolom Nilai hanya untuk jenis selain expiry',
        /v-if="formMinta\.type !== 'expiry'">\s*<label[^>]*>Nilai \(Rp\) \*<\/label>/.test(src.replace(/\n\s*/g, ' ')));
    /* Diperiksa pada sumbernya: form "Ajukan Permintaan" baru ter-render setelah tombol
       "Ajukan Saldo / Expired" ditekan (v-if="kartuPermintaan"), dan itu tidak bisa dilakukan
       lewat SSR. */
    cek('layar menjelaskan bahwa permintaannya masuk antrean persetujuan',
        src.includes('Masuk ke Menunggu Persetujuan'));
    cek('untuk jenis expiry, penjelasnya menyebut masa berlaku kartu',
        src.includes('masa berlaku kartunya berubah setelah disetujui'));

    console.log('\n=== 4. PEMILIH KARTU PADA LAYAR BON ===');
    const kartuUsable = [{ id: 1, label: '141-00-9988886-9 — MANDIRI REK. 141-00-9988886-9', balance: 7500000 }];

    /* Keterangan jurnal dibaca dari baris "Jurnal: Dr … · Cr …" saja. Paragraf pengantar di
       atas form menyebut KEDUA kemungkinan sekaligus, jadi mencari untainya di seluruh halaman
       akan selalu menemukan "Dr Uang Muka Operasional" — termasuk pada bon Debit VA. */
    /* Ringkasan jurnal pada blok pembayaran. Diambil dari KOTAK RINGKASANNYA, bukan dari untai
       'Jurnal: Dr …': pada Debit VA kotak itu justru berbunyi 'Tidak ada jurnal', sehingga pola
       lama akan mengembalikan untai kosong dan setiap pemeriksaannya lulus/gagal palsu. */
    const ketJurnal = (html) => ((html.match(/<div class="md:col-span-2 flex items-center justify-between rounded-lg bg-amber-50[\s\S]*?<\/div>/) || [''])[0])
        .replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

    globalThis.__ubahForm = null;
    const bonReguler = await render(HAL_BON, propsBon({ ...bonDasar }, kartuUsable));
    cek('pada pembayaran reguler pemilih kartu TIDAK muncul', !bonReguler.includes('Nomor Kartu Kredit / VA'));
    cek('pada pembayaran reguler keterangan jurnal tetap Uang Muka Operasional',
        ketJurnal(bonReguler).includes('Dr Uang Muka Operasional'), ketJurnal(bonReguler));

    // Cabang Debit VA — hanya form pembayaran yang digeser, bukan form realisasi.
    globalThis.__ubahForm = (d) => ('va_expired_date' in d ? { payment_method: 'debit_va', credit_card_id: 1 } : null);
    const bonVa = await render(HAL_BON, propsBon({ ...bonDasar }, kartuUsable));
    cek('pada Debit VA pemilih kartu muncul', bonVa.includes('Nomor Kartu Kredit / VA'));
    cek('pada Debit VA tanggal expired tetap diminta', bonVa.includes('Tanggal Expired VA'));
    /* Pembayaran Debit VA tidak berjurnal lagi: ringkasannya karena itu menyebut bahwa jurnal
       baru terbentuk saat ALOKASI KASBON, bukan menyebut akun kartu sebagai sisi debit. */
    cek('ringkasannya menyebut Debit VA tanpa jurnal',
        ketJurnal(bonVa).includes('Tidak ada jurnal'), ketJurnal(bonVa));
    cek('ringkasannya menunjuk Alokasi Kasbon sebagai pembentuk jurnalnya',
        ketJurnal(bonVa).includes('Alokasi Kasbon'), ketJurnal(bonVa));

    cek('disebutkan nominalnya menjadi Penambahan saldo kartunya',
        bonVa.includes('Penambahan') && bonVa.includes('Set Up Credit Card'));
    cek('disebutkan hanya masa berlakunya yang menunggu persetujuan',
        bonVa.includes('masa berlakunya') && bonVa.includes('menunggu persetujuan'));

    // Tanpa satu pun kartu aktif: penyebabnya harus disebut, bukan sekadar dropdown kosong.
    globalThis.__ubahForm = (d) => ('va_expired_date' in d ? { payment_method: 'debit_va' } : null);
    const bonTanpaKartu = await render(HAL_BON, propsBon({ ...bonDasar }, []));
    cek('daftar kartu kosong menyebut Set Up Credit Card', bonTanpaKartu.includes('Set Up Credit Card'));

    console.log('\n=== 5. RINGKASAN BON YANG SUDAH DIBAYAR VIA VA ===');
    globalThis.__ubahForm = null;
    const bonSudahVa = await render(HAL_BON, propsBon({
        ...bonDasar, status: 'outstanding', paid_date: '2026-09-04', payment_method: 'debit_va',
        va_expired_date: '2026-10-19', credit_card_id: 1,
        credit_card: { id: 1, name: 'MANDIRI REK. 141-00-9988886-9', card_number: '141-00-9988886-9' },
    }, kartuUsable));
    cek('ringkasan menyebut Pembayaran Debit VA', bonSudahVa.includes('Pembayaran Debit VA'));
    cek('nomor kartunya tampil', bonSudahVa.includes('141-00-9988886-9'));
    cek('tanggal expired VA tampil', bonSudahVa.includes('19 Oktober 2026'));

    const bonSudahReguler = await render(HAL_BON, propsBon({
        ...bonDasar, status: 'outstanding', paid_date: '2026-09-04', payment_method: 'reguler',
    }, kartuUsable));
    cek('bon reguler yang sudah dibayar tidak menampilkan blok VA',
        !bonSudahReguler.includes('Pembayaran Debit VA'));

    console.log('\n=== HASIL ===');
    console.log('  lulus: ' + lulus + ' · gagal: ' + gagal);
    console.log(gagal === 0 ? '  SEMUA LULUS' : '  ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
