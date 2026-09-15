// Registrasi Pengeluaran Kas & Bank ("Transaksi Kas / Bank"): kolom Cost Center di sebelah kolom
// Akun, wajib sebelum tombol Simpan & Buat Jurnal aktif, dan kolom Akun dilebarkan supaya nama
// akun tidak terpotong saat memilih.
//
// Pasangan uji sisi server ada di tests/Manual/uji-cost-center-kas-bank.php — ia yang membuktikan
// cost center benar-benar sampai ke baris jurnal, termasuk lewat jalur approval BKK.
//
// TIGA HAL YANG DIJAGA, semuanya tidak memunculkan galat apa pun kalau salah:
//
//  1. KEWAJIBANNYA BERGANTUNG PADA AKUN. 71 dari 267 akun postable bertanda cost_center_optional
//     — termasuk rekening bank tujuan pada Transfer Antar Rekening. Mewajibkannya tanpa pandang
//     akun akan mengunci mode transfer sepenuhnya; melonggarkannya untuk semua membuat baris
//     jurnal tanpa dimensi lolos ke buku besar, dan itu hanya terlihat sebagai angka yang kurang
//     di Budget Control berbulan kemudian.
//  2. LEBAR KOLOM HARUS BENAR-BENAR BERTAMBAH. Tabelnya tidak punya lebar minimum, sehingga
//     overflow-x-auto pembungkusnya tidak pernah aktif — menambah kolom kelima pada halaman yang
//     lebarnya tetap berarti MEMERAS kolom Akun, persis kebalikan dari yang diminta.
//  3. GERBANG TOMBOLNYA HARUS BENAR-BENAR TERPASANG. Syarat :disabled dijalankan sungguhan,
//     bukan dicocokkan polanya.
//
// Jalankan:  node tests/Manual/uji-cost-center-kas-bank.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/CashBook/Form.vue');

const IKON = ['Banknote', 'Save', 'Plus', 'Trash2', 'ArrowLeft', 'ArrowRight', 'Check', 'Search',
    'ChevronsUpDown', 'X', 'Loader2', 'Copy', 'ClipboardPaste'];

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
                  + ' export const Head = { setup: () => () => null };'
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

const PROPS = {
    types: { in: 'Kas Masuk (BKM)', out: 'Kas Keluar (BKK)', transfer: 'Transfer Antar Rekening' },
    cashAccounts: [{ id: 1, code: '10101010101', name: 'KAS OPERASIONAL', cost_center_optional: true }],
    counterAccounts: [
        { id: 11, code: '51010101', name: 'Beban Perbaikan & Pemeliharaan Bangunan', type: 'expense', cost_center_optional: false },
        { id: 12, code: '20101010101', name: 'UTANG USAHA SUPPLIER', type: 'liability', cost_center_optional: true },
    ],
    costCenters: [{ id: 5, code: 'CC-HO', name: 'Head Office' }],
    defaultType: 'out',
    allowedTypes: ['out'],
};

/** Akhir tag dicari sambil MELEWATI isi tanda kutip — atribut bisa memuat '>'. */
function tagDi(s, awal) {
    let i = awal, kutip = '';
    while (i < s.length) {
        const c = s[i];
        if (kutip) { if (c === kutip) kutip = ''; }
        else if (c === '"' || c === "'") kutip = c;
        else if (c === '>') break;
        i++;
    }

    return s.slice(awal, i);
}

