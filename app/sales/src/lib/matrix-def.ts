// Pure Matrix layout definitions — safe to import in client components
// (no Prisma / server-only imports).

// Where the manual Matrix cells are edited. INTERCHANGEABLE — flip this one
// value (or NEXT_PUBLIC_MATRIX_INPUT_MODE in .env) to change the whole UX:
//   "collection" (Opsi 3) — Matrix page is read-only; input lives in the
//                            Collection module's "Input Matrix" tab.
//   "inline"     (Opsi 1/5) — cells are edited directly on the Matrix page.
export type MatrixInputMode = "collection" | "inline";
export const MATRIX_INPUT_MODE: MatrixInputMode =
  (process.env.NEXT_PUBLIC_MATRIX_INPUT_MODE as MatrixInputMode) || "collection";

export interface MatrixCol {
  key: string;
  label: string;
  derived: boolean; // false = always manual (Cluster 1)
}

export const MATRIX_COLS: MatrixCol[] = [
  { key: "c1", label: "1", derived: false },
  { key: "c2", label: "2", derived: true },
  { key: "c31_32", label: "3.1 & 3.2", derived: true },
  { key: "c33", label: "3.3", derived: true },
  { key: "c34", label: "3.4", derived: true },
  { key: "c51", label: "5.1", derived: true },
  { key: "c52", label: "5.2", derived: true },
];

export type ColMap = Record<string, number>;
export const zeroCols = (): ColMap => ({ c1: 0, c2: 0, c31_32: 0, c33: 0, c34: 0, c51: 0, c52: 0 });

// Map a deal/unit's cluster+phase to a matrix column key (or null).
export function colOf(cluster?: string | null, phase?: string | null): string | null {
  if (cluster === "Cluster 2") return "c2";
  if (cluster === "Cluster 3") {
    if (phase === "I" || phase === "II") return "c31_32";
    if (phase === "III") return "c33";
    if (phase === "IV") return "c34";
    return null;
  }
  if (cluster === "Cluster 5") {
    if (phase === "I") return "c51";
    if (phase === "II") return "c52";
    return null;
  }
  return null; // Cluster 1 (c1) is manual; others unmapped
}

export type RowKind = "derived" | "input";
export interface MatrixRow {
  key?: string;
  label?: string;
  kind?: RowKind;
  section?: string; // section header row
  sep?: boolean; // blank spacer row
  strong?: boolean; // bold row
  hi?: boolean; // highlighted (yellow)
  note?: string; // extra text shown near the section (e.g. "1 unit/day")
}

export const MATRIX_ROWS: MatrixRow[] = [
  { key: "TOTAL_UNIT", label: "TOTAL UNIT", kind: "derived", strong: true },
  { sep: true },
  { key: "SPR_NET_2025", label: "SPR Nett 2025", kind: "derived" },
  { key: "SPR_NET_SD_2025", label: "SPR Nett s/d 2025", kind: "derived" },
  { sep: true },
  { key: "STOCK_AWAL_2026", label: "STOCK AWAL 2026", kind: "derived", strong: true },
  { sep: true },
  { key: "SPR_NET_2026", label: "SPR Nett 2026", kind: "derived" },
  { key: "SPR_NET_SD_2026", label: "SPR Nett s/d 2026", kind: "derived" },
  { sep: true },
  { section: "SISA STOCK" },
  { key: "SISA_STOCK", label: "Rumah & Ruha", kind: "derived", strong: true },
  { section: "Indikator Sales per Day 2026", note: "1 unit/day" },
  { key: "WI_DAY", label: "WI Hari Ini", kind: "derived" },
  { key: "UTJ_DAY", label: "UTJ Hari Ini", kind: "derived" },
  { key: "NUP_DAY", label: "Waiting List/NUP Hari Ini", kind: "derived" },
  { key: "SPR_DAY", label: "SPR Hari Ini", kind: "derived" },
  { sep: true },
  { key: "WI_MONTH", label: "WI (Nett) Bulan Ini", kind: "derived" },
  { key: "UTJ_MONTH", label: "UTJ (Nett) Bulan Ini", kind: "derived" },
  { key: "NUP_MONTH", label: "Waiting List/NUP Bln Ini", kind: "derived" },
  { key: "SPR_MONTH", label: "SPR (Nett) Bulan Ini", kind: "derived" },
  { sep: true },
  { key: "HOLD", label: "Total Hold Unit (Pre SPR)", kind: "derived", hi: true },
  { key: "WAITING_TOTAL", label: "Total Waiting List/NUP", kind: "input", hi: true },
  { section: "COLLECTION" },
  { key: "COLL_SD_2025", label: "Total Collection s/d 2025", kind: "derived", strong: true },
  { key: "COLL_SD_2026", label: "Total Collection s/d 2026", kind: "derived", strong: true },
  { key: "COLL_2026", label: "Total Collection 2026", kind: "derived", strong: true },
  { key: "KPR_2026", label: "KPR 2026", kind: "derived" },
  { key: "CASH_2026", label: "Cash 2026", kind: "derived" },
  { section: "PROSES" },
  { key: "SP3K", label: "SP3K Blm Akad", kind: "input" },
  { key: "PROSES_KPR", label: "Proses KPR", kind: "input" },
  { key: "SETOFF", label: "Set Off & Cash Blm Lunas", kind: "input" },
  { section: "Rekap 1 Minggu" },
  { key: "WI_WEEK", label: "Total WI 1 Minggu", kind: "derived" },
  { key: "UTJ_WEEK", label: "Total UTJ 1 Minggu", kind: "derived" },
  { key: "UTJBATAL_WEEK", label: "Total UTJ Batal 1 Minggu", kind: "derived" },
  { key: "NUP_WEEK", label: "Total NUP 1 Minggu", kind: "derived" },
  { key: "COLL_WEEK", label: "Total Collection 1 Minggu", kind: "derived", strong: true },
];
