import { format } from "date-fns";
import { id } from "date-fns/locale";
import type { DocStatus, DocType } from "./enums";

export function formatDateID(value: string | Date | null | undefined): string {
  if (!value) return "";
  const dt = typeof value === "string" ? new Date(value) : value;
  if (Number.isNaN(dt.getTime())) return "";
  return format(dt, "d MMM yyyy", { locale: id });
}

/** Whole calendar days from `now` to `value` (negative = in the past). */
export function daysUntil(value: string | Date, now: Date = new Date()): number {
  const dt = typeof value === "string" ? new Date(value) : value;
  const a = Date.UTC(dt.getFullYear(), dt.getMonth(), dt.getDate());
  const b = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
  return Math.round((a - b) / 86_400_000);
}

export type Tone = "secondary" | "warning" | "danger";

/** A document is "expiring soon" when its expiry is within the next 30 days. */
export function isExpiringSoon(expiryDate: string | null | undefined, now: Date = new Date()): boolean {
  if (!expiryDate) return false;
  const du = daysUntil(expiryDate, now);
  return du >= 0 && du <= 30;
}

/**
 * The "Key date" column (spec §4): issue or expiry, amber if ≤30 days, red if past.
 * KPR expiry reads as an offer validity; far-future certificates read as "valid until".
 */
export function keyDate(
  doc: { docType: DocType; status: DocStatus; issueDate: string | null; expiryDate: string | null },
  now: Date = new Date(),
): { text: string; tone: Tone } {
  if (doc.expiryDate) {
    const du = daysUntil(doc.expiryDate, now);
    const dateStr = formatDateID(doc.expiryDate);
    if (du < 0) return { text: `Kedaluwarsa ${dateStr}`, tone: "danger" };
    if (du <= 30) {
      return { text: `Kedaluwarsa ${dateStr}`, tone: "warning" };
    }
    if (du > 365) return { text: `Berlaku s/d ${new Date(doc.expiryDate).getFullYear()}`, tone: "secondary" };
    return { text: `Kedaluwarsa ${dateStr}`, tone: "secondary" };
  }
  if (doc.issueDate) return { text: `Terbit ${formatDateID(doc.issueDate)}`, tone: "secondary" };
  if (doc.status === "Draft") return { text: "Draf", tone: "secondary" };
  return { text: "—", tone: "secondary" };
}
