"use server";

import { mkdir, writeFile, rm } from "fs/promises";
import path from "path";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { buatSkemaDokumen, fieldErrors } from "@/lib/validation";
import {
  DOC_STATUSES,
  ORIGINAL_LOCATION_LABELS,
  isSprLinked,
  scopeForDocType,
  type DocType,
  type OriginalLocation,
} from "@/lib/enums";
import { lepasSinkronAjb, sinkronAjb } from "@/lib/ajb-sync";

export type UpdateResult = { ok: true } | { ok: false; error: string };
export type EditResult =
  | { ok: true; peringatan?: string }
  | { ok: false; errors?: Record<string, string>; error?: string };

const MAX_BYTES = 20 * 1024 * 1024;

function sanitize(name: string) {
  const base = name.replace(/[^a-zA-Z0-9._-]+/g, "_").replace(/_+/g, "_");
  return base.length ? base : "file";
}

function dateKey(d: Date | null | undefined): string {
  return d ? d.toISOString().slice(0, 10) : "";
}

/** Manual status/expiry update by Legal — kept for the drawer quick-edit. */
export async function updateDocumentStatus(docId: string, formData: FormData): Promise<UpdateResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const status = String(formData.get("status") ?? "");
  if (!DOC_STATUSES.includes(status as never)) return { ok: false, error: "Status tidak valid." };

  const expiryRaw = String(formData.get("expiryDate") ?? "").trim();
  let expiryDate: Date | null = null;
  if (expiryRaw) {
    const d = new Date(`${expiryRaw}T00:00:00Z`);
    if (Number.isNaN(d.getTime())) return { ok: false, error: "Tanggal kedaluwarsa tidak valid." };
    expiryDate = d;
  }

  const doc = await prisma.document.findUnique({ where: { id: docId }, select: { status: true } });
  if (!doc) return { ok: false, error: "Dokumen tidak ditemukan." };

  await prisma.document.update({
    where: { id: docId },
    data: {
      status,
      expiryDate,
      lastEditedById: acting.id,
      events:
        doc.status !== status
          ? { create: { actorId: acting.id, action: "edit", detail: `Status → ${status}` } }
          : undefined,
    },
  });
  revalidatePath("/");
  revalidatePath("/timeline");
  revalidatePath("/alerts");
  return { ok: true };
}

// Human labels for the change summary written into the history log.
const FIELD_LABELS: Record<string, string> = {
  title: "Judul",
  docNumber: "Nomor dokumen",
  status: "Status",
  issueDate: "Tanggal terbit",
  expiryDate: "Masa berakhir",
  accessLevel: "Tingkat akses",
  picId: "PIC",
  cluster: "Cluster",
  blok: "Blok",
  unitNo: "Nomor unit",
  buyerName: "Pembeli",
  entityName: "Entitas",
  objectLocation: "Lokasi/objek",
  landArea: "Luas lahan",
  notes: "Catatan",
  description: "Deskripsi",
  boardStructure: "Susunan pengurus",
  authorizedCapital: "Modal dasar",
  issuedCapital: "Modal ditempatkan",
  shareholders: "Pemegang saham",
  parties: "Pihak",
  sprNumber: "No. SPR (Final)",
  notaryName: "Notaris / PPAT",
  ajbValue: "Nominal akta",
};

/**
 * Full edit of a directory document. Updates every metadata field, optionally
 * replaces the attached file(s), and appends one or more entries to the
 * document's history log (who + when + what changed). Any logged-in user.
 */
