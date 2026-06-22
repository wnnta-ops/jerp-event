// Format remaining SLA time (in minutes) as days + hours, falling back to
// hours + minutes under a day. e.g. 25h -> "1d 1h", 1500 -> "1d 1h",
// 1080 -> "18h", 102 -> "1h 42m". null = no SLA (in chain).
import type { Deadline } from '../theme/tokens';

// Deadline bucket from minutes remaining: <=3 days red, 4-7 days amber, else none.
export function deadlineBucket(min: number | null): Deadline {
  if (min == null) return 'none';
  const days = min / 1440;
  if (days <= 3) return 'red';
  if (days <= 7) return 'amber';
  return 'none';
}

export function formatSla(min: number | null): string {
  if (min == null) return '—';
  if (min >= 1440) {
    const d = Math.floor(min / 1440);
    const h = Math.floor((min % 1440) / 60);
    return h > 0 ? `${d}d ${h}h` : `${d}d`;
  }
  if (min >= 60) {
    const h = Math.floor(min / 60);
    const m = min % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
  }
  return `${min}m`;
}
