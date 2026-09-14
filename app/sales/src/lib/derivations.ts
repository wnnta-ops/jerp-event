import type { Prisma } from "@prisma/client";
import { prisma } from "./prisma";
import { ANNUAL_TARGET_PERIOD } from "./constants";
import {
  dayBounds,
  monthBounds,
  monthKey,
  weekOfMonth,
  yearBounds,
  idMonthShort,
} from "./wib";

// ---------------------------------------------------------------------------
// Rotation lookup — koordinator is NEVER typed; resolved from bendera via the
// effective-dated rotation table at the deal's date.
// ---------------------------------------------------------------------------
export async function resolveCoordinatorId(
  agencyId: string,
  at: Date,
): Promise<string | null> {
  const r = await prisma.rotationAssignment.findFirst({
    where: {
      agencyId,
      effectiveFrom: { lte: at },
      OR: [{ effectiveTo: null }, { effectiveTo: { gte: at } }],
    },
    orderBy: { effectiveFrom: "desc" },
    select: { coordinatorId: true },
  });
  return r?.coordinatorId ?? null;
}

// ---------------------------------------------------------------------------
// Event counts
// ---------------------------------------------------------------------------
async function countEvents(
  type: Prisma.EnumEventTypeFilter | string,
  gte: Date,
  lt: Date,
): Promise<number> {
  return prisma.pipelineEvent.count({
    where: { type: type as never, eventDate: { gte, lt } },
  });
}

export interface DailyTally {
  wi: number;
  nup: number;
  utj: number;
  spr: number;
  batal: number;
}

export async function dailyTally(day: Date): Promise<DailyTally> {
  const { gte, lt } = dayBounds(day);
  const [wi, nup, utj, spr, batal] = await Promise.all([
    countEvents("WI", gte, lt),
    countEvents("NUP", gte, lt),
    countEvents("UTJ", gte, lt),
    countEvents("SPR", gte, lt),
    countEvents("BATAL", gte, lt),
  ]);
  return { wi, nup, utj, spr, batal };
}

// ---------------------------------------------------------------------------
// Targets
// ---------------------------------------------------------------------------
async function targetValue(
  scope: string,
  metric: string,
  period: string,
  teamId: string | null = null,
): Promise<number> {
  const t = await prisma.target.findFirst({
    where: { scope: scope as never, metric: metric as never, period, teamId },
    select: { value: true },
  });
  return t?.value ?? 0;
}

// ---------------------------------------------------------------------------
// Monthly UTJ net (gross UTJ − batal) for a WIB month
// ---------------------------------------------------------------------------
export async function monthlyNet(year: number, month0: number): Promise<number> {
  const { gte, lt } = monthBounds(year, month0);
  const [utj, batal] = await Promise.all([
    countEvents("UTJ", gte, lt),
    countEvents("BATAL", gte, lt),
  ]);
  return utj - batal;
}

// SPR cumulative to date (YTD) for a year — used for the annual 798 target.
export async function sprYtd(year: number): Promise<number> {
  const { gte, lt } = yearBounds(year);
  return countEvents("SPR", gte, lt);
}

// ---------------------------------------------------------------------------
// Per-team UTJ net for a month
// ---------------------------------------------------------------------------
export interface TeamNet {
  teamId: string | null;
  tim: string;
  target: number;
  utj: number;
  batal: number;
  net: number;
}

export async function teamNet(year: number, month0: number): Promise<TeamNet[]> {
  const { gte, lt } = monthBounds(year, month0);
  const period = monthKey(new Date(Date.UTC(year, month0, 1)));

  const events = await prisma.pipelineEvent.findMany({
    where: { type: { in: ["UTJ", "BATAL"] }, eventDate: { gte, lt } },
    select: { type: true, deal: { select: { sales: { select: { team: true } } } } },
  });

  const teams = await prisma.team.findMany({ orderBy: { name: "asc" } });
  const buckets = new Map<string, TeamNet>();
  for (const t of teams) {
    buckets.set(t.id, {
      teamId: t.id,
      tim: t.name,
      target: await targetValue("TEAM_MONTH", "UNIT", period, t.id),
      utj: 0,
      batal: 0,
      net: 0,
    });
  }
  const noTeam: TeamNet = { teamId: null, tim: "Tanpa Tim", target: 0, utj: 0, batal: 0, net: 0 };

  for (const e of events) {
    const team = e.deal.sales?.team ?? null;
    const b = team ? buckets.get(team.id)! : noTeam;
    if (e.type === "UTJ") b.utj++;
    else if (e.type === "BATAL") b.batal++;
  }
  const out = [...buckets.values()];
  if (noTeam.utj || noTeam.batal) out.push(noTeam);
  for (const b of out) b.net = b.utj - b.batal;
  return out;
}

