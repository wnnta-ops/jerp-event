/**
 * STRUKTUR JURNAL BAWAAN Registrasi Invoice Hutang — PPN & PPh.
 *
 * Diangkat keluar dari Form.vue supaya aturannya bisa diuji apa adanya, tanpa perlu menjalankan
 * seluruh wizard-nya. Empat aturannya:
 *
 *   1. EXCLUDE PPN  — Dr PPN Masukan sebesar PPN-nya, dan Hutang Usaha (kredit) BERTAMBAH
 *                     sebesar PPN itu untuk menyeimbangkan.
 *                     Dr Beban DPP · Dr PPN Masukan PPN · Cr Hutang Usaha (DPP + PPN)
 *
 *   2. INCLUDE PPN  — Dr PPN Masukan sebesar PPN-nya, dan akun debit dari Kategori Invoice
 *                     BERKURANG sebesar PPN itu untuk menyeimbangkan.
 *                     Dr Beban (Nilai − PPN) · Dr PPN Masukan PPN · Cr Hutang Usaha Nilai
 *                     Di layar, pengurangan itu sudah terjadi pada nilai DPP-nya sendiri:
 *                     memilih Include membagi Nilai Transaksi dengan (1 + tarif), sehingga `dpp`
 *                     yang masuk ke sini memang sudah bersih dari PPN. Yang membedakan Include
 *                     dari Exclude karena itu bukan rumus di sini, melainkan besaran `dpp`-nya.
 *
 *   3. PPh DIPOTONG — Cr Hutang PPh sebesar PPh-nya, dan Hutang Usaha (kredit) BERKURANG
 *                     sebesar PPh itu: supplier dibayar setelah dipotong.
 *
 *   4. PPh DITANGGUNG — Dr Biaya PPh sebesar PPh-nya, dan Cr Hutang PPh sebesar itu juga.
 *                     Hutang Usaha TIDAK berubah — supplier tetap dibayar penuh, PPh-nya
 *                     menjadi beban perusahaan.
 *
 * Akun yang belum ditentukan (id kosong) barisnya TIDAK dibuat, bukan dibuat tanpa akun: baris
 * jurnal tanpa akun tidak bisa diposting, dan menyisipkannya hanya memindahkan kegagalannya ke
 * langkah terakhir. Ketiadaannya justru harus terbaca — lihat akunKurang().
 */

export type SisiJurnal = 'debit' | 'credit';

export type BarisStruktur = {
    account_id: number | null;
    cost_center_id: number | null;
    currency: string;
    kurs: number;
    description: string;
    nilai: number;
    side: SisiJurnal;
    debit: number;
    credit: number;
};

export type ModePph = 'none' | 'withheld' | 'borne';

export type MasukanStruktur = {
    /** Nilai yang menjadi dasar beban. Pada Include PPN, ia SUDAH bersih dari PPN. */
    dpp: number;
    ppn: number;
    pph: number;
    ppnAktif: boolean;
    pphMode: ModePph;
    akun: {
        beban: number | null;
        hutangUsaha: number | null;
        ppnMasukan: number | null;
        hutangPph: number | null;
        biayaPph: number | null;
    };
    uraian: {
        beban: string;
        ppn: string;
        hutangUsaha: string;
        pphDebit: string;
        pphKredit: string;
    };
};

const angka = (v: unknown): number => Math.round(Number(v) || 0);

export function barisStruktur(
    account_id: number | null,
    description: string,
    amount: number,
    side: SisiJurnal,
): BarisStruktur {
    const v = Number(amount) || 0;

    return {
        account_id: account_id ?? null,
        cost_center_id: null,
        currency: 'IDR',
        kurs: 1,
        description: description || '',
        nilai: v,
        side,
        debit: side === 'debit' ? v : 0,
        credit: side === 'credit' ? v : 0,
    };
}

/**
 * NILAI HUTANG USAHA — satu tempat, supaya ketiga aturan yang menyentuhnya tidak saling
 * bertentangan: PPN menambahnya, PPh dipotong menguranginya, PPh ditanggung tidak menyentuhnya.
 */
export function nilaiHutangUsaha(m: Pick<MasukanStruktur, 'dpp' | 'ppn' | 'pph' | 'ppnAktif' | 'pphMode'>): number {
    const dpp = angka(m.dpp);
    const ppn = m.ppnAktif ? angka(m.ppn) : 0;
    const potong = m.pphMode === 'withheld' ? angka(m.pph) : 0;

    return dpp + ppn - potong;
}

