// Registrasi Invoice Hutang — STRUKTUR JURNAL BAWAAN untuk PPN & PPh.
//
// Empat aturan yang dijaga:
//   1. EXCLUDE PPN    Dr PPN Masukan sebesar PPN-nya; Hutang Usaha (kredit) BERTAMBAH sebesar itu.
//   2. INCLUDE PPN    Dr PPN Masukan sebesar PPN-nya; akun debit Kategori Invoice BERKURANG
//                     sebesar itu — di layar, pengurangannya sudah terjadi pada nilai DPP-nya.
//   3. PPh DIPOTONG   Cr Hutang PPh sebesar PPh-nya; Hutang Usaha (kredit) BERKURANG sebesar itu.
//   4. PPh DITANGGUNG Dr Biaya PPh sebesar PPh-nya; Cr Hutang PPh sebesar itu. Hutang Usaha TETAP.
//
// KENAPA PERLU UJI. Ketiga aturan menyentuh SATU baris yang sama (Hutang Usaha): PPN menambahnya,
// PPh dipotong menguranginya, PPh ditanggung tidak menyentuhnya. Salah satu saja keliru dan
// jurnalnya TETAP BALANCE — hanya angkanya yang salah, dan tidak ada galat apa pun yang memberi
// tahu. Karena itu tiap aturan diperiksa dengan angka mutlak, bukan sekadar "balance".
//
// Jalankan:  node tests/Manual/uji-struktur-jurnal-hutang.cjs .
const fs = require('fs');
const path = require('path');
const esbuild = require('esbuild');

const ROOT = path.resolve(process.argv[2] || '.');
const MODUL = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/strukturJurnal.ts');
const FORM = path.join(ROOT, 'resources/js/Pages/BusinessSupport/Payable/Form.vue');

let lulus = 0;
let gagal = 0;
function cek(nama, ok, ket) {
    if (ok) { lulus++; console.log('  OK    ' + nama); }
    else { gagal++; console.log('  GAGAL ' + nama + (ket ? ' — ' + ket : '')); }
}

// Akun uji — angkanya bebas, yang penting bisa dibedakan satu sama lain.
const AKUN = { beban: 501, hutangUsaha: 201, ppnMasukan: 101, hutangPph: 202, biayaPph: 502 };
const URAIAN = {
    beban: 'Biaya internet', ppn: 'PPN Masukan — Biaya internet',
    hutangUsaha: 'Hutang ABDOEL ROZAK',
    pphDebit: 'Biaya PPh Pasal 23 ditanggung — Biaya internet',
    pphKredit: 'PPh Pasal 23 dipotong — Biaya internet',
};

function bahan(over) {
    return {
        dpp: 0, ppn: 0, pph: 0, ppnAktif: false, pphMode: 'none',
        akun: { ...AKUN }, uraian: { ...URAIAN }, ...over,
    };
}
/** Ambil satu baris menurut akunnya. */
const cari = (baris, akun) => baris.find((b) => b.account_id === akun);
const totalDebit = (baris) => baris.reduce((s, b) => s + (Number(b.debit) || 0), 0);
const totalKredit = (baris) => baris.reduce((s, b) => s + (Number(b.credit) || 0), 0);

