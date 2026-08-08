import { daysUntil, formatDateID } from "./format";

/**
 * The ONE shared ranking rule (spec §2.6, §6, §7): overdue/today first, then
 * soonest needed-by, then longest-waiting as the tiebreaker. Used by the timeline
 * deadline boxes AND the approval queue (feature 4) — implement once, here.
 */
export function deadlineComparator<T>(
  getDate: (t: T) => string | Date,
  now: Date = new Date(),
  getCreated?: (t: T) => string | Date,
) {
  return (a: T, b: T): number => {
    const da = daysUntil(getDate(a), now);
    const db = daysUntil(getDate(b), now);
    const aUrgent = da <= 0 ? 0 : 1; // overdue/today pinned to the top
    const bUrgent = db <= 0 ? 0 : 1;
    if (aUrgent !== bUrgent) return aUrgent - bUrgent;
    if (da !== db) return da - db; // soonest needed-by
    if (getCreated) return +new Date(getCreated(a)) - +new Date(getCreated(b)); // longest waiting
    return 0;
  };
}

export function rankByDeadline<T>(
  items: T[],
  getDate: (t: T) => string | Date,
  now: Date = new Date(),
  getCreated?: (t: T) => string | Date,
): T[] {
  return items.slice().sort(deadlineComparator(getDate, now, getCreated));
}

/** Indonesian relative label for a deadline date, e.g. "hari ini", "dalam 3 hari". */
export function relativeDeadlineLabel(date: string | Date, now: Date = new Date()): { text: string; overdue: boolean } {
  const d = daysUntil(date, now);
  if (d < 0) return { text: `terlambat ${Math.abs(d)} hari`, overdue: true };
  if (d === 0) return { text: "hari ini", overdue: true };
  if (d === 1) return { text: "besok", overdue: false };
  return { text: `dalam ${d} hari`, overdue: false };
}

/** "8 Jun · hari ini" style line used in the ranked deadline boxes. */
export function deadlineLine(date: string | Date, now: Date = new Date()): { date: string; rel: string; overdue: boolean } {
  const { text, overdue } = relativeDeadlineLabel(date, now);
  return { date: formatDateID(date), rel: text, overdue };
}
