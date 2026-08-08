"use server";

import { mkdir, writeFile } from "fs/promises";
import path from "path";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { ACCESS_TYPES, inAppChainRoles, isPhysical, type AccessType } from "@/lib/access";
import { forwardToExternalApproval } from "@/lib/external-approval";
import { DOC_TYPES, type Role } from "@/lib/enums";
import { watermarkFile } from "@/lib/watermark";
import { REQUEST_DOCS_DIR } from "@/lib/storage";

export type CreateRequestResult =
  | { ok: true; id: string; code: string; autoApproved: boolean }
  | { ok: false; error: string };

async function nextCode(): Promise<string> {
  const n = await prisma.accessRequest.count();
  return `DR-${String(142 + n).padStart(4, "0")}`;
}

/** Resolve the first user holding a given chain role (role-based; any holder may act). */
async function approverFor(role: Role): Promise<string | null> {
  const u = await prisma.user.findFirst({ where: { role }, orderBy: { name: "asc" } });
  return u?.id ?? null;
}

export async function createAccessRequest(formData: FormData): Promise<CreateRequestResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  if (acting.role === "DIRECTOR") return { ok: false, error: "Direktur tidak dapat membuat permintaan akses." };

  // Manual request — described by the requester, NOT picked from the directory.
  const requestedItem = String(formData.get("requestedItem") ?? "").trim();
  const requestedTypeRaw = String(formData.get("requestedType") ?? "").trim();
  const requestedType = DOC_TYPES.includes(requestedTypeRaw as never) ? requestedTypeRaw : null;
  const accessType = String(formData.get("accessType") ?? "") as AccessType;
  const purpose = String(formData.get("purpose") ?? "").trim();
  const neededByRaw = String(formData.get("neededBy") ?? "").trim();

  if (!requestedItem) return { ok: false, error: "Tulis dokumen yang diminta." };
  if (!ACCESS_TYPES.includes(accessType)) return { ok: false, error: "Pilih jenis akses." };
  if (!purpose) return { ok: false, error: "Tujuan wajib diisi." };
  if (!neededByRaw || Number.isNaN(Date.parse(neededByRaw))) return { ok: false, error: "Tanggal butuh tidak valid." };

  // In-app chain is always PIC → Head of Legal. Physical checkout continues to
  // the external app (GM → Direktur → Owner) after Head of Legal signs.
  const roles = inAppChainRoles(accessType);
  const code = await nextCode();

  const request = await prisma.$transaction(async (tx) => {
    const req = await tx.accessRequest.create({
      data: {
        code,
        requestedItem,
        requestedType,
        requesterId: acting.id,
        dept: acting.department ?? "—",
        purpose,
        accessType,
        neededBy: new Date(neededByRaw),
        status: "pending",
        currentStep: 0,
      },
    });

    await tx.auditEntry.create({
      data: { requestId: req.id, actorId: acting.id, action: "created", detail: `Diminta oleh ${acting.name}: ${requestedItem}` },
    });

    // Build the full chain; auto-notify the first approver (it is now their turn).
    for (let i = 0; i < roles.length; i++) {
      await tx.approvalStep.create({
        data: {
          requestId: req.id,
          order: i,
          role: roles[i],
          approverId: await approverFor(roles[i]),
          notifiedAt: i === 0 ? new Date() : null,
        },
      });
    }
    await tx.auditEntry.create({ data: { requestId: req.id, action: "notified", detail: "Auto-notifikasi approver pertama." } });
    return req;
  });

  revalidatePath("/requests");
  return { ok: true, id: request.id, code, autoApproved: false };
}

const MAX_BYTES = 20 * 1024 * 1024; // 20 MB
const sanitize = (name: string) => (name.replace(/[^a-zA-Z0-9._-]+/g, "_").replace(/_+/g, "_") || "file");

export type DecideResult = { ok: true } | { ok: false; error: string };

