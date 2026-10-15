/**
 * TANDA KOLOM WAJIB — satu gaya untuk seluruh formulir BO.
 *
 * Tombol Simpan / Berikutnya memang DIMATIKAN selama ada isian wajib yang kosong; tanpa tanda,
 * pengguna tidak tahu kolom mana yang menahannya. Kolom wajib yang kosong, atau yang ditolak
 * server, karena itu diberi BINGKAI MERAH dan pesan kecil di bawahnya ("Wajib diisi" atau
 * alasan dari server). Begitu diisi, merahnya hilang sendiri.
 *
 * Pemakaian:
 *   :class="tandai(inputCls, salah)"            — <input>/<select>/<textarea> biasa
 *   <div :class="salah ? CINCIN_MERAH : ''">     — pembungkus SearchSelect / MoneyInput
 *   <PesanWajib :pesan="pesan" />               — pesan di bawah kolom
 */

/** Pembungkus komponen (SearchSelect, MoneyInput): cincin merah — aditif, aman ditumpuk. */
export const CINCIN_MERAH = 'rounded-lg ring-1 ring-danger-400';

/**
 * Kelas kolom dengan bingkai merah bila `salah`.
 *
 * Warna bingkai lama DIBUANG, bukan ditimpa: dua kelas Tailwind yang bertabrakan
 * (border-neutral-300 vs border-danger-500) tidak dimenangkan oleh urutan di atribut,
 * melainkan oleh urutan di berkas CSS — menumpuknya tampak jalan di satu tempat lalu
 * diam-diam gagal di tempat lain.
 */
export function tandai(kelas: string, salah: boolean | null | undefined): string {
    if (! salah) return kelas;

    const tanpaBingkai = kelas.split(/\s+/)
        .filter(k => ! /^(dark:)?border-(neutral|gray|slate|amber|primary)-\d+(\/\d+)?$/.test(k))
        .join(' ');

    return tanpaBingkai + ' border-danger-500 ring-1 ring-danger-400/40 dark:border-danger-500';
}

/** Nilai dianggap kosong: null/undefined, string berisi spasi saja, atau array kosong. */
export function kosong(v: unknown): boolean {
    if (v === null || v === undefined) return true;
    if (typeof v === 'string') return v.trim() === '';
    if (Array.isArray(v)) return v.length === 0;

    return false;
}

/**
 * Pesan untuk satu kolom: galat dari server lebih dulu (alasan yang sebenarnya), lalu
 * "Wajib diisi" bila kolomnya masih kosong. null = kolomnya beres.
 */
export function pesanKolom(galatServer: string | null | undefined, masihKosong: boolean, teks = 'Wajib diisi'): string | null {
    return galatServer || (masihKosong ? teks : null);
}
