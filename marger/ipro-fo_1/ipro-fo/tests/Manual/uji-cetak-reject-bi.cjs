// Lembar cetak "Berita Acara Reject BI Checking" — RejectBiCheckingCetak.vue di-render SUNGGUHAN
// lewat SSR, lalu hasilnya diperiksa.
//
// Yang dijaga uji ini ada empat, dan ketiga yang pertama adalah hal yang kalau salah TIDAK
// menimbulkan galat apa pun — lembarnya tetap keluar dari printer, hanya isinya berbohong:
//
//  1. JENIS REJECT MENENTUKAN KALIMAT DAN WARNANYA. 'batal_sepihak' hanya mungkin terjadi bila
//     hasil BI JUSTRU BERSIH (gerbang di KprInitiationController::reject). Mencetaknya merah
//     bertuliskan "ditolak karena Kol 2-5" menuduh customer punya riwayat kredit bermasalah,
//     padahal bersih — dan lembar itu masuk ke berkasnya.
//  2. SKALA KOLEKTIBILITAS MENANDAI LANGKAH YANG BENAR. Penanda yang meleset satu langkah
//     mengubah Kol 2 menjadi Kol 3 tanpa satu pun tanda bahwa ada yang salah.
//  3. YANG TIDAK TERCATAT HARUS DIKATAKAN, BUKAN DIDIAMKAN. result boleh kosong (gerbang server
//     bisa dilewati oleh hasil pasangan saja), dan hasil di luar enam kolektibilitas baku masih
//     ada pada data lama. Keduanya tidak boleh hilang diam-diam dari skala.
//  4. ATURAN CETAKNYA ADA. Tanpa print-color-adjust, peramban membuang SELURUH warna latar saat
//     mencetak — justru warna latarlah yang membedakan kedua jenis reject dan menandai langkah
//     mana yang menjadi hasil pemohon. Tanpa @page A4, ukurannya ikut bawaan peramban.
//
// Jalankan:  node tests/Manual/uji-cetak-reject-bi.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');
const sfc = require('vue/compiler-sfc');
const { createSSRApp, h } = require('vue');
const { renderToString } = require('vue/server-renderer');

const ROOT = path.resolve(process.argv[2] || '.');
const BERKAS = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RejectBiCheckingCetak.vue');
const DETAIL = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RejectBiCheckingDetail.vue');
const DAFTAR = path.join(ROOT, 'resources/js/Pages/BankLoan/Database/RejectBiChecking.vue');

