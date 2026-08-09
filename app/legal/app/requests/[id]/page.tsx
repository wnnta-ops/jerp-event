import { notFound } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { initialsOf } from "@/lib/utils";
import { RequestDetail, type RequestView } from "@/components/request-detail";

export const dynamic = "force-dynamic";

export default async function RequestDetailPage({ params }: { params: { id: string } }) {
  const [req, acting] = await Promise.all([
    prisma.accessRequest.findUnique({
      where: { id: params.id },
      include: {
        document: true,
        requester: true,
        steps: { orderBy: { order: "asc" }, include: { approver: true } },
        audit: { orderBy: { createdAt: "asc" }, include: { actor: true } },
      },
    }),
    getActingUser(),
  ]);

  if (!req) notFound();

  const fulfiller = req.fulfilledById
    ? await prisma.user.findUnique({ where: { id: req.fulfilledById }, select: { name: true } })
    : null;

  // Visibility: approvers (Legal + leadership) always; requester only once released (approved).
  const APPROVER_ROLES = ["SYSADMIN", "LEGAL_PIC", "HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR"];
  const isApprover = acting ? APPROVER_ROLES.includes(acting.role) : false;
  const isRequesterReleased = acting?.id === req.requesterId && req.status === "approved";
  const canSeeDoc = !!req.fulfilledFileUrl && (isApprover || isRequesterReleased);
  const docUrl = canSeeDoc ? `/api/request-doc?id=${req.id}` : null;

  const view: RequestView = {
    id: req.id,
    code: req.code,
    status: req.status,
    currentStep: req.currentStep,
    docTitle: req.requestedItem || req.document?.title || "—",
    requestedType: req.requestedType,
    requesterName: req.requester.name,
    dept: req.dept,
    accessType: req.accessType,
    purpose: req.purpose,
    neededBy: req.neededBy.toISOString(),
    hasDoc: !!req.fulfilledFileUrl,
    canSeeDoc,
    docUrl,
    docFileName: req.fulfilledFileName,
    docNote: req.fulfilledNote,
    docByName: fulfiller?.name ?? null,
    docAt: req.fulfilledAt?.toISOString() ?? null,
    steps: req.steps.map((s) => ({
      id: s.id,
      order: s.order,
      role: s.role,
      approverId: s.approverId,
      approverName: s.approver?.name ?? null,
      approverInitials: s.approver ? s.approver.initials || initialsOf(s.approver.name) : null,
      decision: s.decision,
      decidedAt: s.decidedAt?.toISOString() ?? null,
      note: s.note,
      notifiedAt: s.notifiedAt?.toISOString() ?? null,
    })),
    audit: req.audit.map((a) => ({
      id: a.id,
      action: a.action,
      actorName: a.actor?.name ?? null,
      detail: a.detail,
      createdAt: a.createdAt.toISOString(),
    })),
  };

  return (
    <div className="mx-auto max-w-[900px]">
      <RequestDetail req={view} actingId={acting?.id ?? ""} actingRole={acting?.role ?? ""} />
    </div>
  );
}
