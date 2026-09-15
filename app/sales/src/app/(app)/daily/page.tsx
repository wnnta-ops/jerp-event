import { prisma } from "@/lib/prisma";
import { DailyClient } from "@/components/report/DailyClient";
import { getSession } from "@/lib/auth";
import { can } from "@/lib/rbac";
import { dayKey, formatIdDateLong, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

export default async function DailyPage() {
  const session = (await getSession())!;
  const canEdit = can.inputSalesStages(session.user.role);
  const today = wibToday();
  const todayKey = dayKey(today);

  // Days that have events (for the per-day history picker), newest first.
  const recent = await prisma.pipelineEvent.findMany({
    where: { eventDate: { lte: today } },
    distinct: ["eventDate"],
    orderBy: { eventDate: "desc" },
    take: 60,
    select: { eventDate: true },
  });

  const keys = new Set<string>([todayKey]);
  const dates: { key: string; label: string }[] = [
    { key: todayKey, label: `${formatIdDateLong(today)} (hari ini)` },
  ];
  for (const r of recent) {
    const k = dayKey(r.eventDate);
    if (keys.has(k)) continue;
    keys.add(k);
    dates.push({ key: k, label: formatIdDateLong(r.eventDate) });
  }

  return (
    <>
      <div className="h2">
        Daily Report <span className="ft">Fitur 1</span>
      </div>
      <div className="lead">Auto dari input hari ini. Download gambar → kirim grup WA.</div>
      <DailyClient dates={dates} initial={todayKey} canEdit={canEdit} />
    </>
  );
}