const plugin = {
    name: 'stub',
    setup(build) {
        build.onResolve({ filter: /^@\// }, (a) => ({ path: path.join(ROOT, 'resources/js', a.path.slice(2)) }));
        build.onResolve({ filter: /^(@inertiajs\/vue3|lucide-vue-next)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
        build.onLoad({ filter: /.*/, namespace: 'stub' }, (a) => ({
            contents: a.path === '@inertiajs/vue3'
                ? "import { h } from 'vue';"
                  + ' export const router = { post(){}, get(){} };'
                  + " export const Link = { setup: (p, { slots }) => () => h('a', {}, slots.default ? slots.default() : []) };"
                  + ' export const Head = { setup: () => () => null };'
                : "import { h } from 'vue';" + ['ArrowLeft', 'Printer']
                    .map((n) => ` export const ${n} = { setup: () => () => h('span') };`).join(''),
            loader: 'js', resolveDir: ROOT,
        }));
        build.onLoad({ filter: /PrintLayout\.vue$/ }, () => ({
            contents: "import { h } from 'vue';"
                + " export default { setup: (p, { slots }) => () => h('div', {}, slots.default ? slots.default() : []) };",
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

// ── Data contoh ──────────────────────────────────────────────────────────────────────────────
function dasar(ubah) {
    const p = {
        init: {
            id: 7, number: 'INIT-2026-00042', customer: 'Budi Santoso', phone: '0812-3456-7890',
            tts: 'TTS-2026-0099', tts_amount: 5000000, tts_date: '2026-03-04',
            unit: 'A3-12', booking: 'BK-2026-0501', plafon: 425000000,
            created_at: '2026-03-05T02:10:00+00:00',
        },
        sprs: {
            marital_status: 'sudah_menikah', perlu_pasangan: true, at: '2026-03-08T04:00:00+00:00',
            dokumen: [
                { label: 'KTP Pemohon', ok: true, at: '2026-03-06T03:00:00+00:00' },
                { label: 'NPWP Pemohon', ok: true, at: '2026-03-06T03:05:00+00:00' },
                { label: 'KTP Pasangan', ok: true, at: '2026-03-07T03:00:00+00:00' },
                { label: 'NPWP Pasangan', ok: true, at: '2026-03-07T03:02:00+00:00' },
            ],
            ocr: 'match',
        },
        bi: {
            jenis: 'bi_checking', result: 'kol3', result_pasangan: 'kol2', permintaan: 'mundur',
            notes: 'Tunggakan kartu kredit 4 bulan.', reason: 'Kolektibilitas 3 pada pemohon.',
            rejected_at: '2026-03-10T07:30:00+00:00', rejected_by: 'Sari Dewi',
            pernah_lanjut: false, lanjut_at: null,
        },
        company: { name: 'PT. MITRA USAHA PROPERTINDO', address: 'Ruko Avenue No. 27-30, Sidoarjo' },
        cetak: { nomor: 'BA-BI/INIT-2026-00042', oleh: 'Sari Dewi', pada: 'Senin, 15 September 2026 pukul 10:00', kota: 'Sidoarjo', hari: '15 September 2026' },
        signers: {
            left: { label: 'Dibuat oleh,', name: 'Sari Dewi', title: 'Bank Loan' },
            right: { label: 'Mengetahui,', name: 'Rachmat Suhendra', title: 'Direktur' },
        },
    };

    return ubah ? ubah(JSON.parse(JSON.stringify(p))) : p;
}

/**
 * Pecah wilayah skala menjadi satu entri per langkah.
 * Tidak memakai satu regex besar: blok langkah punya div bersarang, dan regex yang berhenti di
 * </div> pertama akan memotong penanda "Pemohon/Pasangan" — bagian yang justru diuji.
 */
function bacaSkala(html) {
    const mulai = html.indexOf('rbi-langkah');
    if (mulai < 0) return [];
    const wilayah = html.slice(mulai);
    const potongan = wilayah.split('<div class="rbi-lk ').slice(1);

    return potongan.map((c) => {
        const kelas = c.slice(0, c.indexOf('"'));
        const chip = /rbi-lk-chip">([^<]*)</.exec(c);
        const pin = [...c.matchAll(/rbi-pin">([^<]*)</g)].map((m) => m[1].replace(/▲/g, '').trim());

        return { kelas: 'rbi-lk ' + kelas, label: chip ? chip[1].trim() : '', pin };
    });
}

async function render(bundel, props) {
    const app = createSSRApp({ render: () => h(bundel.default, props) });
    app.config.globalProperties.route = (nama, id) => '/' + nama + '/' + id;

    return await renderToString(app);
}

const teksDari = (html) => html.replace(/<[^>]+>/g, ' ').replace(/&#39;/g, "'").replace(/&amp;/g, '&')
    .replace(/&middot;/g, '·').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ');

async function main() {
    const hasil = await esbuild.build({
        entryPoints: [BERKAS], bundle: true, write: false, format: 'cjs',
        platform: 'node', external: ['vue'], plugins: [plugin],
        define: { __VUE_OPTIONS_API__: 'true', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
    });
    const modul = { exports: {} };
    new Function('module', 'exports', 'require', hasil.outputFiles[0].text)(modul, modul.exports, require);

    const sumber = fs.readFileSync(BERKAS, 'utf8');

    // ══ 1. Reject BI Checking (hasil bermasalah) ══
    console.log('\n=== 1. Reject BI Checking — hasil Kol 2-5 ===');

    const htmlBi = await render(modul.exports, dasar());
    const teksBi = teksDari(htmlBi);

    cek('judul berita acara tercetak', teksBi.includes('BERITA ACARA REJECT BI CHECKING'));
    cek('vonis diwarnai merah (v-red)', htmlBi.includes('v-red'), 'kelas v-red tidak ada');
    cek('vonis TIDAK diwarnai kuning', !htmlBi.includes('v-amber'), 'v-amber ikut muncul');
    cek('kepala vonis berbunyi REJECT BI CHECKING', teksBi.includes('REJECT BI CHECKING'));
    cek('alasan penolakan tercetak', teksBi.includes('Kolektibilitas 3 pada pemohon.'));
    cek('catatan petugas tercetak', teksBi.includes('Tunggakan kartu kredit 4 bulan.'));
    cek('penolak & waktunya tercetak', teksBi.includes('Sari Dewi'), 'nama penolak hilang');
    cek('kalimat vonis menyebut Kol 2 sampai Kol 5', teksBi.includes('Kol 2') && teksBi.includes('Kol 5'));
    cek('TIDAK mengklaim hasilnya bersih', !teksBi.includes('BERSIH'), 'kalimat batal sepihak bocor ke reject BI');

    console.log('\n=== 2. Skala kolektibilitas menandai langkah yang tepat ===');

    const skalaBi = bacaSkala(htmlBi);
    cek('enam langkah kolektibilitas ter-render', skalaBi.length === 6, 'ada ' + skalaBi.length);
    cek('urutannya Clear..Kol 5', skalaBi.map((s) => s.label).join(',') === 'Clear,Kol 1,Kol 2,Kol 3,Kol 4,Kol 5',
        skalaBi.map((s) => s.label).join(','));

    const lk = (label) => skalaBi.find((s) => s.label === label) || { kelas: '', pin: [] };
    cek('Clear & Kol 1 berada di zona bersih (lk-ok)',
        lk('Clear').kelas.includes('lk-ok') && lk('Kol 1').kelas.includes('lk-ok'),
        lk('Clear').kelas + ' | ' + lk('Kol 1').kelas);
    cek('Kol 2..Kol 5 berada di zona bermasalah (lk-bad)',
        ['Kol 2', 'Kol 3', 'Kol 4', 'Kol 5'].every((l) => lk(l).kelas.includes('lk-bad')),
        ['Kol 2', 'Kol 3', 'Kol 4', 'Kol 5'].map((l) => l + '=' + lk(l).kelas).join(' | '));
    cek('penanda Pemohon tepat di Kol 3', lk('Kol 3').pin.includes('Pemohon'), JSON.stringify(lk('Kol 3').pin));
    cek('penanda Pasangan tepat di Kol 2', lk('Kol 2').pin.includes('Pasangan'), JSON.stringify(lk('Kol 2').pin));
    cek('hanya dua langkah yang bertanda', skalaBi.filter((s) => s.pin.length).length === 2,
        String(skalaBi.filter((s) => s.pin.length).length));
    cek('hanya langkah hasil yang disorot penuh (lk-on)',
        skalaBi.filter((s) => s.kelas.includes('lk-on')).map((s) => s.label).join(',') === 'Kol 2,Kol 3',
        skalaBi.filter((s) => s.kelas.includes('lk-on')).map((s) => s.label).join(','));
    cek('langkah bersih TIDAK ikut tersorot',
        !lk('Clear').kelas.includes('lk-on') && !lk('Kol 1').kelas.includes('lk-on'));

    console.log('\n=== 3. Batal Sepihak — hasil BI justru bersih ===');

    const htmlBs = await render(modul.exports, dasar((p) => {
        p.bi.jenis = 'batal_sepihak';
        p.bi.result = 'clear';
        p.bi.result_pasangan = 'kol1';
        p.bi.reason = 'Customer membatalkan, pindah lokasi kerja.';

        return p;
    }));
    const teksBs = teksDari(htmlBs);
    const skalaBs = bacaSkala(htmlBs);
    const lkBs = (label) => skalaBs.find((s) => s.label === label) || { kelas: '', pin: [] };

    cek('vonis diwarnai kuning (v-amber)', htmlBs.includes('v-amber'), 'kelas v-amber tidak ada');
    cek('vonis TIDAK diwarnai merah', !htmlBs.includes('v-red'), 'v-red ikut muncul');
    cek('kepala vonis berbunyi REJECT BATAL SEPIHAK', teksBs.includes('REJECT BATAL SEPIHAK'));
    cek('dinyatakan hasil BI-nya BERSIH', teksBs.includes('BERSIH'), 'tidak dinyatakan bersih');
    cek('dinyatakan customer yang mengundurkan diri', teksBs.includes('mengundurkan diri'));
    cek('TIDAK menuduh riwayat kreditnya bermasalah', !teksBs.includes('di luar batas yang diterima developer'),
        'kalimat reject BI bocor ke batal sepihak');
    cek('kotak alasan ikut kuning (a-amber)', htmlBs.includes('a-amber') && !htmlBs.includes('a-red'));
    cek('penanda jatuh di zona bersih', lkBs('Clear').pin.includes('Pemohon') && lkBs('Kol 1').pin.includes('Pasangan'),
        JSON.stringify([lkBs('Clear').pin, lkBs('Kol 1').pin]));
    cek('sorotan zona bersih memakai lk-ok lk-on',
        lkBs('Clear').kelas.includes('lk-ok') && lkBs('Clear').kelas.includes('lk-on'), lkBs('Clear').kelas);

    console.log('\n=== 4. Pemohon belum menikah — pasangan tidak dipersyaratkan ===');

    const htmlLajang = await render(modul.exports, dasar((p) => {
        p.sprs.marital_status = 'belum_menikah';
        p.sprs.perlu_pasangan = false;
        p.sprs.dokumen = p.sprs.dokumen.slice(0, 2);
        p.bi.result_pasangan = null;

        return p;
    }));
    const teksLajang = teksDari(htmlLajang);
    const skalaLajang = bacaSkala(htmlLajang);

    cek('dokumen pasangan tidak ikut dicetak', !teksLajang.includes('KTP Pasangan') && !teksLajang.includes('NPWP Pasangan'),
        'dokumen pasangan muncul untuk pemohon lajang');
    cek('tidak ada penanda Pasangan di skala', !skalaLajang.some((s) => s.pin.includes('Pasangan')));
    cek('penanda Pemohon tetap ada', skalaLajang.some((s) => s.pin.includes('Pemohon')));
    cek('alasan absennya pasangan dijelaskan', teksLajang.includes('belum menikah'));
    cek('tidak melaporkan hasil pasangan hilang', !teksLajang.includes('pasangan tidak tercatat'),
        'pemohon lajang dilaporkan seolah datanya kurang');

    console.log('\n=== 5. Data yang tidak tercatat dikatakan, bukan didiamkan ===');

    const htmlKosong = await render(modul.exports, dasar((p) => {
        p.bi.result = null;
        p.bi.result_pasangan = null;
        p.bi.reason = null;
        p.bi.notes = null;

        return p;
    }));
    const teksKosong = teksDari(htmlKosong);
    const skalaKosong = bacaSkala(htmlKosong);

    cek('skala tetap enam langkah walau hasilnya kosong', skalaKosong.length === 6);
    cek('tidak ada langkah yang tersorot', !skalaKosong.some((s) => s.kelas.includes('lk-on')));
    cek('hasil kosong dilaporkan', teksKosong.includes('tidak tercatat'), 'diam saja saat hasilnya kosong');
    cek('pemohon DAN pasangan sama-sama disebut',
        /pemohon dan pasangan tidak tercatat/i.test(teksKosong), 'salah satu tidak disebut');
    cek('alasan kosong ditulis apa adanya', teksKosong.includes('tidak dituliskan'));
    cek('blok catatan tidak muncul saat kosong', !htmlKosong.includes('rbi-catatan-l'));

    const htmlLuar = await render(modul.exports, dasar((p) => {
        p.bi.result = 'caution';
        p.bi.result_pasangan = 'kol4';

        return p;
    }));
    const teksLuar = teksDari(htmlLuar);
    const skalaLuar = bacaSkala(htmlLuar);

    cek('hasil di luar skala baku tetap dilaporkan', teksLuar.includes('luar skala kolektibilitas baku'),
        'nilai caution hilang tanpa jejak');
    cek('nilai luar skala disebut dengan label manusianya', teksLuar.includes('Perlu Perhatian'));
    cek('hanya pasangan yang tersorot di skala',
        skalaLuar.filter((s) => s.kelas.includes('lk-on')).map((s) => s.label).join(',') === 'Kol 4',
        skalaLuar.filter((s) => s.kelas.includes('lk-on')).map((s) => s.label).join(','));

    console.log('\n=== 6. Jejak & kelengkapan dokumen ===');

    const htmlLanjut = await render(modul.exports, dasar((p) => {
        p.bi.pernah_lanjut = true;
        p.bi.lanjut_at = '2026-03-09T02:00:00+00:00';

        return p;
    }));
    cek('jejak "pernah lanjut lalu dibatalkan" tercetak',
        teksDari(htmlLanjut).includes('lanjut pemberkasan SPR'), 'jejak pembalikan hilang');
    cek('jejak itu TIDAK muncul bila tidak pernah lanjut',
        !teksBi.includes('lanjut pemberkasan SPR'), 'jejak muncul padahal tidak pernah lanjut');

    const htmlKurang = await render(modul.exports, dasar((p) => {
        p.sprs.dokumen[3].ok = false;
        p.sprs.dokumen[3].at = null;

        return p;
    }));
    const teksKurang = teksDari(htmlKurang);
    cek('SPRS lengkap ditandai Lengkap', teksBi.includes('Lengkap') && !teksBi.includes('Belum lengkap'));
    cek('SPRS kurang satu dokumen ditandai Belum lengkap', teksKurang.includes('Belum lengkap'));
    cek('dokumen yang belum diterima dikatakan', teksKurang.includes('belum diterima'));

    const htmlOcr = await render(modul.exports, dasar((p) => { p.sprs.ocr = 'mismatch'; return p; }));
    cek('OCR sesuai dilaporkan', teksBi.includes('sesuai'));
    cek('OCR tidak sesuai dilaporkan', teksDari(htmlOcr).includes('TIDAK sesuai'));
    const htmlTanpaOcr = await render(modul.exports, dasar((p) => { p.sprs.ocr = null; return p; }));
    cek('tanpa scan, tidak ada klaim kecocokan nama',
        !teksDari(htmlTanpaOcr).includes('Pencocokan nama'), 'mengklaim hasil OCR padahal belum discan');

    console.log('\n=== 7. Identitas & kop ===');

    cek('nama perusahaan di kop', teksBi.includes('PT. MITRA USAHA PROPERTINDO'));
    cek('memakai logo persegi, BUKAN kop A4 penuh',
        sumber.includes('/images/mup-logo.jpeg') && !sumber.includes('mup-letterhead'),
        'mup-letterhead.jpeg dipasang sebagai <img> — seluruh halaman kop termampat jadi sepotong oranye');
    // Ketiganya datang dari prop `cetak`. Halaman ini juga punya fungsi cetak di <script setup>,
    // dan fungsi bernama sama MENUTUPI prop di dalam template — ketiganya ter-render kosong tanpa
    // satu pun galat. Sudah benar-benar terjadi sekali, jadi ketiganya dijaga terpisah.
    cek('nomor dokumen tercetak', teksBi.includes('BA-BI/INIT-2026-00042'), 'prop `cetak` tertutupi fungsi bernama sama?');
    cek('kota tanda tangan tercetak', teksBi.includes('Sidoarjo,'), 'prop cetak.kota kosong');
    cek('tanggal tanda tangan tercetak', teksBi.includes('15 September 2026'), 'prop cetak.hari kosong');
    cek('nama pencetak tercetak', teksBi.includes('pukul 10:00'), 'prop cetak.pada kosong');
    cek('plafon diformat rupiah id-ID', teksBi.includes('Rp 425.000.000'), 'format plafon salah');
    cek('nominal TTS ikut tercetak', teksBi.includes('Rp 5.000.000'), 'nominal TTS tidak dikirim/ditampilkan');
    cek('status pernikahan tercetak', teksBi.includes('Sudah Menikah'));
    cek('blok tanda tangan lengkap', teksBi.includes('Dibuat oleh,') && teksBi.includes('Mengetahui,') && teksBi.includes('Direktur'));
    cek('keterangan waktu cetak ada', teksBi.includes('Dicetak'));

    console.log('\n=== 8. Aturan cetak benar-benar terpasang ===');

    cek('halaman memakai PrintLayout (tanpa sidebar & topbar)',
        /defineOptions\(\{\s*layout:\s*PrintLayout\s*\}\)/.test(sumber), 'chrome aplikasi akan ikut tercetak');
    cek('@page A4 diatur', /@page\s*\{[^}]*size:\s*A4/.test(sumber), 'ukuran kertas ikut bawaan peramban');
    cek('print-color-adjust: exact dipasang', /print-color-adjust:\s*exact/.test(sumber),
        'seluruh warna latar akan dibuang peramban saat mencetak');
    cek('varian -webkit- ikut dipasang', /-webkit-print-color-adjust:\s*exact/.test(sumber));
    cek('bilah aksi disembunyikan saat cetak', /@media print[\s\S]*\.noprint\s*\{[^}]*display:\s*none/.test(sumber));
    // Mencari hex-nya di seluruh berkas TIDAK memeriksa apa pun: kedua warna itu juga dipakai
    // cakram tahap (.d-ok/.d-red), jadi aturan sorotan bisa dipucatkan sambil ujinya tetap hijau.
    // Yang diperiksa isi aturan pada selektornya sendiri.
    const aturan = (selektor) => {
        const i = sumber.indexOf(selektor + ' {');

        return i < 0 ? null : sumber.slice(i + selektor.length + 1, sumber.indexOf('}', i) + 1);
    };
    const sorotOk = aturan('.rbi .lk-ok.lk-on .rbi-lk-chip');
    const sorotBad = aturan('.rbi .lk-bad.lk-on .rbi-lk-chip');
    cek('sorotan zona bersih memakai isian kuat #059669',
        !!sorotOk && /background:\s*#059669/.test(sorotOk),
        'tint pucat hijau vs merah muda beda OKLab hanya 1,3 (deutan) — di kertas praktis sewarna');
    cek('sorotan zona bermasalah memakai isian kuat #dc2626',
        !!sorotBad && /background:\s*#dc2626/.test(sorotBad), sorotBad || 'aturan tidak ada');
    cek('teks di atas isian kuat dibuat putih agar tetap terbaca',
        !!sorotOk && /color:\s*#fff/.test(sorotOk) && !!sorotBad && /color:\s*#fff/.test(sorotBad));
    cek('blok penting dijaga tidak terpotong antar halaman', /break-inside:\s*avoid/.test(sumber));

    console.log('\n=== 9. Tombol Cetak benar-benar mengarah ke lembar ini ===');

    const srcDetail = fs.readFileSync(DETAIL, 'utf8');
    const srcDaftar = fs.readFileSync(DAFTAR, 'utf8');

    // Komentar dibuang dulu: penjelasan KENAPA window.print() ditinggalkan justru menyebut
    // window.print(), dan tanpa pembuangan ini ujinya menuduh komentar sebagai pemanggilan.
    const tanpaKomentar = (s) => s
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');

    cek('halaman detail tidak lagi memanggil window.print()',
        !/window\.print\(\)/.test(tanpaKomentar(srcDetail)), 'masih mencetak layar apa adanya berikut sidebar');
    cek('tombol Cetak di detail menuju rute lembar cetak',
        /reject-bi\.cetak/.test(srcDetail), 'tombol Cetak tidak menunjuk ke mana-mana');
    cek('dibuka di tab baru', /reject-bi\.cetak[\s\S]{0,200}target="_blank"/.test(srcDetail), 'menimpa halaman detail');
    cek('daftar Reject BI Checking punya tombol Cetak per baris', /reject-bi\.cetak/.test(srcDaftar));

    console.log('\n==================================================');
    console.log('  LULUS ' + lulus + ' · GAGAL ' + gagal);
    if (gagal === 0) console.log('  SEMUA UJI LULUS');
    console.log('==================================================');
    process.exit(gagal ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