/**
 * Legal PIC step: approve AND upload the requested document in one action (the
 * doc is attached at the START of the chain, not the end). Watermarks + stores
 * the file privately, marks the PIC step approved, and advances the chain.
 */
export async function approveWithDocument(requestId: string, formData: FormData): Promise<DecideResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const req = await prisma.accessRequest.findUnique({ where: { id: requestId }, include: { steps: { orderBy: { order: "asc" } } } });
  if (!req) return { ok: false, error: "Permintaan tidak ditemukan." };
  if (req.status !== "pending") return { ok: false, error: "Permintaan sudah selesai." };

  const step = req.steps[req.currentStep];
  if (!step) return { ok: false, error: "Tidak ada langkah aktif." };
  if (step.role !== "LEGAL_PIC") return { ok: false, error: "Unggah dokumen hanya pada langkah Legal PIC." };
  const allowed = step.approverId ? acting.id === step.approverId : step.role === acting.role;
  if (!allowed) return { ok: false, error: "Bukan giliran Anda — permintaan ini ditugaskan ke Legal PIC lain." };

  const file = formData.get("file");
  const note = String(formData.get("note") ?? "").trim();
  if (!(file instanceof File) || file.size === 0) return { ok: false, error: "Lampirkan berkas dokumen." };
  if (file.size > MAX_BYTES) return { ok: false, error: `Berkas "${file.name}" melebihi 20 MB.` };

  // Watermark BEFORE storing — a "COPY" mark with the request code.
  const phrase = `COPY · ${req.code}`;
  const origExt = path.extname(file.name).slice(1);
  const wm = await watermarkFile(new Uint8Array(await file.arrayBuffer()), file.type || "", origExt, phrase);

  const dir = path.join(REQUEST_DOCS_DIR, req.id);
  await mkdir(dir, { recursive: true });
  const baseName = sanitize(path.basename(file.name, path.extname(file.name)));
  const outName = `${baseName}.${wm.ext}`;
  await writeFile(path.join(dir, outName), Buffer.from(wm.bytes));
  const relPath = `${req.id}/${outName}`; // relative to PRIVATE_ROOT; served via /api/request-doc
  const displayName = wm.converted ? `${path.basename(file.name, path.extname(file.name))}.pdf` : file.name;

  await prisma.$transaction(async (tx) => {
    await tx.approvalStep.update({ where: { id: step.id }, data: { decision: "approved", decidedAt: new Date(), approverId: step.approverId ?? acting.id, note: note || null } });
    await tx.auditEntry.create({ data: { requestId: req.id, actorId: acting.id, action: "uploaded", detail: `Dokumen diunggah & disetujui oleh ${acting.name} (watermark "${phrase}"): ${displayName}` } });

    const docData = { fulfilledAt: new Date(), fulfilledById: acting.id, fulfilledFileUrl: relPath, fulfilledFileName: displayName, fulfilledNote: note || null };
    const isLast = req.currentStep + 1 >= req.steps.length;
    if (isLast) {
      await tx.accessRequest.update({ where: { id: req.id }, data: { ...docData, status: "approved" } });
    } else {
      const next = req.steps[req.currentStep + 1];
      await tx.accessRequest.update({ where: { id: req.id }, data: { ...docData, currentStep: req.currentStep + 1 } });
      await tx.approvalStep.update({ where: { id: next.id }, data: { notifiedAt: new Date() } });
      await tx.auditEntry.create({ data: { requestId: req.id, action: "notified", detail: "Auto-notifikasi approver berikutnya." } });
    }
  });

  revalidatePath("/requests");
  revalidatePath(`/requests/${requestId}`);
  return { ok: true };
}

