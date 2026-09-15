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
  formatIdDateLong,
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
  // all figures are for the report DAY (per hari)
  utjGross: number;
  utjNett: number; // gross − gagal (day)
  utjGagal: number;
  sprGross: number;
  sprNett: number; // gross − gagal (day)
  sprGagal: number;
  nup: number;
  utjToday: { code: string; n: number }[]; // today's UTJ per cluster.phase (e.g. C2.1)
  waitingList: number; // current snapshot
  holdUnit: number; // current snapshot
}

// Roman phase → number, and "Cluster 2" → "2" for the C2.1 style code.
const ROMAN: Record<string, string> = { I: "1", II: "2", III: "3", IV: "4", V: "5" };
function clusterCode(name: string): string {
  const n = name.replace(/[^0-9]/g, "");
  return n ? `C${n}` : name;
}

export async function dailyRecap(day: Date): Promise<DailyRecap> {
  const { gte: dayGte, lt: dayLt } = dayBounds(day);

  const [wiEvents, utjEvents, utjGross, nup, sprGross, utjGagal, sprGagal, statuses, wlCount] =
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
      countEvents("UTJ", dayGte, dayLt),
      countEvents("NUP", dayGte, dayLt),
      countEvents("SPR", dayGte, dayLt),
      prisma.deal.count({
        where: { batal: true, batalKategori: "UTJ", batalTanggal: { gte: dayGte, lt: dayLt } },
      }),
      prisma.deal.count({
        where: { batal: true, batalKategori: "SPR", batalTanggal: { gte: dayGte, lt: dayLt } },
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
    utjGross,
    utjNett: utjGross - utjGagal,
    utjGagal,
    sprGross,
    sprNett: sprGross - sprGagal,
    sprGagal,
    nup,
    utjToday,
    waitingList: wlCount,
    holdUnit: statuses["HOLD"] ?? 0,
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
// Executive Summary (dashboard tab 2) — derived from the event ledger.
// Fields we can't derive yet (2025 history, per-koor targets, budget) are left
// empty and flagged so the UI can show "belum tersambung".
// ---------------------------------------------------------------------------
export interface ExecMonth {
  m: string;
  sprGross: number;
  sprBatal: number;
  sprNett: number;
  wi: number;
  utjNett: number;
}
export interface ExecSummaryData {
  monthLabels: string[];
  series: ExecMonth[];
  nettSprYtd: number;
  sprGrossYtd: number;
  batalYtd: number;
  batalRate: number;
  annualTarget: number;
  annualPct: number;
  remaining: number;
  monthLabel: string;
  monthUtjGross: number;
  monthUtjNett: number;
  monthTarget: number;
  monthPct: number;
  monthWi: number;
  convWiUtj: number;
  perKoor: { name: string; gross: number; batal: number; nett: number; target: number | null }[];
  perTipe: { type: string; n: number }[];
  topAgent: { name: string; n: number }[];
  hasKoorTarget: boolean;
}

export async function execSummaryData(day: Date, today: Date): Promise<ExecSummaryData> {
  const year = day.getUTCFullYear();
  const curMonth = year === today.getUTCFullYear() ? today.getUTCMonth() : 11;
  const { gte, lt } = yearBounds(year);
  const mb = monthBounds(year, curMonth);

  const [sprEv, wiEv, utjEv, batalDeals, annualTarget, monthTarget, utjMonthRows, batalMonthRows] =
    await Promise.all([
      prisma.pipelineEvent.findMany({ where: { type: "SPR", eventDate: { gte, lt } }, select: { eventDate: true } }),
      prisma.pipelineEvent.findMany({ where: { type: "WI", eventDate: { gte, lt } }, select: { eventDate: true } }),
      prisma.pipelineEvent.findMany({ where: { type: "UTJ", eventDate: { gte, lt } }, select: { eventDate: true } }),
      prisma.deal.findMany({
        where: { batal: true, batalTanggal: { gte, lt } },
        select: { batalTanggal: true, batalKategori: true },
      }),
      targetValue("ANNUAL", "SPR", ANNUAL_TARGET_PERIOD),
      targetValue("MONTHLY", "UNIT", monthKey(new Date(Date.UTC(year, curMonth, 1)))),
      prisma.pipelineEvent.findMany({
        where: { type: "UTJ", eventDate: { gte: mb.gte, lt: mb.lt } },
        select: {
          deal: {
            select: {
              coordinator: { select: { name: true } },
              agency: { select: { name: true } },
              unitType: { select: { name: true } },
            },
          },
        },
      }),
      prisma.deal.findMany({
        where: { batal: true, batalKategori: "UTJ", batalTanggal: { gte: mb.gte, lt: mb.lt } },
        select: { coordinator: { select: { name: true } } },
      }),
    ]);

  const bucket = (rows: { eventDate: Date }[]) => {
    const a = Array(12).fill(0) as number[];
    for (const r of rows) a[r.eventDate.getUTCMonth()]++;
    return a;
  };
  const sprB = bucket(sprEv);
  const wiB = bucket(wiEv);
  const utjB = bucket(utjEv);
  const sprBatalB = Array(12).fill(0) as number[];
  const utjBatalB = Array(12).fill(0) as number[];
  for (const d of batalDeals) {
    if (!d.batalTanggal) continue;
    const m = d.batalTanggal.getUTCMonth();
    if (d.batalKategori === "SPR") sprBatalB[m]++;
    else if (d.batalKategori === "UTJ") utjBatalB[m]++;
  }

  const series: ExecMonth[] = [];
  const monthLabels: string[] = [];
  let nettSprYtd = 0, sprGrossYtd = 0, batalYtd = 0;
  for (let m = 0; m <= curMonth; m++) {
    const sprNett = sprB[m] - sprBatalB[m];
    series.push({
      m: idMonthShort(m),
      sprGross: sprB[m],
      sprBatal: sprBatalB[m],
      sprNett,
      wi: wiB[m],
      utjNett: utjB[m] - utjBatalB[m],
    });
    monthLabels.push(idMonthShort(m));
    nettSprYtd += sprNett;
    sprGrossYtd += sprB[m];
    batalYtd += sprBatalB[m] + utjBatalB[m];
  }

  // current-month aggregates
  const koorMap = new Map<string, { gross: number; batal: number }>();
  const agentMap = new Map<string, number>();
  const tipeMap = new Map<string, number>();
  for (const r of utjMonthRows) {
    const kn = r.deal.coordinator?.name ?? "—";
    const km = koorMap.get(kn) ?? { gross: 0, batal: 0 };
    km.gross++;
    koorMap.set(kn, km);
    const an = r.deal.agency?.name ?? "—";
    agentMap.set(an, (agentMap.get(an) ?? 0) + 1);
    const tn = r.deal.unitType?.name ?? "—";
    tipeMap.set(tn, (tipeMap.get(tn) ?? 0) + 1);
  }
  for (const d of batalMonthRows) {
    const kn = d.coordinator?.name ?? "—";
    const km = koorMap.get(kn) ?? { gross: 0, batal: 0 };
    km.batal++;
    koorMap.set(kn, km);
  }
  const perKoor = [...koorMap.entries()]
    .map(([name, v]) => ({ name, gross: v.gross, batal: v.batal, nett: v.gross - v.batal, target: null as number | null }))
    .sort((a, b) => b.nett - a.nett);
  const perTipe = [...tipeMap.entries()].map(([type, n]) => ({ type, n })).sort((a, b) => b.n - a.n).slice(0, 6);
  const topAgent = [...agentMap.entries()].map(([name, n]) => ({ name, n })).sort((a, b) => b.n - a.n).slice(0, 5);

  const monthUtjGross = utjB[curMonth];
  const monthUtjNett = utjB[curMonth] - utjBatalB[curMonth];
  const monthWi = wiB[curMonth];

  return {
    monthLabels,
    series,
    nettSprYtd,
    sprGrossYtd,
    batalYtd,
    batalRate: sprGrossYtd ? Math.round((batalYtd / (sprGrossYtd || 1)) * 1000) / 10 : 0,
    annualTarget,
    annualPct: annualTarget ? Math.round((nettSprYtd / annualTarget) * 100) : 0,
    remaining: Math.max(annualTarget - nettSprYtd, 0),
    monthLabel: idMonthShort(curMonth),
    monthUtjGross,
    monthUtjNett,
    monthTarget,
    monthPct: monthTarget ? Math.round((monthUtjNett / monthTarget) * 100) : 0,
    monthWi,
    convWiUtj: monthWi ? Math.round((monthUtjNett / monthWi) * 1000) / 10 : 0,
    perKoor,
    perTipe,
    topAgent,
    hasKoorTarget: false,
  };
}

// ---------------------------------------------------------------------------
// Sales Dashboard (Daily Report — Format 2, approved table template).
// Values are manual (DailyDashboardEntry) with derived fallbacks; computed
// fields (remaining, %, min/day) are always derived from the resolved inputs.
// ---------------------------------------------------------------------------
export interface SalesDashboardInput {
  target2026: number;
  sprToday: number;
  targetUtj: number;
  utjNett: number;
  nupNett: number;
  holdUnit: number;
  waitingList: number;
  salesPotensial: number;
  wiHot: number;
  remainingDays: number;
}
export interface SalesDashboardData extends SalesDashboardInput {
  dayKey: string;
  dateLabel: string;
  monthName: string;
  remaining: number;
  sprPct: number;
  utjNupNett: number;
  utjNupPct: number;
  minSalesDay: number;
  hasEntry: boolean;
}

// Derived suggestions (used as defaults for the input form & as fallbacks).
export async function salesDashboardDerived(day: Date): Promise<SalesDashboardInput> {
  const year = day.getUTCFullYear();
  const month0 = day.getUTCMonth();
  const { gte, lt } = monthBounds(year, month0);
  const daysInMonth = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
  const [target2026, targetUtj, spr, utjGross, utjBatal, nup, statuses, waiting, hot, wiHot] =
    await Promise.all([
      targetValue("ANNUAL", "SPR", ANNUAL_TARGET_PERIOD),
      targetValue("MONTHLY", "UNIT", monthKey(new Date(Date.UTC(year, month0, 1)))),
      sprYtd(year),
      countEvents("UTJ", gte, lt),
      prisma.deal.count({ where: { batal: true, batalKategori: "UTJ", batalTanggal: { gte, lt } } }),
      countEvents("NUP", gte, lt),
      statusCounts(),
      prisma.deal.count({ where: { waitingList: true, batal: false } }),
      prisma.deal.count({ where: { statusLead: "HOT", batal: false } }),
      prisma.deal.count({ where: { statusLead: "HOT", batal: false, stage: { in: ["WI", "FOLLOW_UP"] } } }),
    ]);
  return {
    target2026,
    sprToday: spr,
    targetUtj,
    utjNett: utjGross - utjBatal,
    nupNett: nup,
    holdUnit: statuses["HOLD"] ?? 0,
    waitingList: waiting,
    salesPotensial: hot,
    wiHot,
    remainingDays: Math.max(daysInMonth - day.getUTCDate(), 0),
  };
}

export async function salesDashboardData(day: Date): Promise<SalesDashboardData> {
  const dk = `${day.getUTCFullYear()}-${String(day.getUTCMonth() + 1).padStart(2, "0")}-${String(day.getUTCDate()).padStart(2, "0")}`;
  const [derived, entry] = await Promise.all([
    salesDashboardDerived(day),
    prisma.dailyDashboardEntry.findUnique({ where: { dayKey: dk } }),
  ]);
  const pick = (k: keyof SalesDashboardInput) =>
    entry && entry[k] != null ? (entry[k] as number) : derived[k];
  const r: SalesDashboardInput = {
    target2026: pick("target2026"),
    sprToday: pick("sprToday"),
    targetUtj: pick("targetUtj"),
    utjNett: pick("utjNett"),
    nupNett: pick("nupNett"),
    holdUnit: pick("holdUnit"),
    waitingList: pick("waitingList"),
    salesPotensial: pick("salesPotensial"),
    wiHot: pick("wiHot"),
    remainingDays: pick("remainingDays"),
  };
  const utjNupNett = r.utjNett + r.nupNett;
  return {
    ...r,
    dayKey: dk,
    dateLabel: formatIdDateLong(day),
    monthName: idMonthShort(day.getUTCMonth()),
    remaining: r.target2026 - r.sprToday,
    sprPct: r.target2026 ? Math.round((r.sprToday / r.target2026) * 100) : 0,
    utjNupNett,
    utjNupPct: r.targetUtj ? Math.round((utjNupNett / r.targetUtj) * 100) : 0,
    minSalesDay: r.remainingDays > 0 ? Math.ceil((r.targetUtj - utjNupNett) / r.remainingDays) : 0,
    hasEntry: !!entry,
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
