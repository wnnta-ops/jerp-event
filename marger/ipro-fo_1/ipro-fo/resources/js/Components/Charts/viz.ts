// Perkakas bersama untuk grafik Dashboard Back Office.
//
// Palet warnanya TIDAK ditulis di sini sebagai hex, melainkan sebagai CSS variable
// (lihat blok `.viz` di resources/css/app.css). Alasannya: nilai mode terang & gelap
// harus berganti di satu tempat, dan komponen ditulis terhadap PERAN warna, bukan hex.
//
// Palet itu sudah diuji dengan pemeriksa buta warna terhadap permukaan asli aplikasi
// (kartu terang #ffffff dan kartu gelap #0f172a = neutral-900) dan lolos seluruh
// gerbang di kedua mode. Jangan mengganti hex-nya tanpa menjalankan ulang pemeriksa itu.

/** Delapan slot kategorikal, urutannya adalah mekanisme keamanan buta warna — jangan diacak. */
export const S: readonly string[] = [
    'var(--viz-s1)', 'var(--viz-s2)', 'var(--viz-s3)', 'var(--viz-s4)',
    'var(--viz-s5)', 'var(--viz-s6)', 'var(--viz-s7)', 'var(--viz-s8)',
];

/** Ramp biru satu rona — untuk besaran berurut. Indeks 0 paling pucat. */
export const SEQ: readonly string[] = [
    'var(--viz-seq1)', 'var(--viz-seq2)', 'var(--viz-seq3)',
    'var(--viz-seq4)', 'var(--viz-seq5)', 'var(--viz-seq6)',
];

/** Ramp oranye satu rona — dipakai Aging Hutang & SPK per Cluster. */
export const ORA: readonly string[] = [
    'var(--viz-o1)', 'var(--viz-o2)', 'var(--viz-o3)',
    'var(--viz-o4)', 'var(--viz-o5)', 'var(--viz-o6)',
];

/**
 * Warna tahap untuk kedua grafik corong KPR — satu rona berbeda per tahap.
 *
 * Urutan ini hasil enumerasi, bukan selera: dari 30 urutan yang lolos gerbang buta warna
 * di kedua mode, ini yang pemisahan CVD-nya tertinggi (13,0 — target 8) sekaligus
 * pemisahan penglihatan normalnya terbaik (33,6).
 *
 * MERAH sengaja tidak dipakai: pada grafik "Penolakan BI Checking & Proses KPR" merah
 * berarti PENOLAKAN, dan arti itu tidak boleh bocor ke tahap yang normal.
 */
export const TAHAP: readonly string[] = [S[1], S[0], S[3], S[6], S[4], S[5]];

/** Rp 1.234.567 — dipakai pada label nilai. */
export function rp(n: number): string {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

/** Angka sumbu dalam jutaan, tanpa satuan (satuan ditulis di judul panel). */
export function jt(n: number): string {
    return (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 });
}

/**
 * Rupiah disingkat bertingkat — dipakai label nilai pada grafik KOLOM, di mana satu
 * slot kolom hanya selebar ~60px: "Rp 3.220.470.100" tidak muat, "Rp 3,22 M" muat.
 * Nilai penuhnya tidak hilang — tetap dicetak utuh pada tooltip tiap kolom.
 *
 * Tandanya diletakkan SESUDAH "Rp" (Rp -1,9 M), mengikuti kebiasaan yang sudah dipakai
 * rp() supaya satu angka minus terbaca sama di seluruh dashboard.
 */
export function rpRingkas(n: number): string {
    const a = Math.abs(n);
    const t = n < 0 ? '-' : '';
    const f = (v: number, d: number) => v.toLocaleString('id-ID', { maximumFractionDigits: d });
    if (a >= 1e9) return 'Rp ' + t + f(a / 1e9, 2) + ' M';
    if (a >= 1e6) return 'Rp ' + t + f(a / 1e6, a >= 1e8 ? 0 : 1) + ' jt';
    if (a >= 1e3) return 'Rp ' + t + f(a / 1e3, 0) + ' rb';
    return 'Rp ' + t + f(a, 0);
}

/** Nilai besar dalam miliar: "18,9 M". */
export function md(n: number): string {
    return (n / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' M';
}

/** Pembentuk label berjumlah satuan: cnt('unit')(7) → "7 unit". */
export function cnt(satuan: string): (n: number) => string {
    return (n: number) => `${n.toLocaleString('id-ID')} ${satuan}`;
}

/** Bulan singkat Indonesia — dipakai bila controller tidak mengirim label. */
export const BULAN_ID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** Deret bertumpuk/berkelompok yang dikirim controller. */
export interface Seri { n: string; c: number; v: number[] }
/** Satu baris batang horizontal. */
export interface Baris { n: string; v: number }
/** Kunci segmen pada batang bertumpuk. */
export interface Kunci { k: string; n: string; c: number }
/** Baris batang bertumpuk: selain `n`, kuncinya dinamis (a/b/c/d). */
export type BarisTumpuk = { n: string } & Record<string, number | string>;

/**
 * Skala aman: hindari pembagian nol saat semua nilai 0 (tabel masih kosong),
 * yang kalau tidak dijaga menghasilkan NaN dan SVG-nya gagal tampil.
 */
export function maxAman(nilai: number[]): number {
    const m = Math.max(...nilai.filter((v) => Number.isFinite(v)), 0);
    return m > 0 ? m : 1;
}

/** Apakah seluruh angka nol / kosong → layar menampilkan keadaan kosong yang jujur. */
export function kosong(nilai: number[]): boolean {
    return nilai.length === 0 || nilai.every((v) => !Number.isFinite(v) || v === 0);
}