// ---------------------------------------------------------------------------
// UTJ per type for a month (horizontal bars)
// ---------------------------------------------------------------------------
export interface TypeNet {
  type: string;
  n: number;
}

export async function utjByType(year: number, month0: number): Promise<TypeNet[]> {
  const { gte, lt } = monthBounds(year, month0);
  const events = await prisma.pipelineEvent.findMany({
    where: { type: "UTJ", eventDate: { gte, lt } },
    select: { deal: { select: { unitType: { select: { name: true } } } } },
  });
  const map = new Map<string, number>();
  for (const e of events) {
    const name = e.deal.unitType?.name ?? "Lainnya";
    map.set(name, (map.get(name) ?? 0) + 1);
  }
  return [...map.entries()]
    .map(([type, n]) => ({ type, n }))
    .sort((a, b) => b.n - a.n)
    .slice(0, 8);
}

// ---------------------------------------------------------------------------
// Weekly UTJ net trend (W1..W5) within a month
// ---------------------------------------------------------------------------
export async function weeklyTrend(year: number, month0: number): Promise<number[]> {
  const { gte, lt } = monthBounds(year, month0);
  const events = await prisma.pipelineEvent.findMany({
    where: { type: { in: ["UTJ", "BATAL"] }, eventDate: { gte, lt } },
    select: { type: true, eventDate: true },
  });
  const weeks = [0, 0, 0, 0, 0];
  for (const e of events) {
    const w = Math.min(weekOfMonth(e.eventDate), 5) - 1;
    weeks[w] += e.type === "UTJ" ? 1 : -1;
  }
  return weeks;
}

// ---------------------------------------------------------------------------
// Stock aggregations — sisa stock per cluster / per cluster×phase
// ---------------------------------------------------------------------------
export interface ClusterSisa {
  cluster: string;
  total: number;
  sold: number;
  sisa: number;
}

export async function clusterSisa(): Promise<ClusterSisa[]> {
  const units = await prisma.unit.findMany({
    select: { status: true, phase: { select: { cluster: { select: { name: true } } } } },
  });
  const map = new Map<string, { total: number; sisa: number; sold: number }>();
  for (const u of units) {
    const c = u.phase.cluster.name;
    const rec = map.get(c) ?? { total: 0, sisa: 0, sold: 0 };
    rec.total++;
    // sisa = not (realisasi + cash_lunas + proses + hold)
    if (["REALISASI", "CASH_LUNAS", "PROSES", "HOLD"].includes(u.status)) rec.sold++;
    else rec.sisa++;
    map.set(c, rec);
  }
  return [...map.entries()]
    .map(([cluster, r]) => ({ cluster, total: r.total, sold: r.sold, sisa: r.sisa }))
    .sort((a, b) => a.cluster.localeCompare(b.cluster));
}

export interface PhaseSummary {
  cluster: string;
  phase: string;
  total: number;
  proses: number;
  sisa: number;
}

export async function phaseSummary(): Promise<PhaseSummary[]> {
  const units = await prisma.unit.findMany({
    select: {
      status: true,
      phase: { select: { name: true, cluster: { select: { name: true } } } },
    },
  });
  const map = new Map<string, PhaseSummary>();
  for (const u of units) {
    const cluster = u.phase.cluster.name;
    const phase = u.phase.name;
    const key = `${cluster}|${phase}`;
    const rec = map.get(key) ?? { cluster, phase, total: 0, proses: 0, sisa: 0 };
    rec.total++;
    if (u.status === "PROSES") rec.proses++;
    if (!["REALISASI", "CASH_LUNAS", "PROSES", "HOLD"].includes(u.status)) rec.sisa++;
    map.set(key, rec);
  }
  return [...map.values()].sort(
    (a, b) => a.cluster.localeCompare(b.cluster) || a.phase.localeCompare(b.phase),
  );
}

export async function statusCounts(): Promise<Record<string, number>> {
  const grouped = await prisma.unit.groupBy({ by: ["status"], _count: { _all: true } });
  const out: Record<string, number> = {};
  for (const g of grouped) out[g.status] = g._count._all;
  return out;
}

