/**
 * File yang dipilih dari tombol kecil "Upload file" di daftar Account Payable, dititipkan sampai
 * wizard Invoice Hutang terbuka (navigasi Inertia tidak memuat ulang halaman, jadi File tetap ada)
 * lalu masuk ke drop box Upload File di blok Berkas Tagihan.
 */
import { shallowRef } from 'vue';

export const berkasTitipan = shallowRef<File[]>([]);

const POLA: Record<string, RegExp> = {
    INVOICE: /invoice|inv|nota|tagihan/i, KUITANSI: /kuitansi|kwitansi|receipt/i, 'FAKTUR-PAJAK': /faktur|efaktur|pajak|tax/i,
};

/** Tebak jenis dokumen dari nama file (pengunggah tetap bisa mengganti). */
export function tebakJenis(nama: string, jenis: Array<{ id: number; code?: string }>): number | '' {
    for (const j of jenis) if (j.code && POLA[j.code]?.test(nama)) return j.id;
    return '';
}