export async function updateDocument(docId: string, formData: FormData): Promise<EditResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

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
    sprNumber: formData.get("sprNumber"),
    notaryName: formData.get("notaryName"),
    ajbValue: formData.get("ajbValue"),
    accessLevel: formData.get("accessLevel"),
  };

  const existing = await prisma.document.findUnique({ where: { id: docId } });
  if (!existing) return { ok: false, error: "Dokumen tidak ditemukan." };

  // Dokumen AJB lama (hasil impor) memang belum punya No. SPR. Mewajibkannya di
  // sini akan membuat dokumen itu tidak bisa disunting sama sekali — termasuk
  // untuk membetulkan salah ketik. Yang SUDAH tertaut tetap wajib: mengosongkan
  // tautannya diam-diam meninggalkan baris yatim di Back Office.
  const parsed = buatSkemaDokumen({ sprWajib: Boolean(existing.sprNumber) }).safeParse(raw);
  if (!parsed.success) return { ok: false, errors: fieldErrors(parsed.error) };
  const data = parsed.data;

  const pic = await prisma.user.findUnique({ where: { id: data.picId } });
  if (!pic) return { ok: false, errors: { picId: "Penanggung jawab tidak ditemukan" } };

  // Optional file replacement.
  const files = formData.getAll("files").filter((f): f is File => f instanceof File && f.size > 0);
  const tooBig = files.find((f) => f.size > MAX_BYTES);
  if (tooBig) return { ok: false, errors: { files: `Berkas "${tooBig.name}" melebihi 20 MB` } };

  const docType = data.docType as DocType;
  const scope = scopeForDocType(docType);

  const next = {
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
    // Tautan AJB → SPR (Final); dikosongkan bila jenis dokumennya diubah jadi
    // bukan AJB, supaya tidak ada nomor SPR yatim menempel di baris ini.
    sprNumber: isSprLinked(docType) ? data.sprNumber ?? null : null,
    notaryName: isSprLinked(docType) ? data.notaryName ?? null : null,
    ajbValue: isSprLinked(docType) ? data.ajbValue ?? null : null,
    accessLevel: data.accessLevel,
  };

  // --- build the history events from what actually changed ---
  const events: { actorId: string; action: string; detail: string }[] = [];

  // 1. Lokasi fisik (original location) — its own log line (legal note #1).
  if (existing.originalLocation !== next.originalLocation || (existing.originalLocationDetail ?? "") !== (next.originalLocationDetail ?? "")) {
    const fmtLoc = (loc: string, det: string | null) => {
      const l = ORIGINAL_LOCATION_LABELS[loc as OriginalLocation] ?? loc;
      return det ? `${l} (${det})` : l;
    };
    const before = fmtLoc(existing.originalLocation, existing.originalLocationDetail);
    const after = fmtLoc(next.originalLocation, next.originalLocationDetail);
    events.push({ actorId: acting.id, action: "location_change", detail: `Lokasi fisik: ${before} → ${after}` });
  }

  // 2. Reminder pembaruan.
  if (dateKey(existing.reminderDate) !== dateKey(next.reminderDate) || (existing.reminderNote ?? "") !== (next.reminderNote ?? "")) {
    const txt = next.reminderDate
      ? `Reminder diatur ke ${dateKey(next.reminderDate)}${next.reminderNote ? ` — ${next.reminderNote}` : ""}`
      : "Reminder dihapus";
    events.push({ actorId: acting.id, action: "reminder", detail: txt });
  }

  // 3. Other metadata fields — one combined "edit" line.
  const changed: string[] = [];
  const cmp = (a: unknown, b: unknown) => (a ?? "") !== (b ?? "");
  for (const key of Object.keys(FIELD_LABELS)) {
    const before = key === "issueDate" || key === "expiryDate"
      ? dateKey((existing as Record<string, unknown>)[key] as Date | null)
      : (existing as Record<string, unknown>)[key];
    const after = key === "issueDate" || key === "expiryDate"
      ? dateKey((next as Record<string, unknown>)[key] as Date | null)
      : (next as Record<string, unknown>)[key];
    if (cmp(before, after)) changed.push(FIELD_LABELS[key]);
  }
  if (changed.length) {
    events.push({ actorId: acting.id, action: "edit", detail: `Mengubah: ${changed.join(", ")}` });
  }

  // --- replace files on disk if new ones were attached ---
  if (files.length > 0) {
    const old = await prisma.documentFile.findMany({ where: { documentId: docId } });
    const dir = path.join(process.cwd(), "public", "uploads", docId);
    await rm(dir, { recursive: true, force: true });
    await mkdir(dir, { recursive: true });
    await prisma.documentFile.deleteMany({ where: { documentId: docId } });
    for (const file of files) {
      const safe = sanitize(file.name);
      const buf = Buffer.from(await file.arrayBuffer());
      await writeFile(path.join(dir, safe), buf);
      await prisma.documentFile.create({
        data: {
          documentId: docId,
          url: `/uploads/${docId}/${safe}`,
          fileName: file.name,
          size: file.size,
          mimeType: file.type || null,
        },
      });
    }
    events.push({
      actorId: acting.id,
      action: "file_replace",
      detail: `Berkas diganti (${files.length} berkas, sebelumnya ${old.length})`,
    });
  }

  const doc = await prisma.document.update({
    where: { id: docId },
    data: {
      ...next,
      lastEditedById: acting.id,
      ...(events.length ? { events: { create: events } } : {}),
    },
  });

  // Selalu disalurkan — juga ketika jenis dokumennya BERUBAH dari AJB jadi lain.
  // sinkronAjb() melepas baris di Back Office untuk kasus itu; tanpa panggilan
  // ini, akta yang sudah bukan AJB lagi akan tetap mengisi Detail AJB selamanya.
  const sinkron = await sinkronAjb(doc);

  revalidatePath("/");
  revalidatePath("/timeline");
  revalidatePath("/alerts");
  return { ok: true, ...(sinkron.pesan ? { peringatan: sinkron.pesan } : {}) };
}

/** Permanent delete — removes the row, its files (disk + db), and history. */
export async function deleteDocument(docId: string): Promise<UpdateResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const doc = await prisma.document.findUnique({ where: { id: docId }, select: { id: true } });
  if (!doc) return { ok: false, error: "Dokumen tidak ditemukan." };

  // Lepas tautannya DULU, selagi dokumennya masih ada. Kalau dibalik dan
  // pelepasan gagal, baris di Detail AJB jadi yatim tanpa ada dokumen tersisa
  // di sini yang bisa dipakai mengulang pelepasannya.
  await lepasSinkronAjb(docId);

  const dir = path.join(process.cwd(), "public", "uploads", docId);
  await rm(dir, { recursive: true, force: true });
  await prisma.document.delete({ where: { id: docId } });

  revalidatePath("/");
  revalidatePath("/timeline");
  revalidatePath("/alerts");
  return { ok: true };
}
