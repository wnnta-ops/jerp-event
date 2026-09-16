// Langkah "Struktur" pada Registrasi Invoice Hutang (BusinessSupport/Payable/Form.vue):
// tombol "Hitung ulang" dihilangkan, dan kolom Nilai Transaksi memakai pemisah ribuan.
//
// Dua hal yang dijaga, dan dua-duanya tidak memunculkan galat apa pun kalau salah:
//
//  1. MENGHAPUS TOMBOL MENINGGALKAN PETUNJUK YANG MENGGANTUNG. "Hitung ulang" dirujuk oleh DUA
//     teks lain di layar yang sama — peringatan struktur usang dan baris "belum ada baris".
//     Menghapus tombolnya saja membuat keduanya menyuruh menekan tombol yang tidak ada, dan
//     satu-satunya jalan menyusun ulang struktur menjadi tak terungkap. Karena itu yang diuji
//     BUKAN sekadar "tombolnya hilang", melainkan: setiap nama tombol yang DISEBUT teks panduan
//     benar-benar ada sebagai kontrol di halaman yang sama.
//  2. PEMISAH RIBUAN TIDAK MUNGKIN PADA input type=number. Peramban mewajibkan isinya angka
//     mentah yang sah, dan "1.000.000" bukan salah satunya — jadi selama elemennya masih
//     type=number, permintaan ini mustahil dipenuhi betapa pun CSS-nya diatur.
//
// Ikut dijaga: urutan di dalam pengolah @update:model-value (nilai harus ditulis SEBELUM
// applyAmount dipanggil, kalau tidak sisi Debit/Kredit memakai angka yang lama), dan janji pada
// teks panduan bahwa struktur tersusun ulang saat masuk langkah 4 dengan daftar kosong.
//
// Jalankan:  node tests/Manual/uji-struktur-invoice-hutang.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h, ref } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Form.vue');
const MONEY = path.join(ROOT, 'resources/js/Components/UI/MoneyInput.vue');

// Gabungan ikon yang diimpor SELURUH pohon komponen halaman ini — Form.vue sendiri plus
// Button, SearchSelect, dan MoneyInput. Didaftar tegas, bukan lewat Proxy: modul Proxy tak
// punya own-keys, sehingga interop ESM esbuild memulangkan undefined untuk tiap impor bernama
// dan Vue mengeluh "Invalid vnode type: undefined" alih-alih gagal terang-terangan.
const IKON = ['ArrowLeft', 'ArrowRight', 'Check', 'ChevronsUpDown', 'ClipboardPaste', 'Copy',
    'HandCoins', 'Loader2', 'Plus', 'Save', 'Search', 'Trash2', 'X'];

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h, reactive } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
                  + " export const Link = { setup: (p, { slots }) => () => h('a', {}, slots.default ? slots.default() : []) };"
                  + " export const Head = { setup: () => () => null };"
                  + ' export const useForm = (o) => reactive({ ...o, processing: false, errors: {}, post(){}, put(){}, reset(){} });'
                : "import { h } from 'vue';" + IKON.map((n) => ` export const ${n} = { setup: () => () => h('span') };`).join(''),
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /\.vue$/ }, (a) => {
            const { descriptor } = sfc.parse(fs.readFileSync(a.path, 'utf8'), { filename: a.path });
            const id = 'x' + Buffer.from(a.path).toString('hex').slice(0, 8);

            return { contents: sfc.compileScript(descriptor, { id, inlineTemplate: true }).content, loader: 'ts', resolveDir: path.dirname(a.path) };
        });
    },
};

let lulus = 0, gagal = 0;
const cek = (nama, ok, ket) => {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
};

async function bundel(entry, opts) {
    const b = await esbuild.build({
        entryPoints: [entry], bundle: true, write: false, format: 'cjs', platform: 'node',
        external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
        ...opts,
    });
    const m = { exports: {} };
    new Function('module', 'exports', 'require', b.outputFiles[0].text)(m, m.exports, require);

    return m.exports;
}

