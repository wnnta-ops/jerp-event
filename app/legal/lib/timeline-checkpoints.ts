// Badan → jenis dokumen → checkpoint (proses) mapping.
// Sumber: "Entry Sistem Per pekerjaan untuk Sistem Legal.xlsx" (sheet SERTIPIKASI,
// PERIZINAN, CORPORATE). Dipakai saat menambah item timeline: pilih badan lalu jenis
// dokumen → checkpoint item otomatis terisi sesuai daftar di bawah.

export const BADAN = [
  { key: "SERTIPIKASI", label: "Sertipikasi" },
  { key: "PERIZINAN", label: "Perizinan" },
  { key: "CORPORATE", label: "Corporate" },
] as const;
export type BadanKey = (typeof BADAN)[number]["key"];

export const CHECKPOINT_MAP: Record<BadanKey, Record<string, string[]>> = {
  SERTIPIKASI: {
    "Peta Bidang": [
      "PPJB KUM Pelepasan Hak Ket. Desa", "Permohonan Peta Bidang", "Pelunasan PBB Petani",
      "Pengukuran", "Persetujuan Hasil Ukur (GU)", "Pembayaran SPS", "Produk Peta Bidang",
    ],
    "SK Landreform": [
      "Verif & Validasi SSP Waris", "Verif & Validasi BPHTB Waris", "SKLK Kepolisian",
      "Permohonan SK Landreform", "Survey SK Landreform", "Pengantar Kanwil SK Landreform", "Produk SK Landreform",
    ],
    "SKHGB": [
      "Penegasan Hak / SKPT", "Permohonan SKHGB", "Pembayaran SPS", "Panitia A / Telaah Spasial",
      "Bayar Verif & Validasi SSP", "Bayar Verif & Validasi BPHTB", "Produk SKHGB",
    ],
    "SHGB Induk": [
      "Validasi Sertipikat / Penghapusan Hak", "Pengindukkan PBB", "Permohonan SHGB", "Pembayaran SPS", "Produk SHGB",
    ],
    "SHGB Split": [
      "Permohonan SHGB Split", "Pembayaran SPS", "Survey & Ukur Split Sertipikat", "Produk Split Sertipikat",
    ],
    "PBB Split": [
      "Permohonan PBB Split", "Tanda Terima Permohonan", "Produk PBB Split",
    ],
  },
  PERIZINAN: {
    "PKKPR": [
      "Permohonan OSS", "Pembayaran SPS Pertek", "Permohonan Pertek", "Survey Pertek", "Produk Pertek",
      "Permohonan RKKPR", "Produk RKKPR", "Permohonan PKKPR", "Produk PKKPR",
    ],
    "Izin Lingkungan": [
      "Permohonan Sempadan, Piel Banjir & Jembatan", "Tanda Terima Permohonan", "Produk Sempadan & Piel Banjir",
      "Bukti Bayar Retribusi Jembatan", "Produk Izin Jembatan", "Rapat Drainase", "Produk Drainase",
      "Rapat IPAL", "Produk IPAL", "Rapat Andalalin", "Produk Andalalin", "Rapat Rintek", "Produk Rintek",
      "Rapat AMDAL", "Produk AMDAL", "Pengantar Kanwil SK Landreform", "Produk SK Landreform",
    ],
    "SKRK": [
      "Permohonan SKRK", "Tanda Terima Permohonan", "Survey SKRK", "BA Rapat SKRK", "Produk SKRK",
    ],
    "PBG Induk": [
      "Permohonan PBG", "Tanda Terima Permohonan", "Pembayaran Retribusi", "Produk PBG Induk", "Legalisir Arsip PBG Induk & Bestek",
    ],
    "PBG Split": [
      "Permohonan PBG Split", "Tanda Terima Permohonan", "Produk PBG Split", "Legalisir Arsip PBG Split",
    ],
  },
  CORPORATE: {
    "PKS Agent": [
      "Permohonan Perpanjangan PKS", "Arsip Draft Tandatangan",
    ],
    "Review Draft Perjanjian": [
      "Permohonan Review", "Draft Pasca Review", "Button Persetujuan", "Pengembalian ke Dept. Terkait", "Arsip Perjanjian Tertandatangan",
    ],
    "Drafting Perjanjian": [
      "Permohonan Perjanjian + Poin Wajib (Matriks)", "Draft Perjanjian", "Button Persetujuan", "Pengembalian ke Dept. Terkait", "Arsip Perjanjian Tertandatangan",
    ],
  },
};

export function badanLabel(key: string | null | undefined): string {
  return BADAN.find((b) => b.key === key)?.label ?? "";
}

/** Daftar jenis dokumen untuk sebuah badan (kosong bila badan tak dikenal). */
export function jenisForBadan(badan: string | null | undefined): string[] {
  if (!badan || !(badan in CHECKPOINT_MAP)) return [];
  return Object.keys(CHECKPOINT_MAP[badan as BadanKey]);
}

/** Daftar checkpoint (label proses) untuk badan+jenis (kosong bila tak dikenal). */
export function checkpointsFor(badan: string | null | undefined, jenis: string | null | undefined): string[] {
  if (!badan || !jenis || !(badan in CHECKPOINT_MAP)) return [];
  return CHECKPOINT_MAP[badan as BadanKey][jenis] ?? [];
}
