import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { parseMonths } from "@/lib/timeline";
import { TimelineBoard, type SectionView } from "@/components/timeline-board";

export const dynamic = "force-dynamic";

export default async function TimelinePage() {
  const [sections, acting] = await Promise.all([
    prisma.timelineSection.findMany({
      orderBy: { order: "asc" },
      include: {
        entries: {
          orderBy: { order: "asc" },
          include: {
            checkpoints: { orderBy: { order: "asc" }, include: { doneBy: { select: { name: true } } } },
            moves: { orderBy: { createdAt: "desc" }, include: { by: { select: { name: true } } } },
          },
        },
      },
    }),
    getActingUser(),
  ]);

  const data: SectionView[] = sections.map((s) => ({
    id: s.id,
    title: s.title,
    cluster: s.cluster,
    productType: s.productType,
    range: s.range,
    months: parseMonths(s.months),
    entries: s.entries.map((e) => ({
      id: e.id,
      name: e.name,
      badan: e.badan,
      jenisDokumen: e.jenisDokumen,
      status: e.status,
      durasi: e.durasi,
      progressMonth: e.progressMonth,
      targetMonth: e.targetMonth,
      doneCount: e.checkpoints.filter((c) => c.done).length,
      checkpoints: e.checkpoints.map((c) => ({
        id: c.id,
        order: c.order,
        step: c.step,
        done: c.done,
        doneByName: c.doneBy?.name ?? null,
        doneAt: c.doneAt ? c.doneAt.toISOString() : null,
        note: c.note,
        proofName: c.proofName,
      })),
      moves: e.moves.map((mv) => ({
        id: mv.id,
        fromMonth: mv.fromMonth,
        toMonth: mv.toMonth,
        byName: mv.by?.name ?? null,
        createdAt: mv.createdAt.toISOString(),
      })),
    })),
  }));

  return <TimelineBoard sections={data} canEdit={!!acting} nowISO={new Date().toISOString()} />;
}