const PROPS = {
    suppliers: [{ id: 1, name: 'PT UJI Supplier', code: 'SP.001' }],
    categories: [{ id: 5, name: 'Biaya Internet', debit_id: 11, payable_id: 21 }],
    pphAccounts: [{ id: 31, code: '20104040402', name: 'HUTANG PPH PASAL 21' }],
    pphDefaults: { pph21: 31 },
    ppnAccount: { id: 41, code: '10105010101', name: 'PPN MASUKAN' },
    pphBorneExpense: { id: 51, code: '50302020228', name: 'BIAYA UMUM & ADMINISTRASI LAINNYA' },
    accounts: [
        { id: 11, code: '50302020218', name: 'BIAYA INTERNET DAN TELEKOMUNIKASI' },
        { id: 21, code: '20101010101', name: 'UTANG USAHA SUPPLIER' },
        { id: 41, code: '10105010101', name: 'PPN MASUKAN' },
    ],
    costCenters: [{ id: 2, code: 'CC-HO', name: 'Head Office' }],
    departments: [], ledgers: [], locations: [], units: [],
    trxTypes: { normal: 'Normal' },
};

async function main() {
    const srcForm = fs.readFileSync(FORM, 'utf8');

    // ── Render halaman sungguhan ────────────────────────────────────────────────────────────
    const modForm = await bundel(FORM);
    const app = createSSRApp({ render: () => h(modForm.default, PROPS) });
    app.config.globalProperties.route = (n, p) => '/' + n + (p === undefined ? '' : '/' + p);
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&#39;/g, "'").replace(/&quot;/g, '"')
        .replace(/&amp;/g, '&').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');

    console.log('\n=== 1. Tombol "Hitung ulang" benar-benar hilang dari layar ===');

    cek('tidak ada tombol/teks "Hitung ulang" di halaman', ! /Hitung ulang/i.test(teks),
        'masih tersisa di layar');
    cek('fungsi recalcJournal ikut dihapus (bukan jadi kode mati)', ! /recalcJournal/.test(srcForm),
        'fungsinya masih ada tanpa satu pun pemanggil');
    cek('panel Struktur Jurnal tetap ada', teks.includes('Struktur Jurnal'));
    cek('tombol Tambah 1 Baris tetap ada', teks.includes('Tambah 1 Baris'));

    console.log('\n=== 2. Petunjuk hanya menyebut tombol yang benar-benar ada ===');

    /* Render SSR berdiri di langkah 1, sedangkan panduannya dibaca di langkah 4 — dan tombol
       "Sebelumnya" memang belum ada di langkah 1 (v-if="step > 1"). Jadi keberadaan tombol
       dinilai dari sumbernya pada keadaan step = 4: cari label yang disebut panduan, ambil
       gerbang v-if pada tag tombolnya, lalu hitung gerbang itu untuk step = 4. */
    function tombolPadaLangkah(nama, langkah) {
        const idx = srcForm.indexOf(nama + '</button>') >= 0
            ? srcForm.indexOf(nama + '</button>')
            : srcForm.indexOf(nama + '</Button>');
        if (idx < 0) return { ada: false, tampil: false, syarat: '(tidak ditemukan)' };

        const awal = Math.max(srcForm.lastIndexOf('<button', idx), srcForm.lastIndexOf('<Button', idx));
        if (awal < 0) return { ada: false, tampil: false, syarat: '(bukan tombol)' };

        // Akhir tag dicari dengan MELEWATI isi tanda kutip. Gerbang yang diperiksa di sini
        // berbunyi v-if="step > 1" — tanda '>' ada DI DALAM nilai atributnya, sehingga
        // indexOf('>') memotong tagnya menjadi '<button v-if="step ' dan gerbangnya tidak
        // pernah terbaca: pemeriksaannya lolos tanpa menguji apa pun.
        let akhir = awal, kutip = '';
        while (akhir < srcForm.length) {
            const c = srcForm[akhir];
            if (kutip) { if (c === kutip) kutip = ''; }
            else if (c === '"' || c === "'") kutip = c;
            else if (c === '>') break;
            akhir++;
        }
        const tag = srcForm.slice(awal, akhir);
        const g = /v-if="([^"]+)"/.exec(tag);
        if (! g) return { ada: true, tampil: true, syarat: '(tanpa gerbang)' };

        try {
            return { ada: true, tampil: !! new Function('step', 'return (' + g[1] + ');')(langkah), syarat: g[1] };
        } catch (e) {
            return { ada: true, tampil: false, syarat: g[1] + ' — tidak bisa dihitung: ' + e.message };
        }
    }

    // Nama kontrol yang DISEBUT teks panduan pada panel Struktur, dikutip apa adanya dari layar.
    const panduan = [
        /<p v-if="strukturUsang"[\s\S]*?<\/p>/, /Belum ada baris[\s\S]{0,260}?<\/td>/,
    ].map((re) => (srcForm.match(re) || [''])[0]).join(' ');

    const disebut = [];
    for (const m of [...panduan.matchAll(/<b>([^<]{3,24})<\/b>/g), ...panduan.matchAll(/"([A-Z][A-Za-z0-9 ]{2,24})"/g)]) {
        const n = m[1].trim();
        if (! disebut.includes(n)) disebut.push(n);
    }

    cek('petunjuk memang menyebut nama tombol (bukan lolos karena kosong)', disebut.length >= 3,
        JSON.stringify(disebut));

    const menggantung = disebut.map((n) => ({ n, ...tombolPadaLangkah(n, 4) })).filter((x) => ! x.ada || ! x.tampil);
    cek('setiap tombol yang disebut petunjuk benar-benar ada di langkah 4', menggantung.length === 0,
        JSON.stringify(menggantung));

    for (const n of ['Sebelumnya', 'Berikutnya', 'Tambah 1 Baris']) {
        const t = tombolPadaLangkah(n, 4);
        cek('tombol "' + n + '" tampil di langkah 4', t.ada && t.tampil, t.syarat);
    }
    // Penjaga arah sebaliknya: kalau nama tombolnya salah ketik, pemeriksaan di atas harus merah.
    cek('nama tombol yang tidak ada memang terdeteksi tidak ada',
        ! tombolPadaLangkah('Hitung ulang', 4).ada, 'tombol yang sudah dihapus masih terbaca ada');

    console.log('\n=== 3. Baris "belum ada baris" mengarahkan ke jalan yang nyata ===');

    cek('pesan tabel kosong ada', teks.includes('Belum ada baris'));
    cek('pesan tabel kosong tidak lagi menyuruh klik Hitung ulang', ! /Belum ada baris[^.]*Hitung ulang/i.test(teks));
    cek('pesan tabel kosong menyebut Sebelumnya & Berikutnya',
        /Belum ada baris[\s\S]{0,200}Sebelumnya[\s\S]{0,40}Berikutnya/.test(teks), 'jalan menyusun ulang tidak disebutkan');
    // Janji itu harus benar: watch(step) memang membentuk struktur saat masuk langkah 4 kosong.
    cek('janjinya ditopang kode — watch(step) menyusun struktur saat langkah 4 & daftar kosong',
        /if \(s === 4\)[\s\S]{0,200}journal_lines\.length === 0[\s\S]{0,60}pasangStruktur\(defaultJournal\(\)\)/.test(srcForm),
        'teksnya menjanjikan sesuatu yang tidak dikerjakan kode');

    console.log('\n=== 4. Kolom Nilai Transaksi: pemisah ribuan ===');

    const sel = /<td class="px-2 py-2">\s*<MoneyInput :model-value="l\.nilai"([\s\S]{0,260}?)\/>/.exec(srcForm);
    cek('kolom Nilai Transaksi memakai MoneyInput', !!sel, 'masih memakai input biasa');
    cek('kolom itu tidak lagi input type=number',
        ! /v-model\.number="l\.nilai"[^>]*type="number"/.test(srcForm),
        'type=number tidak akan pernah menampilkan pemisah ribuan — isinya wajib angka mentah');
    cek('ukurannya disetel agar muat di sel tabel padat',
        !!sel && /!h-8/.test(sel[1]) && /!text-xs/.test(sel[1]),
        'MoneyInput bawaan h-9/text-sm — lebih tinggi dari input Kurs di sel yang sama');
    cek('sekarang sebaris dengan Nilai Transaksi di langkah Input Transaksi',
        (srcForm.match(/<MoneyInput/g) || []).length >= 4, 'langkah 2 sudah memakai MoneyInput sejak dulu');

    console.log('\n=== 5. MoneyInput benar-benar memberi tanda ribuan ===');

    // Dipanggil langsung, bukan dilihat dari hasil render: yang menentukan benar-tidaknya
    // adalah nilai yang DIPANCARKAN balik, dan itu tidak terlihat di HTML.
    const { descriptor } = sfc.parse(fs.readFileSync(MONEY, 'utf8'), { filename: MONEY });
    // TANPA inlineTemplate: setup() memulangkan binding-nya (display, onInput) alih-alih fungsi
    // render, sehingga bisa dipanggil langsung. Hasil compileScript masih TypeScript
    // (MoneyInput memakai lang="ts"), jadi dilucuti dulu oleh esbuild.
    const kode = esbuild.transformSync(sfc.compileScript(descriptor, { id: 'money' }).content,
        { loader: 'ts', format: 'cjs' }).code;
    const mm = { exports: {} };
    new Function('module', 'exports', 'require', kode)(mm, mm.exports, require);

    const dipancar = [];
    const setup = mm.exports.default.setup;
    const bind = setup({ modelValue: 1000000, placeholder: '0', min: 0, disabled: false },
        { emit: (e, v) => dipancar.push([e, v]), attrs: {}, slots: {}, expose() {} });

    cek('nilai 1000000 ditampilkan sebagai 1.000.000', bind.display.value === '1.000.000', String(bind.display.value));

    bind.onInput({ target: { value: '1234567' } });
    cek('mengetik 1234567 langsung berubah jadi 1.234.567', bind.display.value === '1.234.567', String(bind.display.value));
    cek('yang dipancarkan tetap ANGKA, bukan teks berformat',
        dipancar.length === 1 && dipancar[0][0] === 'update:modelValue' && dipancar[0][1] === 1234567,
        JSON.stringify(dipancar));

    // Mengetik ulang di atas teks yang SUDAH berformat tidak boleh menggandakan nilainya.
    bind.onInput({ target: { value: '1.234.5678' } });
    cek('mengetik di atas teks berformat tidak menggandakan nilai', dipancar[1][1] === 12345678, String(dipancar[1][1]));
    bind.onInput({ target: { value: '' } });
    cek('dikosongkan menjadi 0, bukan NaN', dipancar[2][1] === 0, String(dipancar[2][1]));
    bind.onInput({ target: { value: '-500' } });
    cek('nilai negatif ditolak (sama seperti min=0 sebelumnya)', dipancar[3][1] === 500, String(dipancar[3][1]));

    console.log('\n=== 6. Nilai ditulis SEBELUM sisi Debit/Kredit dihitung ===');

    // applyAmount membaca l.nilai. Kalau urutannya terbalik, Debit/Kredit memakai angka LAMA —
    // jurnalnya tetap balance pada angka yang salah, jadi tidak ada galat yang memberi tahu.
    const ph = /@update:model-value="\(v\) => \{([\s\S]*?)\}"/.exec(srcForm);
    cek('pengolah perubahan nilai ditemukan', !!ph, 'binding @update:model-value tidak ada');

    const jejak = [];
    const jalankan = new Function('v', 'l', 'applyAmount', ph[1]);
    const baris = { nilai: 25, side: 'debit', debit: 25, credit: 0 };
    jalankan(900000, baris, (l) => jejak.push(Number(l.nilai)));

    cek('applyAmount menerima nilai yang BARU', jejak.length === 1 && jejak[0] === 900000,
        'applyAmount dipanggil sebelum nilainya ditulis — Debit/Kredit memakai angka lama: ' + JSON.stringify(jejak));
    cek('nilai barisnya benar-benar tersimpan', baris.nilai === 900000, String(baris.nilai));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
