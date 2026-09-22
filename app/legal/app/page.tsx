import { prisma } from "@/lib/prisma";
import { initialsOf } from "@/lib/utils";
import { getActingUser } from "@/lib/current-user";
import { DirectoryTable, type DocRow } from "@/components/directory-table";
import type { DocStatus, DocType } from "@/lib/enums";

export const dynamic = "force-dynamic";

export default async function HomePage() {
  const [docs, acting] = await Promise.all([
    prisma.document.findMany({
      orderBy: { uploadedAt: "desc" },
      include: {
        pic: true,
        uploadedBy: true,
        lastEditedBy: { select: { name: true } },
        files: { orderBy: { uploadedAt: "asc" } },
        relationsFrom: { select: { toId: true } },
        relationsTo: { select: { fromId: true } },
        events: { orderBy: { createdAt: "desc" }, take: 30, include: { actor: { select: { name: true } } } },
      },
    }),
    getActingUser(),
  ]);
  const canEdit = !!acting; // any logged-in user can edit/update/delete (legal-team note)

  const rows: DocRow[] = docs.map((d) => ({
    id: d.id,
    docType: d.docType as DocType,
    title: d.title,
    docNumber: d.docNumber,
    scope: d.scope,
    cluster: d.cluster,
    blok: d.blok,
    unitNo: d.unitNo,
    buyerName: d.buyerName,
    entityName: d.entityName,
    status: d.status as DocStatus,
    issueDate: d.issueDate ? d.issueDate.toISOString() : null,
    expiryDate: d.expiryDate ? d.expiryDate.toISOString() : null,
    originalLocation: d.originalLocation,
    originalLocationDetail: d.originalLocationDetail,
    version: d.version,
    isCurrentApproved: d.isCurrentApproved,
    accessLevel: d.accessLevel,
    objectLocation: d.objectLocation,
    landArea: d.landArea,
    notes: d.notes,
    description: d.description,
    boardStructure: d.boardStructure,
    authorizedCapital: d.authorizedCapital,
    issuedCapital: d.issuedCapital,
    shareholders: d.shareholders,
    parties: d.parties,
    reminderDate: d.reminderDate ? d.reminderDate.toISOString() : null,
    reminderNote: d.reminderNote,
    sprNumber: d.sprNumber,
    notaryName: d.notaryName,
    ajbValue: d.ajbValue,
    sprSyncedAt: d.sprSyncedAt ? d.sprSyncedAt.toISOString() : null,
    sprSyncError: d.sprSyncError,
    fileCount: d.files.length,
    files: d.files.map((f) => ({ url: f.url, fileName: f.fileName, mimeType: f.mimeType })),
    relatedIds: Array.from(
      new Set([...d.relationsFrom.map((r) => r.toId), ...d.relationsTo.map((r) => r.fromId)]),
    ),
    picName: d.pic.name,
    picInitials: d.pic.initials || initialsOf(d.pic.name),
    uploaderName: d.uploadedBy.name,
    uploadedAt: d.uploadedAt.toISOString(),
    updatedAt: d.updatedAt.toISOString(),
    lastEditedByName: d.lastEditedBy?.name ?? null,
    events: d.events.map((e) => ({
      action: e.action,
      detail: e.detail,
      actorName: e.actor?.name ?? null,
      createdAt: e.createdAt.toISOString(),
    })),
  }));

  return <DirectoryTable docs={rows} canEdit={canEdit} />;
}
