import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { getMatrixReport } from "@/lib/matrix";
import { MATRIX_INPUT_MODE } from "@/lib/matrix-def";
import { MatrixClient } from "@/components/matrix/MatrixClient";
import { can } from "@/lib/rbac";
import { dayKey, formatIdDateLong, parseDayKey, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

export default async function MatrixPage({
  searchParams,
}: {
  searchParams: Promise<{ date?: string }>;
}) {
  const session = (await getSession())!;
  const sp = await searchParams;
  const today = wibToday();
  const todayKey = dayKey(today);
  const day = sp.date ? parseDayKey(sp.date) : today;
  const curKey = dayKey(day);

  const [report, entries, recent] = await Promise.all([
    getMatrixReport(day),
    prisma.matrixEntry.findMany({ where: { dayKey: curKey } }),
    prisma.pipelineEvent.findMany({
      where: { eventDate: { lte: today } },
      distinct: ["eventDate"],
      orderBy: { eventDate: "desc" },
      take: 60,
      select: { eventDate: true },
    }),
  ]);

  const initialManual: Record<string, number> = {};
  for (const e of entries) initialManual[`${e.rowKey}::${e.colKey}`] = e.value;

  const seen = new Set<string>([todayKey, curKey]);
  const dates = [{ key: todayKey, label: `${formatIdDateLong(today)} (hari ini)` }];
  if (curKey !== todayKey) dates.push({ key: curKey, label: formatIdDateLong(day) });
  for (const r of recent) {
    const k = dayKey(r.eventDate);
    if (seen.has(k)) continue;
    seen.add(k);
    dates.push({ key: k, label: formatIdDateLong(r.eventDate) });
  }

  return (
    <>
      <div className="h2">
        Matrix Daily Sales Report <span className="ft">Export</span>
      </div>
      <div className="lead">
        Format matrix per cluster·phase (+ rekap 1 minggu). Angka otomatis dari data web;
        {MATRIX_INPUT_MODE === "collection" ? (
          <>
            {" "}angka manual (Cluster 1, Target, Proses) diisi tim <b>Collection → tab Input Matrix</b>.
          </>
        ) : (
          <> angka manual diisi langsung di sel putih.</>
        )}{" "}
        Export ke Excel atau Print/PDF.
      </div>
      <MatrixClient
        dayKey={report.dayKey}
        dateLabel={report.dateLabel}
        derived={report.derived}
        initialManual={initialManual}
        dates={dates}
        canEdit={can.inputSalesStages(session.user.role)}
        editable={MATRIX_INPUT_MODE === "inline"}
      />
    </>
  );
}
