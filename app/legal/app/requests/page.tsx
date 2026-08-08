import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { RequestsQueue, type QueueItem } from "@/components/requests-queue";

export const dynamic = "force-dynamic";

export default async function RequestsPage() {
  const [requests, acting] = await Promise.all([
    prisma.accessRequest.findMany({
      orderBy: { createdAt: "desc" },
      include: {
        document: { select: { title: true } },
        requester: { select: { name: true } },
        steps: { orderBy: { order: "asc" }, include: { approver: { select: { id: true, name: true } } } },
      },
    }),
    getActingUser(),
  ]);

  const items: QueueItem[] = requests.map((r) => {
    const cur = r.steps[r.currentStep];
    const waitingOnMe = r.status === "pending" && !!cur && (cur.approverId ? cur.approverId === acting?.id : cur.role === acting?.role);
    return {
      id: r.id,
      code: r.code,
      docTitle: r.requestedItem || r.document?.title || "—",
      requesterName: r.requester.name,
      dept: r.dept,
      accessType: r.accessType,
      status: r.status,
      neededBy: r.neededBy.toISOString(),
      createdAt: r.createdAt.toISOString(),
      currentStep: r.currentStep,
      totalSteps: r.steps.length,
      currentRole: cur?.role ?? null,
      currentApproverName: cur?.approver?.name ?? null,
      currentNotifiedAt: cur?.notifiedAt?.toISOString() ?? null,
      waitingOnMe,
    };
  });

  return (
    <div className="mx-auto max-w-[1040px]">
      <RequestsQueue
        items={items}
        hasActingRole={!!acting?.role}
        canCreate={!!acting && acting.role !== "DIRECTOR"}
      />
    </div>
  );
}