export async function decideStep(requestId: string, decision: "approved" | "rejected", note?: string): Promise<DecideResult> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const req = await prisma.accessRequest.findUnique({ where: { id: requestId }, include: { steps: { orderBy: { order: "asc" } } } });
  if (!req) return { ok: false, error: "Permintaan tidak ditemukan." };
  if (req.status !== "pending") return { ok: false, error: "Permintaan sudah selesai." };

  const step = req.steps[req.currentStep];
  if (!step) return { ok: false, error: "Tidak ada langkah aktif." };

  // Only the SPECIFICALLY ASSIGNED approver may decide (role is only a fallback
  // when the step has no assigned person). So another holder of the same role
  // (e.g. a different Legal PIC) cannot act on someone else's step.
  const allowed = step.approverId ? acting.id === step.approverId : step.role === acting.role;
  if (!allowed) {
    return { ok: false, error: "Bukan giliran Anda untuk memutuskan langkah ini." };
  }

  // The Legal PIC step approves by uploading the document (use approveWithDocument).
  if (decision === "approved" && step.role === "LEGAL_PIC" && !req.fulfilledFileUrl) {
    return { ok: false, error: "Unggah dokumen terlebih dahulu untuk menyetujui langkah ini." };
  }

  const isLast = req.currentStep + 1 >= req.steps.length;
  // Physical checkout: after the last in-app approver (Head of Legal) signs,
  // hand off to the external approval app (GM → Direktur → Owner) instead of
  // finalizing here.
  const willForwardExternal = decision === "approved" && isLast && isPhysical(req.accessType as AccessType);

  await prisma.$transaction(async (tx) => {
    await tx.approvalStep.update({
      where: { id: step.id },
      data: { decision, decidedAt: new Date(), note: note?.trim() || null, approverId: step.approverId ?? acting.id },
    });
    await tx.auditEntry.create({
      data: { requestId: req.id, actorId: acting.id, action: decision, detail: note?.trim() || undefined },
    });

    if (decision === "rejected") {
      await tx.accessRequest.update({ where: { id: req.id }, data: { status: "rejected" } });
      return;
    }

    if (isLast) {
      if (willForwardExternal) {
        await tx.accessRequest.update({ where: { id: req.id }, data: { status: "pending_external" } });
        await tx.auditEntry.create({ data: { requestId: req.id, action: "forwarded_external", detail: "Diteruskan ke approval eksternal: GM → Direktur → Owner." } });
      } else {
        await tx.accessRequest.update({ where: { id: req.id }, data: { status: "approved" } });
      }
    } else {
      const next = req.steps[req.currentStep + 1];
      await tx.accessRequest.update({ where: { id: req.id }, data: { currentStep: req.currentStep + 1 } });
      await tx.approvalStep.update({ where: { id: next.id }, data: { notifiedAt: new Date() } });
      await tx.auditEntry.create({ data: { requestId: req.id, action: "notified", detail: "Auto-notifikasi approver berikutnya." } });
    }
  });

  // Fire the external hand-off after the row is committed (best-effort seam).
  if (willForwardExternal) {
    await forwardToExternalApproval({
      id: req.id, code: req.code, requestedItem: req.requestedItem,
      accessType: req.accessType, purpose: req.purpose, neededBy: req.neededBy,
    });
  }

  revalidatePath("/requests");
  revalidatePath(`/requests/${requestId}`);
  return { ok: true };
}

export async function notifyApprover(requestId: string): Promise<DecideResult> {
  const acting = await getActingUser();
  const req = await prisma.accessRequest.findUnique({ where: { id: requestId }, include: { steps: { orderBy: { order: "asc" } } } });
  if (!req) return { ok: false, error: "Permintaan tidak ditemukan." };
  if (req.status !== "pending") return { ok: false, error: "Permintaan sudah selesai." };
  const step = req.steps[req.currentStep];
  if (!step) return { ok: false, error: "Tidak ada langkah aktif." };

  await prisma.$transaction(async (tx) => {
    await tx.approvalStep.update({ where: { id: step.id }, data: { notifiedAt: new Date() } });
    await tx.auditEntry.create({
      data: { requestId: req.id, actorId: acting?.id ?? null, action: "notified", detail: "Pengingat manual dikirim." },
    });
  });

  revalidatePath("/requests");
  revalidatePath(`/requests/${requestId}`);
  return { ok: true };
}
