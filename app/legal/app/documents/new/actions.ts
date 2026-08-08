"use server";

import { mkdir, writeFile } from "fs/promises";
import path from "path";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { documentInputSchema, fieldErrors } from "@/lib/validation";
import { scopeForDocType, type DocType } from "@/lib/enums";

export type CreateResult =
  | { ok: true; id: string; title: string }
  | { ok: false; errors: Record<string, string>; message?: string };

const MAX_BYTES = 20 * 1024 * 1024; // 20 MB (matches the dropzone hint)

function sanitize(name: string) {
  const base = name.replace(/[^a-zA-Z0-9._-]+/g, "_").replace(/_+/g, "_");
  return base.length ? base : "file";
}

export async function createDocument(formData: FormData): Promise<CreateResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, errors: {}, message: "Tidak ada pengguna aktif." };

  // --- validate metadata (the authority; the client validates too) ---
  const raw = {
    docType: formData.get("docType"),
    title: formData.get("title"),
    docNumber: formData.get("docNumber"),
    cluster: formData.get("cluster"),
    blok: formData.get("blok"),
    unitNo: formData.get("unitNo"),
    buyerName: formData.get("buyerName"),
    entityName: formData.get("entityName"),
    status: formData.get("status"),
    issueDate: formData.get("issueDate"),
    expiryDate: formData.get("expiryDate"),
    picId: formData.get("picId"),
    originalLocation: formData.get("originalLocation"),
    originalLocationDetail: formData.get("originalLocationDetail"),
    version: formData.get("version"),
    isCurrentApproved: formData.get("isCurrentApproved") === "on" || formData.get("isCurrentApproved") === "true",
    objectLocation: formData.get("objectLocation"),
    landArea: formData.get("landArea"),
    notes: formData.get("notes"),
    description: formData.get("description"),
    boardStructure: formData.get("boardStructure"),
    authorizedCapital: formData.get("authorizedCapital"),
    issuedCapital: formData.get("issuedCapital"),
    shareholders: formData.get("shareholders"),
    parties: formData.get("parties"),
    reminderDate: formData.get("reminderDate"),
    reminderNote: formData.get("reminderNote"),
    accessLevel: formData.get("accessLevel"),
  };

  const parsed = documentInputSchema.safeParse(raw);
  if (!parsed.success) return { ok: false, errors: fieldErrors(parsed.error) };
  const data = parsed.data;

  // --- validate files: at least one, none oversized ---
  const files = formData.getAll("files").filter((f): f is File => f instanceof File && f.size > 0);
  if (files.length === 0) return { ok: false, errors: { files: "Lampirkan minimal satu berkas" } };
  const tooBig = files.find((f) => f.size > MAX_BYTES);
  if (tooBig) return { ok: false, errors: { files: `Berkas "${tooBig.name}" melebihi 20 MB` } };

  // --- verify PIC exists ---
  const pic = await prisma.user.findUnique({ where: { id: data.picId } });
  if (!pic) return { ok: false, errors: { picId: "Penanggung jawab tidak ditemukan" } };

  const docType = data.docType as DocType;
  const scope = scopeForDocType(docType);

  // --- persist ---
  const doc = await prisma.document.create({
    data: {
      docType,
      title: data.title,
      docNumber: data.docNumber ?? null,
      scope,
      cluster: scope === "unit" ? data.cluster ?? null : null,
      blok: scope === "unit" ? data.blok ?? null : null,
      unitNo: scope === "unit" ? data.unitNo ?? null : null,
      entityName: scope === "entity" ? data.entityName ?? null : null,
      buyerName: scope === "unit" ? data.buyerName ?? null : null,
      status: data.status,
      issueDate: data.issueDate ? new Date(data.issueDate) : null,
      expiryDate: data.expiryDate ? new Date(data.expiryDate) : null,
      picId: data.picId,
      uploadedById: acting.id,
      originalLocation: data.originalLocation,
      originalLocationDetail: data.originalLocationDetail ?? null,
      objectLocation: data.objectLocation ?? null,
      landArea: data.landArea ?? null,
      notes: data.notes ?? null,
      description: data.description ?? null,
      boardStructure: data.boardStructure ?? null,
      authorizedCapital: data.authorizedCapital ?? null,
      issuedCapital: data.issuedCapital ?? null,
      shareholders: data.shareholders ?? null,
      parties: data.parties ?? null,
      reminderDate: data.reminderDate ? new Date(data.reminderDate) : null,
      reminderNote: data.reminderNote ?? null,
      accessLevel: data.accessLevel,
      events: { create: { actorId: acting.id, action: "created", detail: "Dokumen diunggah" } },
    },
  });

  // --- write files to /public/uploads/<docId> and record them ---
  const dir = path.join(process.cwd(), "public", "uploads", doc.id);
  await mkdir(dir, { recursive: true });
  for (const file of files) {
    const safe = sanitize(file.name);
    const buf = Buffer.from(await file.arrayBuffer());
    await writeFile(path.join(dir, safe), buf);
    await prisma.documentFile.create({
      data: {
        documentId: doc.id,
        url: `/uploads/${doc.id}/${safe}`,
        fileName: file.name,
        size: file.size,
        mimeType: file.type || null,
      },
    });
  }

  revalidatePath("/");
  return { ok: true, id: doc.id, title: doc.title };
}
