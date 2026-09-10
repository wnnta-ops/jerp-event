import { prisma } from "./prisma";
import { dayKey, monthKey, formatIdDateLong, parseDayKey } from "./wib";
import { MATRIX_COLS, MATRIX_ROWS, colOf, zeroCols, type ColMap } from "./matrix-def";

export { MATRIX_COLS, MATRIX_ROWS };
const zero = zeroCols;

export interface MatrixReport {
  dayKey: string;
  dateLabel: string;
  derived: Record<string, ColMap>; // rowKey -> col -> value (derived rows only)
}

export async function getMatrixReport(day: Date): Promise<MatrixReport> {
  const year = day.getUTCFullYear();
  const dKey = dayKey(day);
  const mKey = monthKey(day);

  const [units, events] = await Promise.all([
    prisma.unit.findMany({
      select: { status: true, phase: { select: { name: true, cluster: { select: { name: true } } } } },
    }),
    prisma.pipelineEvent.findMany({
      select: {
        type: true,
        eventDate: true,
        deal: {
          select: {
            caraBayar: true,
            cluster: { select: { name: true } },
            phase: { select: { name: true } },
          },
        },
      },
    }),
  ]);

  // accumulators
  const totalUnit = zero();
  const hold = zero();
  const sprYr: Record<number, ColMap> = {};
  const batalYr: Record<number, ColMap> = {};
  const realYr: Record<number, ColMap> = {};
  const realKprYr: Record<number, ColMap> = {};
  const realCashYr: Record<number, ColMap> = {};
  const wiDay = zero(), utjDay = zero(), nupDay = zero(), sprDay = zero();
  const wiMon = zero(), utjMon = zero(), nupMon = zero(), sprMon = zero();
  const wiWk = zero(), utjWk = zero(), batalWk = zero(), nupWk = zero(), realWk = zero();
  const dayTs = day.getTime();
  const weekStart = dayTs - 6 * 86400_000; // last 7 days incl. selected day
  const yr = (m: Record<number, ColMap>, y: number) => (m[y] ??= zero());

  for (const u of units) {
    const col = colOf(u.phase.cluster.name, u.phase.name);
    if (!col) continue;
    totalUnit[col]++;
    if (u.status === "HOLD") hold[col]++;
  }

  for (const e of events) {
    const col = colOf(e.deal.cluster?.name, e.deal.phase?.name);
    if (!col) continue;
    const y = e.eventDate.getUTCFullYear();
    if (e.type === "SPR") yr(sprYr, y)[col]++;
    else if (e.type === "BATAL") yr(batalYr, y)[col]++;
    else if (e.type === "REALISASI") {
      yr(realYr, y)[col]++;
      if (e.deal.caraBayar === "CASH") yr(realCashYr, y)[col]++;
      else yr(realKprYr, y)[col]++;
    }
    const isDay = dayKey(e.eventDate) === dKey;
    const isMon = monthKey(e.eventDate) === mKey;
    if (isDay || isMon) {
      const bump = (d: ColMap, m: ColMap) => {
        if (isDay) d[col]++;
        if (isMon) m[col]++;
      };
      if (e.type === "WI") bump(wiDay, wiMon);
      else if (e.type === "UTJ") bump(utjDay, utjMon);
      else if (e.type === "NUP") bump(nupDay, nupMon);
      else if (e.type === "SPR") bump(sprDay, sprMon);
    }
    const t = e.eventDate.getTime();
    if (t >= weekStart && t <= dayTs) {
      if (e.type === "WI") wiWk[col]++;
      else if (e.type === "UTJ") utjWk[col]++;
      else if (e.type === "BATAL") batalWk[col]++;
      else if (e.type === "NUP") nupWk[col]++;
      else if (e.type === "REALISASI") realWk[col]++;
    }
  }

  // net SPR (gross − batal) for a year, and cumulative up to a year
  const sprNet = (y: number): ColMap => {
    const s = sprYr[y] ?? zero();
    const b = batalYr[y] ?? zero();
    const out = zero();
    for (const k in out) out[k] = (s[k] ?? 0) - (b[k] ?? 0);
    return out;
  };
  const sprNetCum = (upto: number): ColMap => {
    const out = zero();
    for (const y of Object.keys(sprYr).map(Number)) if (y <= upto) {
      const n = sprNet(y);
      for (const k in out) out[k] += n[k];
    }
    // include years present only in batal
    for (const y of Object.keys(batalYr).map(Number)) if (y <= upto && !(y in sprYr)) {
      const n = sprNet(y);
      for (const k in out) out[k] += n[k];
    }
    return out;
  };
  const realCum = (upto: number): ColMap => {
    const out = zero();
    for (const y of Object.keys(realYr).map(Number)) if (y <= upto) {
      const r = realYr[y];
      for (const k in out) out[k] += r[k] ?? 0;
    }
    return out;
  };
  const sub = (a: ColMap, b: ColMap): ColMap => {
    const out = zero();
    for (const k in out) out[k] = (a[k] ?? 0) - (b[k] ?? 0);
    return out;
  };

  const sprNetSd2025 = sprNetCum(2025);
  const sprNet2026 = sprNet(2026);
  const stockAwal2026 = sub(totalUnit, sprNetSd2025);
  const sprNetSd2026 = sprNetCum(year >= 2026 ? 2026 : year);
  const sisaStock = sub(stockAwal2026, sprNet2026);

  const derived: Record<string, ColMap> = {
    TOTAL_UNIT: totalUnit,
    SPR_NET_2025: sprNet(2025),
    SPR_NET_SD_2025: sprNetSd2025,
    STOCK_AWAL_2026: stockAwal2026,
    SPR_NET_2026: sprNet2026,
    SPR_NET_SD_2026: sprNetSd2026,
    SISA_STOCK: sisaStock,
    WI_DAY: wiDay,
    UTJ_DAY: utjDay,
    NUP_DAY: nupDay,
    SPR_DAY: sprDay,
    WI_MONTH: wiMon,
    UTJ_MONTH: utjMon,
    NUP_MONTH: nupMon,
    SPR_MONTH: sprMon,
    HOLD: hold,
    COLL_SD_2025: realCum(2025),
    COLL_SD_2026: realCum(2026),
    COLL_2026: realYr[2026] ?? zero(),
    KPR_2026: realKprYr[2026] ?? zero(),
    CASH_2026: realCashYr[2026] ?? zero(),
    WI_WEEK: wiWk,
    UTJ_WEEK: utjWk,
    UTJBATAL_WEEK: batalWk,
    NUP_WEEK: nupWk,
    COLL_WEEK: realWk,
  };

  return { dayKey: dKey, dateLabel: formatIdDateLong(parseDayKey(dKey)), derived };
}