async function main() {
    const src = fs.readFileSync(FORM, 'utf8');
    const kode = src.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');

    const b = await esbuild.build({
        entryPoints: [FORM], bundle: true, write: false, format: 'cjs', platform: 'node',
        external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const m = { exports: {} };
    new Function('module', 'exports', 'require', b.outputFiles[0].text)(m, m.exports, require);

    const app = createSSRApp({ render: () => h(m.exports.default, PROPS) });
    app.config.globalProperties.route = (n, p) => '/' + n + (p === undefined ? '' : '/' + p);
    const html = await renderToString(app);
    const teks = html.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ');

    console.log('\n=== 1. Kolom Cost Center ada, tepat di sebelah kolom Akun ===');

    cek('kepala kolom Cost Center ter-render', teks.includes('Cost Center'), 'kolomnya tidak ada');
    const kepala = [...html.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)]
        .map((x) => x[1].replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim());
    cek('urutannya Akun → Cost Center → Keterangan → Nominal',
        kepala.slice(0, 4).join(' | ') === 'Akun * | Cost Center * | Keterangan Baris | Nominal *',
        JSON.stringify(kepala));
    cek('Cost Center bertanda wajib (*)', kepala.includes('Cost Center *'), JSON.stringify(kepala));
    cek('SearchSelect cost center terikat ke baris', /v-model="line\.cost_center_id"/.test(kode),
        'kolomnya tidak menyimpan ke barisnya');
    cek('pilihannya dari prop costCenters', /:options="costCenters"/.test(kode));

    console.log('\n=== 2. Wajib — kecuali pada akun yang memang tidak menanggungnya ===');

    // ccKurang dijalankan SUNGGUHAN dengan ccOptional tiruan: yang menentukan benar-tidaknya
    // adalah hasilnya untuk tiap keadaan, bukan bentuk tulisannya.
    const mCc = /const ccKurang = \(l: Line\) => ([^;]+);/.exec(kode);
    cek('aturan ccKurang ditemukan', !! mCc);
    const ccKurang = new Function('l', 'ccOptional', 'return (' + mCc[1].replace(/: Line/g, '') + ');');
    const opt = (id) => String(id) === '12'; // hanya akun 12 yang dikecualikan

    cek('akun wajib tanpa cost center → kurang', ccKurang({ account_id: 11, cost_center_id: '' }, opt) === true);
    cek('akun wajib dengan cost center → tidak kurang', ccKurang({ account_id: 11, cost_center_id: 5 }, opt) === false);
    cek('akun DIKECUALIKAN tanpa cost center → tidak kurang',
        ccKurang({ account_id: 12, cost_center_id: '' }, opt) === false,
        'mode Transfer & baris Utang Usaha akan terkunci selamanya');
    cek('baris yang akunnya belum dipilih tidak dianggap kurang',
        ccKurang({ account_id: '', cost_center_id: '' }, opt) === false,
        'baris kosong menahan tombol tanpa sebab yang bisa diperbaiki');
    cek('cost center 0 tidak dianggap terisi', ccKurang({ account_id: 11, cost_center_id: 0 }, opt) === true);

    /* ccOptional YANG SEBENARNYA ikut diuji, bukan hanya yang ditiru di atas: pemeriksaan
       ccKurang memberi ccOptional-nya sendiri, jadi ia buta terhadap ccOptional yang rusak —
       dan ccOptional yang selalu true membuat SELURUH baris dikecualikan tanpa satu pun tanda. */
    const mOpt = /const ccOptional = \(accountId: number \| string\) => \{([\s\S]*?)\n\};/.exec(kode);
    cek('aturan ccOptional ditemukan', !! mOpt);
    const ccOptionalAsli = new Function('accountId', 'props',
        mOpt[1].replace(/: any/g, '').replace(/ as any/g, ''));
    const propsTiru = { counterAccounts: PROPS.counterAccounts, cashAccounts: PROPS.cashAccounts };

    cek('akun beban (cost_center_optional=false) TIDAK dikecualikan',
        ccOptionalAsli(11, propsTiru) === false, 'semua baris jadi dikecualikan — kewajibannya tidak berlaku');
    cek('akun Utang Usaha (cost_center_optional=true) dikecualikan', ccOptionalAsli(12, propsTiru) === true);
    cek('rekening kas ikut terbaca dari daftar cashAccounts (dipakai mode transfer)',
        ccOptionalAsli(1, propsTiru) === true, 'rekening tujuan transfer tidak terbaca → mode transfer terkunci');
    cek('akun yang tidak dikenal tidak diam-diam dikecualikan',
        ccOptionalAsli(999, propsTiru) === false);
    cek('id bertipe teks tetap cocok (payload JSON mengirimnya sebagai teks)',
        ccOptionalAsli('12', propsTiru) === true);

    console.log('\n=== 3. Tombol Simpan terkunci selama masih ada yang kurang ===');

    const mBtn = /<Button type="submit" :disabled="([^"]+)"/.exec(kode);
    cek('tombol Simpan & Buat Jurnal ditemukan', !! mBtn);
    const syarat = mBtn[1];
    cek('syarat matinya menyebut kekurangan cost center', /akunKurangCc/.test(syarat), syarat);

    const mati = (f, total, kurang) => !! new Function('form', 'total', 'akunKurangCc', 'return (' + syarat + ');')(f, total, { length: kurang });
    const F = { processing: false };

    cek('ada yang kurang → tombol MATI', mati(F, 150000, 1) === true);
    cek('tidak ada yang kurang → tombol HIDUP', mati(F, 150000, 0) === false);
    cek('nominal masih 0 → tetap MATI', mati(F, 0, 0) === true);
    cek('sedang menyimpan → tetap MATI', mati({ processing: true }, 150000, 0) === true);

    cek('alasan matinya disebutkan', /Cost center wajib diisi untuk akun/.test(src),
        'tombol nonaktif tanpa keterangan membuat orang menyangka layarnya rusak');
    cek('barisnya ikut ditandai merah', /ccKurang\(line\) \? 'rounded-lg ring-1 ring-danger-400'/.test(kode));
    cek('baris yang dikecualikan diberi tahu boleh kosong', /Boleh dikosongkan/.test(src));

    console.log('\n=== 4. Kolom Akun dilebarkan & tidak lagi terpotong ===');

    cek('pilihan akun per baris tidak lagi dipotong elipsis',
        /<SearchSelect v-model="line\.account_id"[^>]*:no-truncate="true"/.test(kode),
        'nama akun panjang terpotong baik di pemicu maupun di daftar pilihannya');
    cek('halaman dilebarkan ke max-w-6xl', /<div class="max-w-6xl space-y-4">/.test(kode),
        'kolom kelima justru memeras kolom Akun');
    cek('tidak lagi max-w-4xl', ! /max-w-4xl/.test(kode));
    // Tanpa lebar minimum, overflow-x-auto pembungkusnya tidak pernah aktif dan tabel selalu diperas.
    cek('tabel diberi lebar minimum agar gulir mendatar bekerja',
        /<table class="w-full min-w-\[\d+rem\] text-sm">/.test(kode),
        'di layar sempit kolomnya diperas, bukan digulir');

    console.log('\n=== 5. Lebar kolom tetap utuh 100% di kedua mode ===');

    const lebar = (mode) => {
        const out = [];
        let i = kode.indexOf('<thead>');
        const akhir = kode.indexOf('</thead>');
        while (true) {
            i = kode.indexOf('<th', i);
            if (i < 0 || i > akhir) break;
            const tag = tagDi(kode, i);
            i += 3;
            // Kolom yang hanya tampil di mode non-transfer.
            if (mode === 'transfer' && /v-if="!isTransfer"/.test(tag)) continue;
            const tetap = /style="width:(\d+)%"/.exec(tag);
            if (tetap) { out.push(Number(tetap[1])); continue; }
            const dinamis = /isTransfer \? '(\d+)%' : '(\d+)%'/.exec(tag);
            if (dinamis) out.push(Number(mode === 'transfer' ? dinamis[1] : dinamis[2]));
        }

        return out;
    };
    const nonTrf = lebar('non');
    const trf = lebar('transfer');

    cek('mode biasa: lima kolom', nonTrf.length === 5, JSON.stringify(nonTrf));
    cek('mode biasa: jumlahnya 100%', nonTrf.reduce((a, c) => a + c, 0) === 100, String(nonTrf.reduce((a, c) => a + c, 0)));
    cek('mode transfer: tiga kolom', trf.length === 3, JSON.stringify(trf));
    cek('mode transfer: jumlahnya 100%', trf.reduce((a, c) => a + c, 0) === 100, String(trf.reduce((a, c) => a + c, 0)));
    cek('kolom Akun tetap yang paling lebar', Math.max(...nonTrf) === nonTrf[0], JSON.stringify(nonTrf));
    // Kaki tabel harus ikut bergeser, kalau tidak angka Total jatuh di kolom yang salah.
    cek('kaki tabel ikut bergeser satu kolom', /:colspan="isTransfer \? 2 : 3"/.test(kode),
        'angka Total akan jatuh di bawah kolom yang salah');

    console.log('\n=== 6. Setiap baris baru membawa cost_center_id ===');

    cek('tipe Line memuat cost_center_id', /type Line = \{[^}]*cost_center_id/.test(kode));
    cek('baris kosong dibuat lewat satu pabrik (barisKosong)', /const barisKosong = \(\): Line =>/.test(kode),
        'nilai awal ditulis ulang di beberapa tempat — satu yang terlewat berarti barisnya tanpa kolom itu');
    const pabrik = (kode.match(/barisKosong\(\)/g) || []).length;
    cek('pabriknya dipakai di semua tempat pembuatan baris', pabrik >= 3, String(pabrik));
    cek('tidak ada lagi baris literal tanpa cost_center_id',
        ! /\{ account_id: '', amount: 0, description: '' \}/.test(kode),
        'ada tempat yang masih membuat baris tanpa kolom cost center');
    cek('baris lama saat edit ikut membawa cost centernya',
        /e\.lines\.map\(\(l: any\) => \(\{[^}]*cost_center_id: l\.cost_center_id/.test(kode),
        'membuka transaksi lama akan mengosongkan cost centernya diam-diam');

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