/** Bangun seluruh baris struktur jurnal bawaan. */
export function bangunStrukturJurnal(m: MasukanStruktur): BarisStruktur[] {
    const dpp = angka(m.dpp);
    const ppn = m.ppnAktif ? angka(m.ppn) : 0;
    const pph = m.pphMode === 'none' ? 0 : angka(m.pph);
    const out: BarisStruktur[] = [];

    // 1. Beban dari Kategori Invoice. Pada Include PPN nilainya sudah berkurang sebesar PPN.
    if (m.akun.beban) out.push(barisStruktur(m.akun.beban, m.uraian.beban, dpp, 'debit'));

    // 2. PPN Masukan — aturan 1 & 2.
    if (ppn > 0 && m.akun.ppnMasukan) out.push(barisStruktur(m.akun.ppnMasukan, m.uraian.ppn, ppn, 'debit'));

    // 3. Biaya PPh — aturan 4 (hanya PPh ditanggung).
    if (m.pphMode === 'borne' && pph > 0 && m.akun.biayaPph) {
        out.push(barisStruktur(m.akun.biayaPph, m.uraian.pphDebit, pph, 'debit'));
    }

    // 4. Hutang Usaha Supplier — penyeimbang aturan 1 & 3.
    if (m.akun.hutangUsaha) {
        out.push(barisStruktur(m.akun.hutangUsaha, m.uraian.hutangUsaha, nilaiHutangUsaha(m), 'credit'));
    }

    // 5. Hutang PPh — aturan 3 (lawan potongan) & aturan 4 (lawan Biaya PPh).
    if (m.pphMode !== 'none' && pph > 0 && m.akun.hutangPph) {
        out.push(barisStruktur(m.akun.hutangPph, m.uraian.pphKredit, pph, 'credit'));
    }

    return out;
}

/**
 * Akun BEBAN untuk PPh DITANGGUNG, mengikuti JENIS PPh yang dipilih.
 *
 * Dr Biaya PPh 21 · Biaya PPh 23 · Biaya PPh Pasal 4(2) — satu jenis PPh menentukan sepasang
 * akun sekaligus bersama Hutang PPh-nya. Sebelumnya ketiganya bermuara ke satu akun beban umum,
 * sehingga beban PPh 21, 23, dan 4(2) tidak bisa dipisahkan lagi di buku besar maupun SPT.
 *
 * Diletakkan di modul ini, bukan di dalam komponennya, karena inilah satu-satunya keputusan
 * pada rantai tersebut yang bisa salah TANPA memunculkan galat apa pun: akun yang keliru tetap
 * membentuk baris, jurnalnya tetap balance, dan angkanya tetap benar.
 *
 * `cadangan` dipakai bila jenis PPh belum dipilih padahal tarifnya sudah diisi manual — keadaan
 * yang masih mungkin karena jenis PPh tidak diwajibkan gerbang mana pun. Tanpa cadangan itu
 * barisnya tidak terbentuk (lihat akunKurang) dan strukturnya tidak balance.
 */
export function akunBiayaPphDitanggung(
    jenisPph: string | null | undefined,
    perJenis: Record<string, number | null> | null | undefined,
    cadangan: number | null | undefined,
): number | null {
    // ?? bukan ||: id akun tidak pernah 0, tetapi menulis || membuat nilai 0 yang terlanjur
    // masuk dari server diam-diam jatuh ke cadangan alih-alih terbaca sebagai akun tak dikenal.
    return (perJenis?.[String(jenisPph ?? '')] ?? cadangan) ?? null;
}

/**
 * Akun yang DIBUTUHKAN aturan di atas tetapi belum tersedia.
 *
 * Barisnya sengaja tidak dibuat tanpa akun, dan justru karena itu ketiadaannya harus disebutkan:
 * tanpa peringatan, struktur yang kekurangan baris PPN hanya terlihat seperti struktur yang
 * memang tidak ber-PPN — jurnalnya tetap balance, dan selisihnya baru ketahuan saat rekonsiliasi.
 */
export function akunKurang(m: MasukanStruktur): string[] {
    const kurang: string[] = [];
    if (m.ppnAktif && angka(m.ppn) > 0 && ! m.akun.ppnMasukan) kurang.push('PPN Masukan');
    if (m.pphMode !== 'none' && angka(m.pph) > 0 && ! m.akun.hutangPph) kurang.push('Hutang PPh');
    if (m.pphMode === 'borne' && angka(m.pph) > 0 && ! m.akun.biayaPph) kurang.push('Biaya PPh');

    return kurang;
}

/**
 * Apakah struktur di layar masih SAMA PERSIS dengan bawaannya?
 *
 * Dipakai untuk memutuskan boleh-tidaknya struktur disusun ulang saat PPN/PPh diubah. Struktur
 * yang belum disentuh siapa pun boleh diperbarui diam-diam — memang itu yang diharapkan. Yang
 * sudah disunting tangan TIDAK boleh ditimpa: pekerjaan orang tidak boleh lenyap hanya karena
 * ia kembali ke langkah sebelumnya untuk membetulkan satu angka.
 */
export function samaDenganBawaan(sekarang: BarisStruktur[], bawaan: BarisStruktur[]): boolean {
    if (! Array.isArray(sekarang) || sekarang.length !== bawaan.length) return false;

    return sekarang.every((b, i) => {
        const d = bawaan[i];

        return String(b.account_id ?? '') === String(d.account_id ?? '')
            && b.side === d.side
            && Math.round(Number(b.nilai) || 0) === Math.round(Number(d.nilai) || 0)
            && String(b.description ?? '') === String(d.description ?? '')
            && String(b.cost_center_id ?? '') === String(d.cost_center_id ?? '');
    });
}
