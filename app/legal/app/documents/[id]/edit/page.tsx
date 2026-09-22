import Link from "next/link";
import { notFound } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { UploadForm, type EditInitial } from "@/components/upload-form";

export const dynamic = "force-dynamic";

const DEFAULT_CLUSTERS = ["Cluster Melati", "Cluster Anggrek", "Cluster Bougenville"];

function dateInput(d: Date | null | undefined): string {
  return d ? d.toISOString().slice(0, 10) : "";
}

function parseShareholders(json: string | null): { name: string; pct: string }[] {
  if (!json) return [];
  try {
    const arr = JSON.parse(json);
    return Array.isArray(arr) ? arr.map((s) => ({ name: String(s.name ?? ""), pct: String(s.percentage ?? "") })) : [];
  } catch {
    return [];
  }
}

function parseParties(json: string | null): { name: string; entity: string }[] {
  if (!json) return [];
  try {
    const arr = JSON.parse(json);
    return Array.isArray(arr) ? arr.map((p) => ({ name: String(p.name ?? ""), entity: String(p.entity ?? "") })) : [];
  } catch {
    return [];
  }
}

export default async function EditDocumentPage({ params }: { params: { id: string } }) {
  const [doc, users, acting, clusterRows] = await Promise.all([
    prisma.document.findUnique({ where: { id: params.id }, include: { files: { orderBy: { uploadedAt: "asc" } } } }),
    prisma.user.findMany({ orderBy: [{ role: "asc" }, { name: "asc" }] }),
    getActingUser(),
    prisma.document.findMany({ where: { cluster: { not: null } }, select: { cluster: true }, distinct: ["cluster"] }),
  ]);

  if (!doc) notFound();

  const clusters = Array.from(
    new Set([...DEFAULT_CLUSTERS, ...clusterRows.map((c) => c.cluster!).filter(Boolean)]),
  ).sort();

  const initial: EditInitial = {
    id: doc.id,
    values: {
      docType: doc.docType as never,
      title: doc.title,
      docNumber: doc.docNumber ?? "",
      cluster: doc.cluster ?? "",
      blok: doc.blok ?? "",
      unitNo: doc.unitNo ?? "",
      buyerName: doc.buyerName ?? "",
      entityName: doc.entityName ?? "",
      status: doc.status as never,
      issueDate: dateInput(doc.issueDate),
      expiryDate: dateInput(doc.expiryDate),
      picId: doc.picId,
      originalLocation: doc.originalLocation as never,
      originalLocationDetail: doc.originalLocationDetail ?? "",
      version: doc.version ?? "",
      isCurrentApproved: doc.isCurrentApproved,
      objectLocation: doc.objectLocation ?? "",
      landArea: doc.landArea ?? "",
      notes: doc.notes ?? "",
      description: doc.description ?? "",
      boardStructure: doc.boardStructure ?? "",
      authorizedCapital: doc.authorizedCapital ?? "",
      issuedCapital: doc.issuedCapital ?? "",
      reminderDate: dateInput(doc.reminderDate),
      reminderNote: doc.reminderNote ?? "",
      sprNumber: doc.sprNumber ?? "",
      notaryName: doc.notaryName ?? "",
      ajbValue: doc.ajbValue ?? "",
      accessLevel: doc.accessLevel as never,
    },
    shareholders: parseShareholders(doc.shareholders),
    parties: parseParties(doc.parties),
    existingFiles: doc.files.map((f) => ({ fileName: f.fileName })),
  };

  return (
    <div className="mx-auto max-w-[820px]">
      <div className="mb-4 flex items-center gap-2 text-xs" style={{ color: "var(--color-text-tertiary)" }}>
        <Link href="/" className="inline-flex items-center gap-1" style={{ color: "var(--color-text-info)" }}>
          <i className="ti ti-arrow-left" />
          Direktori
        </Link>
        <span>/</span>
        <span>Edit dokumen</span>
      </div>

      <UploadForm
        mode="edit"
        initial={initial}
        users={users.map((u) => ({ id: u.id, name: u.name, role: u.role, department: u.department }))}
        defaultPicId={acting?.id}
        clusters={clusters}
      />
    </div>
  );
}
