import { format } from "date-fns";
import { id as idLocale } from "date-fns/locale";
import {
  DOC_TYPE_LABELS,
  DOC_STATUS_LABELS,
  ORIGINAL_LOCATION_LABELS,
  type DocType,
  type DocStatus,
  type OriginalLocation,
} from "./enums";

/**
 * Per-tab Excel export columns. Mirrors the reference workbook
 * ("tabel data export Legal.xlsx") — one sheet per directory tab — plus a
 * STATUS column (keaktifan dokumen) requested on top of the reference.
 */

// Minimal shape we read off a Prisma Document.
export type ExportDoc = {
  title: string;
  docType: string;
  status: string;
  docNumber: string | null;
  cluster: string | null;
  issueDate: Date | null;
  expiryDate: Date | null;
  landArea: string | null;
  objectLocation: string | null;
  originalLocation: string;
  originalLocationDetail: string | null;
  entityName: string | null;
  boardStructure: string | null;
  authorizedCapital: string | null;
  issuedCapital: string | null;
  shareholders: string | null;
  parties: string | null;
};

const fdate = (d: Date | null) => (d ? format(d, "d MMM yyyy", { locale: idLocale }) : "");
const typeL = (t: string) => DOC_TYPE_LABELS[t as DocType] ?? t;
const statusL = (s: string) => DOC_STATUS_LABELS[s as DocStatus] ?? s;
const locL = (loc: string, detail: string | null) => {
  const l = ORIGINAL_LOCATION_LABELS[loc as OriginalLocation] ?? loc;
  return detail ? `${l} — ${detail}` : l;
};
function shareholdersL(json: string | null): string {
  try {
    const arr = JSON.parse(json || "[]");
    return Array.isArray(arr)
      ? arr.map((s) => `${s.name ?? ""}${s.percentage ? ` (${s.percentage}%)` : ""}`.trim()).filter(Boolean).join("; ")
      : "";
  } catch {
    return "";
  }
}
function partiesL(json: string | null): string {
  try {
    const arr = JSON.parse(json || "[]");
    return Array.isArray(arr)
      ? arr.map((p) => `${p.name ?? ""}${p.entity ? ` — ${p.entity}` : ""}`.trim()).filter(Boolean).join("; ")
      : "";
  } catch {
    return "";
  }
}

export type ExportColumn = { header: string; width: number; get: (d: ExportDoc) => string };

// Perizinan / Sertifikasi / Aset share the same column set.
const LAND_COLUMNS: ExportColumn[] = [
  { header: "JUDUL DOKUMEN", width: 34, get: (d) => d.title },
  { header: "STATUS", width: 16, get: (d) => statusL(d.status) },
  { header: "JENIS DOKUMEN", width: 18, get: (d) => typeL(d.docType) },
  { header: "LOKASI ORIGINAL FISIK", width: 26, get: (d) => locL(d.originalLocation, d.originalLocationDetail) },
  { header: "NOMOR DOKUMEN", width: 20, get: (d) => d.docNumber ?? "" },
  { header: "CLUSTER", width: 18, get: (d) => d.cluster ?? d.objectLocation ?? "" },
  { header: "TANGGAL TERBIT", width: 16, get: (d) => fdate(d.issueDate) },
  { header: "MASA BERAKHIR", width: 16, get: (d) => fdate(d.expiryDate) },
  { header: "LUAS", width: 14, get: (d) => d.landArea ?? "" },
];

const KORPORASI_COLUMNS: ExportColumn[] = [
  { header: "JUDUL DOKUMEN", width: 34, get: (d) => d.title },
  { header: "STATUS", width: 16, get: (d) => statusL(d.status) },
  { header: "JENIS DOKUMEN", width: 18, get: (d) => typeL(d.docType) },
  { header: "NAMA ENTITAS", width: 24, get: (d) => d.entityName ?? "" },
  { header: "SUSUNAN PENGURUS", width: 30, get: (d) => d.boardStructure ?? "" },
  { header: "MODAL DASAR", width: 18, get: (d) => d.authorizedCapital ?? "" },
  { header: "MODAL DITEMPATKAN", width: 18, get: (d) => d.issuedCapital ?? "" },
  { header: "PEMEGANG SAHAM", width: 32, get: (d) => shareholdersL(d.shareholders) },
  { header: "LOKASI ORIGINAL FISIK", width: 26, get: (d) => locL(d.originalLocation, d.originalLocationDetail) },
  { header: "NOMOR DOKUMEN", width: 20, get: (d) => d.docNumber ?? "" },
  { header: "TANGGAL TERBIT", width: 16, get: (d) => fdate(d.issueDate) },
  { header: "MASA BERAKHIR", width: 16, get: (d) => fdate(d.expiryDate) },
];

const PERJANJIAN_COLUMNS: ExportColumn[] = [
  { header: "JUDUL DOKUMEN", width: 34, get: (d) => d.title },
  { header: "STATUS", width: 16, get: (d) => statusL(d.status) },
  { header: "JENIS DOKUMEN", width: 22, get: (d) => typeL(d.docType) },
  { header: "LOKASI ORIGINAL FISIK", width: 26, get: (d) => locL(d.originalLocation, d.originalLocationDetail) },
  { header: "NOMOR DOKUMEN", width: 20, get: (d) => d.docNumber ?? "" },
  { header: "TANGGAL PERJANJIAN", width: 18, get: (d) => fdate(d.issueDate) },
  { header: "MASA BERAKHIR", width: 16, get: (d) => fdate(d.expiryDate) },
  { header: "OBJEK PERJANJIAN", width: 30, get: (d) => d.objectLocation ?? "" },
  { header: "NAMA PIHAK", width: 32, get: (d) => partiesL(d.parties) },
];

export const EXPORT_COLUMNS: Record<string, ExportColumn[]> = {
  perizinan: LAND_COLUMNS,
  sertifikasi: LAND_COLUMNS,
  aset: LAND_COLUMNS,
  korporasi: KORPORASI_COLUMNS,
  perjanjian: PERJANJIAN_COLUMNS,
};
