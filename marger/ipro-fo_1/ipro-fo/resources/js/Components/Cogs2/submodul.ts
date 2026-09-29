/**
 * COGS Baru — identitas MASING-MASING SUB-MODUL.
 *
 * Keempat layar COGS Baru berdiri sebagai sub-modul yang terpisah di sidebar: Perhitungan COGS,
 * Set Up Kategori COGS, Actual Costing COGS, dan Rincian COGS. Berkas ini satu-satunya tempat
 * nama, ikon, dan keterangan keempatnya ditulis, sehingga judul di layar TIDAK MUNGKIN berbeda
 * dari label yang diklik orang di sidebar. Dua nama untuk satu layar adalah cara tercepat membuat
 * orang mengira ia membuka layar yang lain.
 *
 * Ikonnya sengaja sama persis dengan ikon butir menunya di sidebar: itulah tanda paling cepat
 * bahwa layar yang terbuka memang layar yang diklik.
 */
import { Calculator, SlidersHorizontal, ReceiptText, FileBarChart } from 'lucide-vue-next';
import { KOMPONEN } from './warna';

export type Layar = 'perhitungan' | 'kategori' | 'actual' | 'rincian';

export const SUBMODUL: Record<Layar, {
    /** Nama sub-modul — SAMA PERSIS dengan judul grupnya di sidebar. */
    nama: string;
    ikon: any;
    /** Satu kalimat: apa yang dikerjakan di sini, supaya layar yang berdiri sendiri tetap terbaca. */
    keterangan: string;
    /**
     * Sub-modul yang butir menunya dipecah per komponen (…-Tanah, …-Housing, …-Infra, …-License).
     * Hanya di sinilah pemindah komponen muncul; komponen BUKAN sub-modul tersendiri, ia empat
     * tampilan dari sub-modul yang sama.
     */
    berkomponen: boolean;
}> = {
    perhitungan: {
        nama: 'Perhitungan COGS',
        ikon: Calculator,
        keterangan: 'Perhitungan lahan, komposisi budget HPP per komponen, dan totalnya untuk periode ini.',
        berkomponen: false,
    },
    kategori: {
        nama: 'Set Up Kategori COGS',
        ikon: SlidersHorizontal,
        keterangan: 'Rincian budget per kategori — angka inilah yang dipakai sebagai budget komponen.',
        berkomponen: true,
    },
    actual: {
        nama: 'Actual Costing COGS',
        ikon: ReceiptText,
        keterangan: 'Biaya yang sudah benar-benar terjadi: diketik manual, dari Kontrak SPK (MUK), dan dari jurnal.',
        berkomponen: true,
    },
    rincian: {
        nama: 'Rincian COGS',
        ikon: FileBarChart,
        keterangan: 'HPP tiap unit: budget menurut luasnya, lalu biaya aktual dibebankan ulang ke unit yang belum terkunci.',
        berkomponen: false,
    },
};

/**
 * Judul lengkap satu layar — DISUSUN SAMA PERSIS dengan label butir menunya di sidebar, termasuk
 * tanda hubungnya: "Set Up Kategori COGS-Tanah", bukan "Set Up Kategori COGS — Tanah".
 */
export function judulLayar(layar: Layar, komponen?: string | null): string {
    const s = SUBMODUL[layar];
    if (! s.berkomponen) return s.nama;

    const k = KOMPONEN.find((x) => x.kunci === komponen);

    return k ? s.nama + '-' + k.pendek : s.nama;
}
