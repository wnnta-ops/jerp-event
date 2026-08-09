/**
 * Canonical enum values for the Legal module (spec §3).
 *
 * The VALUE is the stable key stored in the database (English-ish, never shown
 * raw to users). The LABEL is the Indonesian text rendered in the UI. Keeping
 * them separate means we can localize freely without migrating data, and it is
 * the seam where native Postgres enums slot in later.
 */

export const DOC_TYPES = [
  // Perizinan
  "IzinLokasi", "IzinLingkungan", "SKRK", "PBGInduk", "PBGSplit", "AJB",
  // Sertifikasi
  "PetaBidang", "SKHGB", "SHGBInduk", "SHGBSplit", "PBBSplit",
  // Korporasi
  "AktaPendirian", "RUPS",
  // Perjanjian
  "PerjanjianAgent", "PerjanjianKPR", "PerjanjianKredit", "PerjanjianJaminanAset", "PerjanjianPihakKetiga",
  // Aset (satu tipe umum — tab Aset tidak dipecah jadi sub-tipe)
  "Aset",
  // Lain (catch-all; tidak masuk tab manapun selain Semua)
  "Other",
] as const;
export type DocType = (typeof DOC_TYPES)[number];

export const DOC_STATUSES = [
  "Draft",
  "AwaitingSignature",
  "Signed",
  "Registered",
  "Active",
  "Expiring",
  "Expired",
  "Superseded",
] as const;
export type DocStatus = (typeof DOC_STATUSES)[number];

/** Status options offered per category (Sertifikasi/Korporasi use a short set). */
export function statusesForCategory(cat: string | null | undefined): DocStatus[] {
  return cat === "sertifikasi" || cat === "korporasi" || cat === "aset"
    ? ["Active", "Expiring", "Expired"]
    : [...DOC_STATUSES];
}

export const ORIGINAL_LOCATIONS = ["OfficeSafe", "BankKPR", "NotarisPPAT", "WithBuyer"] as const;
export type OriginalLocation = (typeof ORIGINAL_LOCATIONS)[number];

/** Label for the extra detail field shown once a physical location is chosen. */
export const ORIGINAL_LOCATION_DETAIL_LABELS: Record<OriginalLocation, string> = {
  OfficeSafe: "Kode penyimpanan",
  BankKPR: "Nama bank",
  NotarisPPAT: "Nama notaris",
  WithBuyer: "Nama pembeli",
};

export const ORIGINAL_LOCATION_DETAIL_PLACEHOLDERS: Record<OriginalLocation, string> = {
  OfficeSafe: "cth. BRK-A-014",
  BankKPR: "cth. Bank BCA",
  NotarisPPAT: "cth. Notaris Budi Santoso, S.H.",
  WithBuyer: "cth. Budi Santoso",
};

export const ACCESS_LEVELS = ["SalesVisible", "Leadership", "LegalOnly"] as const;
export type AccessLevel = (typeof ACCESS_LEVELS)[number];

export const SCOPES = ["unit", "development", "entity"] as const;
export type Scope = (typeof SCOPES)[number];

// SYSADMIN = akun kontrol IT (di luar peran bisnis) — akses & wewenang penuh:
// lihat semua + boleh menyetujui langkah manapun (override). Lihat lib/access
// / requests/actions untuk bypass otorisasinya.
export const ROLES = ["SYSADMIN", "LEGAL_PIC", "HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR", "REQUESTER"] as const;
export type Role = (typeof ROLES)[number];

/** Unit-scoped types — per-kavling "split" docs (require cluster/blok/unit). */
export const TRANSACTION_TYPES: DocType[] = ["PBGSplit", "SHGBSplit", "PBBSplit"];

/** Entity-scoped types — belong to a PT/company, not a unit (require entityName). */
export const ENTITY_TYPES: DocType[] = ["AktaPendirian", "RUPS"];

/** Document types whose expiry/renewal date is mandatory at upload. None for now —
 *  Legal sets expiry manually via the doc drawer (still feeds timeline/alerts). */
export const EXPIRY_REQUIRED_TYPES: DocType[] = [];

/** Types offered in the access-request category dropdown (permits + certificates). */
export const REQUESTABLE_DOC_TYPES: DocType[] = [
  "IzinLokasi", "IzinLingkungan", "SKRK", "PBGInduk", "PBGSplit",
  "PetaBidang", "SKHGB", "SHGBInduk", "SHGBSplit", "PBBSplit",
];