(async () => {
    // Modulnya TypeScript — dikompilasi dulu, lalu diuji apa adanya (bukan disalin logikanya).
    const out = path.join(ROOT, 'struktur-jurnal.bundle.tmp.cjs');
    process.on('exit', () => { try { fs.unlinkSync(out); } catch { /* sudah terhapus */ } });
    await esbuild.build({ entryPoints: [MODUL], bundle: true, format: 'cjs', platform: 'node', outfile: out, logLevel: 'silent' });
    delete require.cache[out];
    const { bangunStrukturJurnal, akunKurang, samaDenganBawaan } = require(out);

    console.log('\n=== 0. TANPA PPN & TANPA PPh — dasarnya tidak berubah ===');

    let b = bangunStrukturJurnal(bahan({ dpp: 1000 }));
    cek('dua baris saja', b.length === 2, JSON.stringify(b.map((x) => x.account_id)));
    cek('Dr beban = nilai transaksi', cari(b, AKUN.beban)?.debit === 1000, String(cari(b, AKUN.beban)?.debit));
    cek('Cr hutang usaha = nilai transaksi', cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('tidak ada baris PPN', ! cari(b, AKUN.ppnMasukan));
    cek('balance', totalDebit(b) === totalKredit(b));

    /* NILAI PPN SISA HARUS DIABAIKAN saat modenya dimatikan. Layar tidak mengosongkan angka PPN
       ketika orang berpindah ke "Tanpa PPN" — nilainya tetap tersimpan di formulir. Bila angka
       sisa itu ikut terhitung, Hutang Usaha membengkak tanpa baris PPN yang mengimbanginya. */
    b = bangunStrukturJurnal(bahan({ dpp: 1000, ppn: 110, ppnAktif: false }));
    cek('PPN sisa diabaikan — tetap dua baris', b.length === 2, JSON.stringify(b.map((x) => x.account_id)));
    cek('Cr Hutang Usaha tetap 1.000, bukan 1.110',
        cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));

    /* Begitu pula PPh sisa saat modenya "Tanpa PPh". */
    b = bangunStrukturJurnal(bahan({ dpp: 1000, pph: 20, pphMode: 'none' }));
    cek('PPh sisa diabaikan — tetap dua baris', b.length === 2, JSON.stringify(b.map((x) => x.account_id)));
    cek('Cr Hutang Usaha tetap 1.000, tidak dipotong PPh sisa',
        cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));

    console.log('\n=== 1. EXCLUDE PPN — PPN menambah Hutang Usaha ===');

    // Nilai transaksi 1.000 · PPN 11% = 110 → dibayar 1.110 (persis contoh di layar).
    b = bangunStrukturJurnal(bahan({ dpp: 1000, ppn: 110, ppnAktif: true }));
    cek('tiga baris', b.length === 3, JSON.stringify(b.map((x) => x.account_id)));
    cek('ada baris PPN Masukan di posisi DEBIT', cari(b, AKUN.ppnMasukan)?.side === 'debit');
    cek('nilainya = PPN dari Input Transaksi', cari(b, AKUN.ppnMasukan)?.debit === 110, String(cari(b, AKUN.ppnMasukan)?.debit));
    cek('Dr beban TETAP sebesar nilai transaksi (tidak dikurangi)',
        cari(b, AKUN.beban)?.debit === 1000, String(cari(b, AKUN.beban)?.debit));
    cek('Cr Hutang Usaha BERTAMBAH sebesar PPN → 1.110',
        cari(b, AKUN.hutangUsaha)?.credit === 1110, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));
    cek('uraian baris PPN terbawa', cari(b, AKUN.ppnMasukan)?.description === URAIAN.ppn);

    console.log('\n=== 2. INCLUDE PPN — beban berkurang, hutang usaha tetap senilai transaksinya ===');

    /* Include: nilai transaksi 1.000 sudah termasuk PPN → DPP 901, PPN 99.
       Layar membagi nilainya lebih dulu, jadi `dpp` yang masuk ke sini memang sudah bersih —
       "kurangkan akun posisi debit untuk balancing" terjadi pada angka itu. */
    b = bangunStrukturJurnal(bahan({ dpp: 901, ppn: 99, ppnAktif: true }));
    cek('Dr beban BERKURANG sebesar PPN → 901', cari(b, AKUN.beban)?.debit === 901, String(cari(b, AKUN.beban)?.debit));
    cek('Dr PPN Masukan = 99', cari(b, AKUN.ppnMasukan)?.debit === 99, String(cari(b, AKUN.ppnMasukan)?.debit));
    cek('Cr Hutang Usaha = nilai transaksinya, 1.000',
        cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('beban + PPN = hutang usaha',
        cari(b, AKUN.beban).debit + cari(b, AKUN.ppnMasukan).debit === cari(b, AKUN.hutangUsaha).credit);
    cek('balance', totalDebit(b) === totalKredit(b));

    console.log('\n=== 3. PPh DIPOTONG — Hutang PPh bertambah, Hutang Usaha berkurang ===');

    // Include PPN 901/99 + PPh 23 2% dari 901 = 18 → dibayar 982 (persis contoh di layar).
    b = bangunStrukturJurnal(bahan({ dpp: 901, ppn: 99, ppnAktif: true, pph: 18, pphMode: 'withheld' }));
    cek('empat baris', b.length === 4, JSON.stringify(b.map((x) => x.account_id)));
    cek('ada baris Hutang PPh di posisi KREDIT', cari(b, AKUN.hutangPph)?.side === 'credit');
    cek('nilainya = PPh dari Input Transaksi', cari(b, AKUN.hutangPph)?.credit === 18, String(cari(b, AKUN.hutangPph)?.credit));
    cek('Cr Hutang Usaha BERKURANG sebesar PPh → 982',
        cari(b, AKUN.hutangUsaha)?.credit === 982, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('TIDAK ada baris Biaya PPh — ia hanya untuk PPh ditanggung', ! cari(b, AKUN.biayaPph));
    cek('balance', totalDebit(b) === totalKredit(b));
    cek('uraian baris PPh terbawa', cari(b, AKUN.hutangPph)?.description === URAIAN.pphKredit);

    console.log('\n=== 4. PPh DITANGGUNG — Biaya PPh & Hutang PPh, Hutang Usaha TIDAK berubah ===');

    // Include PPN 901/99, PPh ditanggung 18 → supplier tetap dibayar 1.000 penuh.
    b = bangunStrukturJurnal(bahan({ dpp: 901, ppn: 99, ppnAktif: true, pph: 18, pphMode: 'borne' }));
    cek('lima baris', b.length === 5, JSON.stringify(b.map((x) => x.account_id)));
    cek('ada baris Biaya PPh di posisi DEBIT', cari(b, AKUN.biayaPph)?.side === 'debit');
    cek('nilainya = PPh dari Input Transaksi', cari(b, AKUN.biayaPph)?.debit === 18, String(cari(b, AKUN.biayaPph)?.debit));
    cek('lawannya Hutang PPh di posisi KREDIT sebesar itu juga',
        cari(b, AKUN.hutangPph)?.credit === 18, String(cari(b, AKUN.hutangPph)?.credit));
    cek('Cr Hutang Usaha TIDAK berubah — supplier tetap dibayar penuh 1.000',
        cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));

    // PPh ditanggung TANPA PPN — Hutang Usaha tetap sebesar DPP-nya.
    b = bangunStrukturJurnal(bahan({ dpp: 1000, pph: 20, pphMode: 'borne' }));
    cek('tanpa PPN pun, Hutang Usaha tetap sebesar nilai transaksinya',
        cari(b, AKUN.hutangUsaha)?.credit === 1000, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));

    console.log('\n=== 5. EXCLUDE PPN + PPh DIPOTONG — ketiganya bertemu pada satu baris ===');

    // 1.000 + 110 PPN − 20 PPh = 1.090. Di sinilah salah tanda paling mudah lolos.
    b = bangunStrukturJurnal(bahan({ dpp: 1000, ppn: 110, ppnAktif: true, pph: 20, pphMode: 'withheld' }));
    cek('Cr Hutang Usaha = DPP + PPN − PPh = 1.090',
        cari(b, AKUN.hutangUsaha)?.credit === 1090, String(cari(b, AKUN.hutangUsaha)?.credit));
    cek('balance', totalDebit(b) === totalKredit(b));

    console.log('\n=== 6. AKUN YANG BELUM ADA: BARISNYA TIDAK DIKARANG, TETAPI DISEBUTKAN ===');

    b = bangunStrukturJurnal(bahan({ dpp: 1000, ppn: 110, ppnAktif: true, akun: { ...AKUN, ppnMasukan: null } }));
    cek('baris PPN tidak dibuat tanpa akun', ! b.some((x) => x.account_id === null), JSON.stringify(b));
    cek('dan ketiadaannya dilaporkan',
        akunKurang(bahan({ dpp: 1000, ppn: 110, ppnAktif: true, akun: { ...AKUN, ppnMasukan: null } })).includes('PPN Masukan'));
    cek('Hutang PPh yang belum dipilih juga dilaporkan',
        akunKurang(bahan({ dpp: 1000, pph: 20, pphMode: 'withheld', akun: { ...AKUN, hutangPph: null } })).includes('Hutang PPh'));
    cek('Biaya PPh yang belum ada dilaporkan pada mode ditanggung',
        akunKurang(bahan({ dpp: 1000, pph: 20, pphMode: 'borne', akun: { ...AKUN, biayaPph: null } })).includes('Biaya PPh'));
    cek('tidak ada laporan palsu saat akunnya lengkap',
        akunKurang(bahan({ dpp: 1000, ppn: 110, ppnAktif: true, pph: 20, pphMode: 'borne' })).length === 0);
    cek('PPN bernilai 0 tidak dianggap kurang akun',
        akunKurang(bahan({ dpp: 1000, ppn: 0, ppnAktif: true, akun: { ...AKUN, ppnMasukan: null } })).length === 0);

    console.log('\n=== 7. PEMBANDING "MASIH ASLI ATAU SUDAH DISUNTING" ===');

    const asli = bangunStrukturJurnal(bahan({ dpp: 1000, ppn: 110, ppnAktif: true }));
    cek('struktur yang belum disentuh dikenali sebagai bawaan',
        samaDenganBawaan(asli.map((x) => ({ ...x })), asli));
    const disunting = asli.map((x) => ({ ...x }));
    disunting[0].nilai = 999;
    cek('nilai yang diubah tangan dikenali', ! samaDenganBawaan(disunting, asli));
    const ditambah = [...asli.map((x) => ({ ...x })), { account_id: 999, side: 'debit', nilai: 1, description: '', cost_center_id: null }];
    cek('baris tambahan dikenali', ! samaDenganBawaan(ditambah, asli));
    const gantiAkun = asli.map((x) => ({ ...x }));
    gantiAkun[0].account_id = 777;
    cek('akun yang diganti tangan dikenali', ! samaDenganBawaan(gantiAkun, asli));
    const gantiCc = asli.map((x) => ({ ...x }));
    gantiCc[0].cost_center_id = 5;
    cek('cost center yang sudah diisi dikenali sebagai suntingan',
        ! samaDenganBawaan(gantiCc, asli), 'kalau tidak, cost center yang sudah dipilih akan lenyap saat PPN diubah');

    console.log('\n=== 8. FORM MEMAKAI ATURAN INI, DAN MENYUSUN ULANG SAAT PPN/PPh BERUBAH ===');

    const src = fs.readFileSync(FORM, 'utf8');
    cek('Form.vue memakai modul aturannya, bukan salinan logika',
        src.includes("from './strukturJurnal'") && src.includes('bangunStrukturJurnal(bahanStruktur.value)'));

    /* Inilah yang dulu tidak ada: struktur hanya dibentuk sekali saat pertama masuk langkah
       Struktur. Yang kembali menyalakan PPN lalu maju lagi menemukan struktur lamanya utuh —
       tanpa baris PPN, dan tetap balance, sehingga tidak ada yang memberi tahu. */
    const badanWatch = (src.match(/watch\(bahanStruktur,[\s\S]*?\{ deep: true \}\);/) || [''])[0];
    cek('ada pengamat yang menyusun ulang saat bahannya berubah', badanWatch !== '');
    cek('yang belum pernah dibentuk tidak ikut disusun', badanWatch.includes('form.journal_lines.length === 0'), badanWatch);
    cek('yang sudah disunting tangan TIDAK ditimpa',
        badanWatch.includes('samaDenganBawaan') && badanWatch.includes('return'), badanWatch);
    cek('pengamatnya mendalam (deep) — perubahan di dalam objek bahan ikut terbaca',
        badanWatch.includes('{ deep: true }'), badanWatch);

    const template = ((src.match(/<template>[\s\S]*<\/template>/) || [''])[0]).replace(/<!--[\s\S]*?-->/g, '');
    /* Diperiksa pada GERBANG v-if-nya, bukan sekadar "namanya disebut di suatu tempat": isi
       paragrafnya juga menyebut nama yang sama, jadi mematikan gerbangnya akan tetap lulus.

       Dulu yang dicari kata "Hitung ulang" — nama tombol yang kini dihilangkan. Peringatan tanpa
       jalan keluar sama tak bergunanya dengan tidak ada peringatan sama sekali, jadi yang dicari
       sekarang langkah penggantinya: hapus semua baris, lalu Sebelumnya → Berikutnya. */
    const pUsang = (template.match(/<p v-if="strukturUsang"[\s\S]*?<\/p>/) || [''])[0];
    cek('layar memberitahu bila strukturnya sudah tidak mengikuti PPN/PPh', pUsang !== '', 'penolakan diam-diam');
    cek('peringatannya menyebutkan cara menyusun ulang',
        /Sebelumnya/.test(pUsang) && /Berikutnya/.test(pUsang),
        'peringatan tanpa jalan keluar: ' + pUsang.slice(0, 160));
    cek('tidak lagi menyuruh menekan tombol yang sudah tidak ada',
        ! /Hitung ulang/.test(template), 'petunjuk menunjuk tombol yang tidak ada');
    cek('layar menyebutkan akun yang belum tersedia',
        /<p v-if="akunStrukturKurang\.length"/.test(template),
        'baris yang hilang akan terlihat seperti memang tidak ada');
    cek('isi peringatannya benar-benar menyebut akun mana yang kurang',
        template.includes("akunStrukturKurang.join(' & ')"), template.slice(0, 100));

    console.log('\n────────────────────────────────');
    console.log('LULUS: ' + lulus + '   GAGAL: ' + gagal);
    console.log(gagal === 0 ? 'SEMUA UJI LULUS' : 'ADA YANG GAGAL');
    process.exitCode = gagal === 0 ? 0 : 1;
})();
