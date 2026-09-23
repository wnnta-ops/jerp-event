import Link from "next/link";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { CollectionClient, type CollDeal } from "@/components/collection/CollectionClient";
import { MatrixInputGrid } from "@/components/matrix/MatrixInputGrid";
import { getMatrixReport } from "@/lib/matrix";
import { MATRIX_INPUT_MODE } from "@/lib/matrix-def";
import { ROLE_LABEL } from "@/lib/constants";
import { dayKey, formatIdDateLong, parseDayKey, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

export default async function CollectionPage({
  searchParams,
}: {
  searchParams: Promise<{ tab?: string; date?: string }>;
}) {
  const session = (await getSession())!;
  const role = session.user.role;
  const sp = await searchParams;
  const showInputTab = MATRIX_INPUT_MODE === "collection";
  const tab = showInputTab && sp.tab === "input" ? "input" : "status";
  const isColl = role === "COLLECTION";
  const canInputMatrix = role === "COLLECTION" || role === "MANAGEMENT";

  return (
    <>
      <div className="h2">
        Collection <span className="ft">Fitur 7</span>
      </div>
      <div className="lead">
        Tempat kerja tim Collection: update progress akad &amp; input data matrix harian.
      </div>

      {showInputTab && (
        <div className="tabbar">
          <Link href="/collection" className={`tab-link ${tab === "status" ? "on" : ""}`}>
            🔄 Update Status
          </Link>
          <Link href="/collection?tab=input" className={`tab-link ${tab === "input" ? "on" : ""}`}>
            🧮 Input Matrix
          </Link>
        </div>
      )}

      {tab === "input" ? (
        <MatrixInputPanel dateParam={sp.date} canEdit={canInputMatrix} role={role} />
      ) : (
        <StatusPanel role={role} isColl={isColl} />
      )}
    </>
  );
}

async function StatusPanel({ role, isColl }: { role: import("@prisma/client").Role; isColl: boolean }) {
  const rows = await prisma.deal.findMany({
    where: { batal: false, stage: { in: ["UTJ", "SPR", "ACC", "AKAD"] } },
    orderBy: { updatedAt: "desc" },
    take: 400,
    select: {
      id: true,
      nama: true,
      stage: true,
      catatan: true,
      cluster: { select: { name: true } },
      unitType: { select: { name: true } },
      sales: { select: { name: true } },
      bank: { select: { name: true } },
      unit: { select: { code: true } },
    },
  });
  const deals: CollDeal[] = rows.map((d) => ({
    id: d.id,
    nama: d.nama,
    stage: d.stage,
    unit: d.unit?.code ?? null,
    cluster: d.cluster?.name ?? "-",
    type: d.unitType?.name ?? "-",
    sales: d.sales.name,
    bank: d.bank?.name ?? null,
    catatan: d.catatan,
  }));

  return (
    <>
      <div className={`notice ${isColl ? "info" : ""}`}>
        <span>{isColl ? "✏️" : "🔒"}</span>
        <div>
          {isColl ? (
            <>
              <b>Collection:</b> update ACC → Akad (AJB diisi Legal) — atau Batal + refund.
            </>
          ) : (
            <>
              <b>{ROLE_LABEL[role]}:</b> mode <b>lihat</b> — hanya tim Collection yang bisa mengubah
              status di sini.
            </>
          )}
        </div>
      </div>
      <CollectionClient deals={deals} role={role} />
    </>
  );
}

async function MatrixInputPanel({
  dateParam,
  canEdit,
  role,
}: {
  dateParam?: string;
  canEdit: boolean;
  role: import("@prisma/client").Role;
}) {
  const today = wibToday();
  const todayKey = dayKey(today);
  const day = dateParam ? parseDayKey(dateParam) : today;
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
      <div className={`notice ${canEdit ? "info" : ""}`}>
        <span>{canEdit ? "✏️" : "🔒"}</span>
        <div>
          {canEdit ? (
            <>
              <b>Input Matrix:</b> isi angka yang belum masuk dari sistem (Cluster 1, Target,
              Proses) — tersimpan otomatis per tanggal. Klik <b>👁 Preview</b> buat lihat hasilnya
              dalam format Matrix Report sebelum dipakai.
            </>
          ) : (
            <>
              <b>{ROLE_LABEL[role]}:</b> mode <b>lihat</b> — input matrix diisi tim Collection.
            </>
          )}
        </div>
      </div>
      <MatrixInputGrid
        dayKey={report.dayKey}
        dateLabel={report.dateLabel}
        derived={report.derived}
        initialManual={initialManual}
        dates={dates}
        canEdit={canEdit}
        dateBase="/collection?tab=input&date="
      />
    </>
  );
}