// ---------------------------------------------------------------------------
// Working-day helpers for run-rate
// ---------------------------------------------------------------------------
function weekdaysBetween(from: Date, to: Date): number {
  let count = 0;
  const d = new Date(from.getTime());
  while (d < to) {
    const day = d.getUTCDay();
    if (day !== 0 && day !== 6) count++;
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return count;
}

// ---------------------------------------------------------------------------
// Dashboard KPIs
// ---------------------------------------------------------------------------
export interface DashboardKpis {
  annualTarget: number;
  sprYtd: number;
  annualPct: number;
  monthNet: number;
  monthTarget: number;
  monthPct: number;
  runRate: number;
  remainingAnnual: number;
  sisaStockTotal: number;
  clusterSisa: ClusterSisa[];
  hold: number;
  waitingList: number;
  salesPotensial: number;
  sisaHari: number;
}

export async function dashboardKpis(today: Date): Promise<DashboardKpis> {
  const year = today.getUTCFullYear();
  const month0 = today.getUTCMonth();

  const [annualTarget, monthTarget, spr, mNet, cSisa, statuses, hot, wl] = await Promise.all([
    targetValue("ANNUAL", "SPR", ANNUAL_TARGET_PERIOD),
    targetValue("MONTHLY", "UNIT", monthKey(today)),
    sprYtd(year),
    monthlyNet(year, month0),
    clusterSisa(),
    statusCounts(),
    prisma.deal.count({ where: { statusLead: "HOT", batal: false } }),
    prisma.deal.count({ where: { stage: "UTJ", unitId: null, batal: false } }),
  ]);

  const remainingAnnual = Math.max(annualTarget - spr, 0);
  const endOfYear = new Date(Date.UTC(year + 1, 0, 1));
  const workingLeft = Math.max(weekdaysBetween(today, endOfYear), 1);
  const runRate = Math.round((remainingAnnual / workingLeft) * 10) / 10;

  const sisaStockTotal = cSisa.reduce((s, c) => s + c.sisa, 0);
  const daysInMonth = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
  const sisaHari = daysInMonth - today.getUTCDate();

  return {
    annualTarget,
    sprYtd: spr,
    annualPct: annualTarget ? Math.round((spr / annualTarget) * 100) : 0,
    monthNet: mNet,
    monthTarget,
    monthPct: monthTarget ? Math.round((mNet / monthTarget) * 100) : 0,
    runRate,
    remainingAnnual,
    sisaStockTotal,
    clusterSisa: cSisa,
    hold: statuses["HOLD"] ?? 0,
    waitingList: wl,
    salesPotensial: hot,
    sisaHari,
  };
}

// ---------------------------------------------------------------------------
// Full Daily Report payload (drives the PNG + preview)
// ---------------------------------------------------------------------------
export interface DailyRecap {
  wiByCluster: { cluster: string; total: number; murni: number; janjian: number }[];
  utjNett: number; // month-to-date: UTJ gross − batal UTJ
  utjToday: { code: string; n: number }[]; // today's UTJ per cluster.phase (e.g. C2.1)
  utjBatal: number; // month-to-date batal kategori UTJ
  waitingList: number; // current snapshot
  holdUnit: number; // current snapshot
  sprNett: number; // month-to-date: SPR gross − batal SPR
  sprBatal: number; // month-to-date batal kategori SPR
  nupTotal: number; // month-to-date NUP
}

// Roman phase → number, and "Cluster 2" → "2" for the C2.1 style code.
const ROMAN: Record<string, string> = { I: "1", II: "2", III: "3", IV: "4", V: "5" };
function clusterCode(name: string): string {
  const n = name.replace(/[^0-9]/g, "");
  return n ? `C${n}` : name;
}

export async function dailyRecap(day: Date): Promise<DailyRecap> {
  const { gte: dayGte, lt: dayLt } = dayBounds(day);
  const monthGte = monthBounds(day.getUTCFullYear(), day.getUTCMonth()).gte;

  const [wiEvents, utjEvents, utjGross, nupMtd, sprGross, batalUtj, batalSpr, statuses, wlCount] =
    await Promise.all([
      prisma.pipelineEvent.findMany({
        where: { type: "WI", eventDate: { gte: dayGte, lt: dayLt } },
        select: { deal: { select: { channel: true, cluster: { select: { name: true } } } } },
      }),
      prisma.pipelineEvent.findMany({
        where: { type: "UTJ", eventDate: { gte: dayGte, lt: dayLt } },
        select: {
          deal: {
            select: { cluster: { select: { name: true } }, phase: { select: { name: true } } },
          },
        },
      }),
      countEvents("UTJ", monthGte, dayLt),
      countEvents("NUP", monthGte, dayLt),
      countEvents("SPR", monthGte, dayLt),
      prisma.deal.count({
        where: { batal: true, batalKategori: "UTJ", batalTanggal: { gte: monthGte, lt: dayLt } },
      }),
      prisma.deal.count({
        where: { batal: true, batalKategori: "SPR", batalTanggal: { gte: monthGte, lt: dayLt } },
      }),
      statusCounts(),
      prisma.deal.count({ where: { waitingList: true, batal: false } }),
    ]);

  // WI per cluster with murni (non-janjian) vs janjian breakdown
  const wiMap = new Map<string, { total: number; murni: number; janjian: number }>();
  for (const e of wiEvents) {
    const c = e.deal.cluster?.name ?? "Lainnya";
    const row = wiMap.get(c) ?? { total: 0, murni: 0, janjian: 0 };
    row.total++;
    if (e.deal.channel === "JANJIAN") row.janjian++;
    else row.murni++;
    wiMap.set(c, row);
  }
  const wiByCluster = [...wiMap.entries()]
    .map(([cluster, v]) => ({ cluster, ...v }))
    .sort((a, b) => a.cluster.localeCompare(b.cluster));

  // today's UTJ per cluster.phase
  const utjMap = new Map<string, number>();
  for (const e of utjEvents) {
    const cn = e.deal.cluster?.name;
    const pn = e.deal.phase?.name;
    const code = cn ? (pn ? `${clusterCode(cn)}.${ROMAN[pn] ?? pn}` : clusterCode(cn)) : "?";
    utjMap.set(code, (utjMap.get(code) ?? 0) + 1);
  }
  const utjToday = [...utjMap.entries()]
    .map(([code, n]) => ({ code, n }))
    .sort((a, b) => a.code.localeCompare(b.code));

  return {
    wiByCluster,
    utjNett: utjGross - batalUtj,
    utjToday,
    utjBatal: batalUtj,
    waitingList: wlCount,
    holdUnit: statuses["HOLD"] ?? 0,
    sprNett: sprGross - batalSpr,
    sprBatal: batalSpr,
    nupTotal: nupMtd,
  };
}

export interface DailyReportData {
  dayKey: string;
  isArchive: boolean;
  tally: DailyTally;
  recap: DailyRecap;
  monthNet: number;
  monthTarget: number;
  monthPct: number;
  annualTarget: number;
  sprYtd: number;
  annualPct: number;
  teams: TeamNet[];
  weekly: number[];
  hold: number;
  waitingList: number;
  salesPotensial: number;
}

export async function dailyReportData(
  day: Date,
  today: Date,
): Promise<DailyReportData> {
  const year = day.getUTCFullYear();
  const month0 = day.getUTCMonth();
  const [tally, recap, mNet, monthTarget, annualTarget, spr, teams, weekly, statuses, hot, wl] =
    await Promise.all([
      dailyTally(day),
      dailyRecap(day),
      monthlyNet(year, month0),
      targetValue("MONTHLY", "UNIT", monthKey(day)),
      targetValue("ANNUAL", "SPR", ANNUAL_TARGET_PERIOD),
      sprYtd(year),
      teamNet(year, month0),
      weeklyTrend(year, month0),
      statusCounts(),
      prisma.deal.count({ where: { statusLead: "HOT", batal: false } }),
      prisma.deal.count({ where: { stage: "UTJ", unitId: null, batal: false } }),
    ]);

  return {
    dayKey: `${year}-${String(month0 + 1).padStart(2, "0")}-${String(day.getUTCDate()).padStart(2, "0")}`,
    isArchive: day.getTime() < today.getTime(),
    tally,
    recap,
    monthNet: mNet,
    monthTarget,
    monthPct: monthTarget ? Math.round((mNet / monthTarget) * 100) : 0,
    annualTarget,
    sprYtd: spr,
    annualPct: annualTarget ? Math.round((spr / annualTarget) * 100) : 0,
    teams,
    weekly,
    hold: statuses["HOLD"] ?? 0,
    waitingList: wl,
    salesPotensial: hot,
  };
}

// ---------------------------------------------------------------------------
// Monthly / yearly series for the Monthly report
// ---------------------------------------------------------------------------
export async function monthlySeries(year: number): Promise<{ b: string; net: number }[]> {
  const out: { b: string; net: number }[] = [];
  for (let m = 0; m < 12; m++) {
    out.push({ b: idMonthShort(m), net: await monthlyNet(year, m) });
  }
  return out;
}

export async function yearlySeries(years: number[]): Promise<{ b: string; net: number }[]> {
  const out: { b: string; net: number }[] = [];
  for (const y of years) {
    const { gte, lt } = yearBounds(y);
    const [utj, batal] = await Promise.all([
      countEvents("UTJ", gte, lt),
      countEvents("BATAL", gte, lt),
    ]);
    out.push({ b: String(y), net: utj - batal });
  }
  return out;
}
