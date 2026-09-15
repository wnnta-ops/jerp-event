import { prisma } from "./prisma";
import { monthKey, wibToday } from "./wib";
import { ANNUAL_TARGET_PERIOD } from "./constants";

// ---------------------------------------------------------------------------
// Reminder follow-up settings + live overdue list
// ---------------------------------------------------------------------------
export interface ReminderData {
  global: { days: number; notifyApp: boolean; notifyWa: boolean; active: boolean };
  overrides: { coordinatorId: string; days: number; active: boolean }[];
  coordinators: { id: string; name: string }[];
  overdue: { nama: string; koor: string; sales: string; cluster: string; days: number }[];
}

export async function getReminderData(): Promise<ReminderData> {
  const today = wibToday();
  const [rows, coordinators, leads] = await Promise.all([
    prisma.reminderSetting.findMany(),
    prisma.coordinator.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.deal.findMany({
      where: { batal: false, stage: { in: ["WI", "FOLLOW_UP", "NUP"] } },
      select: {
        nama: true,
        tanggalWi: true,
        coordinatorId: true,
        coordinator: { select: { name: true } },
        sales: { select: { name: true } },
        cluster: { select: { name: true } },
        followUps: { orderBy: { createdAt: "desc" }, take: 1, select: { createdAt: true } },
      },
    }),
  ]);

  const globalRow = rows.find((r) => !r.coordinatorId);
  const global = {
    days: globalRow?.days ?? 3,
    notifyApp: globalRow?.notifyApp ?? true,
    notifyWa: globalRow?.notifyWa ?? false,
    active: globalRow?.active ?? true,
  };
  const overrideMap = new Map(rows.filter((r) => r.coordinatorId).map((r) => [r.coordinatorId!, r]));
  const overrides = [...overrideMap.values()].map((r) => ({
    coordinatorId: r.coordinatorId!,
    days: r.days,
    active: r.active,
  }));

  const overdue = leads
    .map((d) => {
      const last = d.followUps[0]?.createdAt ?? d.tanggalWi;
      const days = Math.floor((today.getTime() - last.getTime()) / 86400_000);
      const threshold = d.coordinatorId
        ? overrideMap.get(d.coordinatorId)?.days ?? global.days
        : global.days;
      return { nama: d.nama, koor: d.coordinator?.name ?? "-", sales: d.sales.name, cluster: d.cluster?.name ?? "-", days, threshold };
    })
    .filter((x) => x.days >= x.threshold)
    .sort((a, b) => b.days - a.days)
    .slice(0, 100)
    .map(({ nama, koor, sales, cluster, days }) => ({ nama, koor, sales, cluster, days }));

  return { global, overrides, coordinators, overdue };
}

// ---------------------------------------------------------------------------
// Targets
// ---------------------------------------------------------------------------
export interface TargetData {
  monthKey: string;
  annualSpr: number;
  monthlyUnit: number;
  teams: { id: string; name: string; target: number }[];
}

export async function getTargetData(): Promise<TargetData> {
  const mk = monthKey(wibToday());
  const [annual, monthly, teams, teamTargets] = await Promise.all([
    prisma.target.findFirst({ where: { scope: "ANNUAL", metric: "SPR", period: ANNUAL_TARGET_PERIOD, teamId: null } }),
    prisma.target.findFirst({ where: { scope: "MONTHLY", metric: "UNIT", period: mk, teamId: null } }),
    prisma.team.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.target.findMany({ where: { scope: "TEAM_MONTH", metric: "UNIT", period: mk } }),
  ]);
  const tt = new Map(teamTargets.map((t) => [t.teamId, t.value]));
  return {
    monthKey: mk,
    annualSpr: annual?.value ?? 0,
    monthlyUnit: monthly?.value ?? 0,
    teams: teams.map((t) => ({ id: t.id, name: t.name, target: tt.get(t.id) ?? 0 })),
  };
}

// ---------------------------------------------------------------------------
