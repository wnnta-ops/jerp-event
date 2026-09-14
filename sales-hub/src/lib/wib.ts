// Timezone helpers — the whole app operates in WIB (UTC+7).
// A "WIB day" is represented canonically as a Date at UTC midnight of that
// calendar day (Date.UTC(y, m, d)), so day-grouping in queries is exact and
// independent of the server's local timezone.

export const WIB_OFFSET_MIN = 7 * 60;

const ID_DAYS = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];
const ID_MONTHS_SHORT = [
  "Jan", "Feb", "Mar", "Apr", "Mei", "Jun",
  "Jul", "Agu", "Sep", "Okt", "Nov", "Des",
];
const ID_MONTHS_LONG = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

/** Current instant shifted into WIB wall-clock. */
function wibParts(d: Date = new Date()) {
  const shifted = new Date(d.getTime() + WIB_OFFSET_MIN * 60_000);
  return {
    y: shifted.getUTCFullYear(),
    m: shifted.getUTCMonth(),
    d: shifted.getUTCDate(),
    h: shifted.getUTCHours(),
    min: shifted.getUTCMinutes(),
  };
}

/** WIB calendar day (UTC-midnight canonical) for a given instant (default now). */
export function wibDay(d: Date = new Date()): Date {
  const p = wibParts(d);
  return new Date(Date.UTC(p.y, p.m, p.d));
}

/** Today's WIB day as canonical Date. */
export function wibToday(): Date {
  return wibDay(new Date());
}

/** 'YYYY-MM-DD' key for a WIB day. */
export function dayKey(d: Date): string {
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}-${String(
    d.getUTCDate(),
  ).padStart(2, "0")}`;
}

/** Parse 'YYYY-MM-DD' into a canonical WIB day. */
export function parseDayKey(key: string): Date {
  const [y, m, d] = key.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d));
}

/** 'YYYY-MM' month key for a WIB day. */
export function monthKey(d: Date): string {
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`;
}

/** Inclusive-start, exclusive-end UTC bounds for a WIB day. */
export function dayBounds(d: Date): { gte: Date; lt: Date } {
  const gte = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
  const lt = new Date(gte.getTime() + 24 * 3600_000);
  return { gte, lt };
}

/** Bounds for a whole WIB month (0-based month). */
export function monthBounds(year: number, month0: number): { gte: Date; lt: Date } {
  return {
    gte: new Date(Date.UTC(year, month0, 1)),
    lt: new Date(Date.UTC(year, month0 + 1, 1)),
  };
}

/** Bounds for a whole year. */
export function yearBounds(year: number): { gte: Date; lt: Date } {
  return { gte: new Date(Date.UTC(year, 0, 1)), lt: new Date(Date.UTC(year + 1, 0, 1)) };
}

/** "Sen, 29 Jun 2026" */
export function formatIdDate(d: Date): string {
  return `${ID_DAYS[d.getUTCDay()]}, ${d.getUTCDate()} ${ID_MONTHS_SHORT[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

/** "29 Juni 2026" */
export function formatIdDateLong(d: Date): string {
  return `${d.getUTCDate()} ${ID_MONTHS_LONG[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

export function idMonthShort(month0: number): string {
  return ID_MONTHS_SHORT[month0];
}

/** Current WIB wall-clock time, e.g. "17:05 WIB". */
export function wibClock(d: Date = new Date()): string {
  const p = wibParts(d);
  return `${String(p.h).padStart(2, "0")}:${String(p.min).padStart(2, "0")} WIB`;
}

/**
 * ISO week number (1-5+) within its month — used for the "Trend UTJ mingguan"
 * W1–W5 buckets in the daily report.
 */
export function weekOfMonth(d: Date): number {
  return Math.ceil(d.getUTCDate() / 7);
}
