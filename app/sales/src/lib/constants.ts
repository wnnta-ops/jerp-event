import type { Role, Stage } from "@prisma/client";

// Pipeline stages in order (index 0..7).
export const STAGES: { key: Stage; label: string }[] = [
  { key: "WI", label: "Lead / WI" },
  { key: "FOLLOW_UP", label: "Follow-Up" },
  { key: "NUP", label: "NUP" },
  { key: "UTJ", label: "UTJ" },
  { key: "SPR", label: "SPR" },
  { key: "ACC", label: "ACC Bank" },
  { key: "AKAD", label: "Akad" },
  { key: "REALISASI", label: "Realisasi" },
];

export const STAGE_INDEX: Record<Stage, number> = STAGES.reduce(
  (acc, s, i) => ({ ...acc, [s.key]: i }),
  {} as Record<Stage, number>,
);

// Sales/Koordinator own stages 0..4 (up to & including SPR). Collection owns 5..7.
export const SALES_MAX_INDEX = 4; // SPR

export const ROLE_LABEL: Record<Role, string> = {
  SALES: "Sales / Koordinator",
  COLLECTION: "Collection",
  MANAGEMENT: "Management",
  KPR: "KPR",
  SYSADMIN: "IT Sysadmin",
};

export const STATUS_LEAD_LABEL: Record<string, string> = {
  HOT: "Hot",
  WARM: "Warm",
  COLD: "Cold",
  DONE: "Done",
};

// UI label for the Hot/Warm/Cold field (§12.1 decision).
export const LEAD_FIELD_LABEL = "Status Lead";

export const UNIT_STATUS_LABEL: Record<string, string> = {
  OPEN_STOCK: "Open Stock",
  HOLD: "Hold",
  PROSES: "Proses",
  REALISASI: "Realisasi",
  CASH_LUNAS: "Cash Lunas",
  BATAL: "Batal",
};

export const CHANNEL_LABEL: Record<string, string> = {
  SITE: "Site",
  PAMERAN: "Pameran",
  JANJIAN: "Janjian",
  LEADS: "Leads",
};

// Sumber informasi closing (marketing source) — options + free "Other".
export const SUMBER_INFORMASI: string[] = [
  "Instagram",
  "Tiktok",
  "Flyer/Brosur",
  "Referensi Kerabat/Teman",
  "Baliho",
  "Pameran",
  "Open Table",
  "Facebook",
  "Google Search",
  "Radio",
  "Market Place",
];

export const BATAL_ALASAN_LABEL: Record<string, string> = {
  KOL_2: "KOL 2",
  KOL_5: "KOL 5",
  REJECT_BANK: "Reject Bank",
  BATAL_SEPIHAK: "Batal Sepihak",
  MASALAH_KELUARGA: "Masalah Keluarga",
  SKEMA_BAYAR: "Skema Bayar",
  TIDAK_BERMINAT: "Tidak Berminat",
  OTHER: "Lainnya",
};

export const BATAL_KATEGORI_LABEL: Record<string, string> = {
  UTJ: "UTJ",
  SPR: "SPR",
  WAITING_LIST: "Waiting List",
};

export const ANNUAL_TARGET_PERIOD = "2026";
