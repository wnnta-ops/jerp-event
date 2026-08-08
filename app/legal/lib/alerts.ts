import { prisma } from "./prisma";
import { daysUntil } from "./format";
import { rankByDeadline, relativeDeadlineLabel } from "./ranking";
import { ROLE_LABELS, type Role } from "./enums";

/**
 * Deadline alerts (spec §6/§8): auto-notify the PIC at lead times (90/60/30 days)
 * for renewals, and escalate overdue items to leadership. Alerts are DERIVED at
 * read time from documents + access requests (always fresh, no cron), then
 * filtered to the acting user's relevance. The same set would be dispatched via
 * lib/notify.ts (in-app now, email/push later).
 */
export type AlertSeverity = "danger" | "warning" | "info";

export type Alert = {
  id: string;
  kind: "renewal" | "overdue" | "request";
  severity: AlertSeverity;
  title: string;
  detail: string;
  date: string; // ISO — the deadline
  href: string;
  escalation: boolean; // surfaced to leadership rather than the owner
};

const LEADERSHIP: Role[] = ["HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR"];

type ActingUser = { id: string; role: string } | null | undefined;

type DocLite = { id: string; title: string; expiryDate: Date | null; picId: string; pic: { name: string } };
type ReqLite = {
  id: string; code: string; neededBy: Date; requestedItem: string; document: { title: string } | null;
  steps: { order: number; role: string; approverId: string | null }[]; currentStep: number;
};

export function buildAlerts(docs: DocLite[], requests: ReqLite[], acting: ActingUser, now: Date): Alert[] {
  if (!acting) return [];
  const isLeadership = LEADERSHIP.includes(acting.role as Role);
  const out: Alert[] = [];

  // --- document renewals + overdue ---
  for (const d of docs) {
    if (!d.expiryDate) continue;
    const dd = daysUntil(d.expiryDate, now);
    const iso = d.expiryDate.toISOString();
    const isPic = d.picId === acting.id;

    if (dd < 0) {
      // overdue → owner sees it AND it escalates to leadership
      if (isPic || isLeadership) {
        out.push({
          id: `ov-${d.id}`,
          kind: "overdue",
          severity: "danger",
          title: `${d.title} — terlambat ${Math.abs(dd)} hari`,
          detail: isPic ? "Sudah lewat tenggat perpanjangan." : `Eskalasi: PIC ${d.pic.name} belum menindaklanjuti.`,
          date: iso,
          href: "/timeline",
          escalation: !isPic && isLeadership,
        });
      }
    } else if (dd <= 90 && isPic) {
      // lead-time buckets 90 / 60 / 30
      const lead = dd <= 30 ? 30 : dd <= 60 ? 60 : 90;
      out.push({
        id: `rn-${d.id}`,
        kind: "renewal",
        severity: dd <= 30 ? "warning" : "info",
        title: `${d.title} — perpanjangan dalam ${dd} hari`,
        detail: `Pengingat ${lead} hari · jatuh tempo ${relativeDeadlineLabel(iso, now).text}.`,
        date: iso,
        href: "/timeline",
        escalation: false,
      });
    }
  }

  // --- access requests waiting on the acting user (or overdue → escalate) ---
  for (const r of requests) {
    const cur = r.steps[r.currentStep];
    if (!cur) continue;
    const waitingOnMe = cur.approverId ? cur.approverId === acting.id : cur.role === acting.role;
    const nb = daysUntil(r.neededBy, now);
    const iso = r.neededBy.toISOString();

    if (waitingOnMe) {
      out.push({
        id: `rq-${r.id}`,
        kind: "request",
        severity: nb < 0 ? "danger" : nb <= 3 ? "warning" : "info",
        title: `Permintaan #${r.code} menunggu persetujuan Anda`,
        detail: `${r.requestedItem || r.document?.title || "Dokumen"} · butuh ${relativeDeadlineLabel(iso, now).text}.`,
        date: iso,
        href: `/requests/${r.id}`,
        escalation: false,
      });
    } else if (nb < 0 && isLeadership) {
      out.push({
        id: `rq-esc-${r.id}`,
        kind: "request",
        severity: "danger",
        title: `Permintaan #${r.code} terlambat — macet di ${ROLE_LABELS[cur.role as Role]?.toLowerCase() ?? cur.role}`,
        detail: `Eskalasi: ${r.requestedItem || r.document?.title || "Dokumen"} sudah lewat tenggat dan belum diputuskan.`,
        date: iso,
        href: `/requests/${r.id}`,
        escalation: true,
      });
    }
  }

  return rankByDeadline(out, (a) => a.date, now);
}

/** Server loader: query the inputs and build the acting user's alert list. */
export async function loadAlertsFor(acting: ActingUser): Promise<Alert[]> {
  if (!acting) return [];
  const [docs, requests] = await Promise.all([
    prisma.document.findMany({ where: { expiryDate: { not: null } }, include: { pic: { select: { name: true } } } }),
    prisma.accessRequest.findMany({
      where: { status: "pending" },
      include: { document: { select: { title: true } }, steps: { orderBy: { order: "asc" }, select: { order: true, role: true, approverId: true } } },
    }),
  ]);
  return buildAlerts(docs as DocLite[], requests as ReqLite[], acting, new Date());
}