/** Document categories = the directory tabs. Each maps to its doc types (Aset has none yet). */
export const DOC_CATEGORIES: { key: string; label: string; types: DocType[] }[] = [
  { key: "perizinan", label: "Perizinan", types: ["IzinLokasi", "IzinLingkungan", "SKRK", "PBGInduk", "PBGSplit", "AJB"] },
  { key: "sertifikasi", label: "Sertifikasi", types: ["PetaBidang", "SKHGB", "SHGBInduk", "SHGBSplit", "PBBSplit"] },
  { key: "korporasi", label: "Korporasi", types: ["AktaPendirian", "RUPS"] },
  { key: "perjanjian", label: "Perjanjian", types: ["PerjanjianAgent", "PerjanjianKPR", "PerjanjianKredit", "PerjanjianJaminanAset", "PerjanjianPihakKetiga"] },
  { key: "aset", label: "Aset", types: ["Aset"] },
];

export function categoryOfDocType(t: DocType): string | null {
  return DOC_CATEGORIES.find((c) => c.types.includes(t))?.key ?? null;
}

// ---------------------------------------------------------------------------
// Indonesian display labels
// ---------------------------------------------------------------------------

export const DOC_TYPE_LABELS: Record<DocType, string> = {
  IzinLokasi: "Izin Lokasi",
  IzinLingkungan: "Izin Lingkungan",
  SKRK: "SKRK",
  PBGInduk: "PBG Induk",
  PBGSplit: "PBG Split",
  AJB: "AJB",
  PetaBidang: "Peta Bidang",
  SKHGB: "SKHGB",
  SHGBInduk: "SHGB Induk",
  SHGBSplit: "SHGB Split",
  PBBSplit: "PBB Split",
  AktaPendirian: "Akta Pendirian",
  RUPS: "RUPS",
  PerjanjianAgent: "Perjanjian Agent",
  PerjanjianKPR: "Perjanjian KPR",
  PerjanjianKredit: "Perjanjian Kredit",
  PerjanjianJaminanAset: "Perjanjian Jaminan Aset",
  PerjanjianPihakKetiga: "Perjanjian Pihak Ketiga Lainnya",
  Aset: "Aset",
  Other: "Lainnya",
};

/** Short codes for the neutral type badge in tables. */
export const DOC_TYPE_SHORT: Record<DocType, string> = {
  IzinLokasi: "Izin Lokasi",
  IzinLingkungan: "Izin Lingkungan",
  SKRK: "SKRK",
  PBGInduk: "PBG Induk",
  PBGSplit: "PBG Split",
  AJB: "AJB",
  PetaBidang: "Peta Bidang",
  SKHGB: "SKHGB",
  SHGBInduk: "SHGB Induk",
  SHGBSplit: "SHGB Split",
  PBBSplit: "PBB Split",
  AktaPendirian: "Akta Pendirian",
  RUPS: "RUPS",
  PerjanjianAgent: "Perj. Agent",
  PerjanjianKPR: "Perj. KPR",
  PerjanjianKredit: "Perj. Kredit",
  PerjanjianJaminanAset: "Perj. Jaminan Aset",
  PerjanjianPihakKetiga: "Perj. Pihak Ketiga",
  Aset: "Aset",
  Other: "Lainnya",
};

export const DOC_STATUS_LABELS: Record<DocStatus, string> = {
  Draft: "Draf",
  AwaitingSignature: "Menunggu tanda tangan",
  Signed: "Ditandatangani",
  Registered: "Terdaftar",
  Active: "Aktif",
  Expiring: "Akan berakhir",
  Expired: "Berakhir",
  Superseded: "Digantikan",
};

export const ORIGINAL_LOCATION_LABELS: Record<OriginalLocation, string> = {
  OfficeSafe: "Kantor",
  BankKPR: "Bank",
  NotarisPPAT: "Notaris",
  WithBuyer: "Dengan pembeli",
};

export const ACCESS_LEVEL_LABELS: Record<AccessLevel, string> = {
  SalesVisible: "Terlihat sales",
  Leadership: "Pimpinan",
  LegalOnly: "Legal saja",
};

export const ROLE_LABELS: Record<Role, string> = {
  SYSADMIN: "Sysadmin (IT)",
  LEGAL_PIC: "Legal PIC",
  HEAD_OF_LEGAL: "Head of Legal",
  GENERAL_MANAGER: "General Manager",
  DIRECTOR: "Direktur",
  REQUESTER: "Pemohon",
};

/**
 * Status -> semantic color family (spec §4: "status carries the color").
 * Maps to the bg-/ink- token families in tailwind.config.ts.
 */
export const STATUS_TONE: Record<DocStatus, "neutral" | "info" | "success" | "warning" | "danger"> = {
  Draft: "neutral",
  AwaitingSignature: "info",
  Signed: "success",
  Registered: "success",
  Active: "success",
  Expiring: "warning",
  Expired: "danger",
  Superseded: "danger",
};

/** Scope is derived from the document type, never asked of the user. */
export function scopeForDocType(docType: DocType): Scope {
  if (TRANSACTION_TYPES.includes(docType)) return "unit";
  if (ENTITY_TYPES.includes(docType)) return "entity";
  return "development";
}
