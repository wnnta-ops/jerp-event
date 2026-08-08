"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { MO12 } from "@/lib/timeline";
import { checkpointsFor } from "@/lib/timeline-checkpoints";

export type Result = { ok: true; id?: string } | { ok: false; error: string };

const MAX = 15 * 1024 * 1024;

// ── Sections ──────────────────────────────────────────────────────────
export async function createSection(formData: FormData): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  const title = String(formData.get("title") ?? "").trim();
  const range = String(formData.get("range") ?? "").trim() || "2026";
  if (!title) return { ok: false, error: "Nama kategori wajib diisi." };
  const clusterRaw = String(formData.get("cluster") ?? "").replace(/\D/g, "");
  const cluster = clusterRaw ? Number(clusterRaw) : null;
  const productType = String(formData.get("productType") ?? "").trim() || null;
  // Kategori baru muncul paling atas.
  const first = await prisma.timelineSection.findFirst({ orderBy: { order: "asc" }, select: { order: true } });
  const s = await prisma.timelineSection.create({
    data: { title, cluster, productType, range, months: JSON.stringify(MO12), order: (first?.order ?? 0) - 1, createdById: acting.id },
  });
  revalidatePath("/timeline");
  return { ok: true, id: s.id };
}

export async function updateSection(id: string, formData: FormData): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  const title = String(formData.get("title") ?? "").trim();
  const range = String(formData.get("range") ?? "").trim();
  if (!title) return { ok: false, error: "Nama kategori wajib diisi." };
  const clusterRaw = String(formData.get("cluster") ?? "").replace(/\D/g, "");
  const cluster = clusterRaw ? Number(clusterRaw) : null;
  const productType = String(formData.get("productType") ?? "").trim() || null;
  await prisma.timelineSection.update({ where: { id }, data: { title, cluster, productType, range: range || "2026" } });
  revalidatePath("/timeline");
  return { ok: true };
}

export async function deleteSection(id: string): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  await prisma.timelineSection.delete({ where: { id } });
  revalidatePath("/timeline");
  return { ok: true };
}

// ── Entries (items) ───────────────────────────────────────────────────
function parseTarget(v: FormDataEntryValue | null): number | null {
  const s = String(v ?? "").trim();
  if (s === "") return null;
  const n = Number(s);
  return Number.isInteger(n) && n >= 0 ? n : null;
}

export async function createEntry(formData: FormData): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  const sectionId = String(formData.get("sectionId") ?? "");
  const name = String(formData.get("name") ?? "").trim();
  if (!sectionId) return { ok: false, error: "Section tidak valid." };
  if (!name) return { ok: false, error: "Nama item wajib diisi." };
  const badan = String(formData.get("badan") ?? "").trim();
  const jenisDokumen = String(formData.get("jenisDokumen") ?? "").trim();
  if (!badan) return { ok: false, error: "Badan wajib dipilih." };
  if (!jenisDokumen) return { ok: false, error: "Jenis dokumen wajib dipilih." };
  const steps = checkpointsFor(badan, jenisDokumen);
  if (steps.length === 0) return { ok: false, error: "Jenis dokumen tidak dikenal." };
  const status = String(formData.get("status") ?? "—").trim() || "—";
  const durasi = String(formData.get("durasi") ?? "").trim();
  const progressMonth = parseTarget(formData.get("progressMonth"));
  const targetMonth = parseTarget(formData.get("targetMonth"));
  const last = await prisma.timelineEntry.findFirst({ where: { sectionId }, orderBy: { order: "desc" }, select: { order: true } });
  const e = await prisma.timelineEntry.create({
    data: {
      sectionId, name, badan, jenisDokumen, status, durasi, progressMonth, targetMonth, order: (last?.order ?? -1) + 1,
      checkpoints: { create: steps.map((label, i) => ({ step: label, order: i })) },
    },
  });
  revalidatePath("/timeline");
  return { ok: true, id: e.id };
}

export async function updateEntry(id: string, formData: FormData): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  const name = String(formData.get("name") ?? "").trim();
  if (!name) return { ok: false, error: "Nama item wajib diisi." };
  const badan = String(formData.get("badan") ?? "").trim();
  const jenisDokumen = String(formData.get("jenisDokumen") ?? "").trim();
  if (!badan) return { ok: false, error: "Badan wajib dipilih." };
  if (!jenisDokumen) return { ok: false, error: "Jenis dokumen wajib dipilih." };
  const steps = checkpointsFor(badan, jenisDokumen);
  if (steps.length === 0) return { ok: false, error: "Jenis dokumen tidak dikenal." };

  const cur = await prisma.timelineEntry.findUnique({ where: { id }, select: { badan: true, jenisDokumen: true } });
  const jenisChanged = !cur || cur.badan !== badan || cur.jenisDokumen !== jenisDokumen;

  await prisma.$transaction(async (tx) => {
    await tx.timelineEntry.update({
      where: { id },
      data: {
        name, badan, jenisDokumen,
        status: String(formData.get("status") ?? "—").trim() || "—",
        durasi: String(formData.get("durasi") ?? "").trim(),
        progressMonth: parseTarget(formData.get("progressMonth")),
        targetMonth: parseTarget(formData.get("targetMonth")),
      },
    });
    // Ganti jenis dokumen → daftar checkpoint ikut berganti (progres lama direset).
    if (jenisChanged) {
      await tx.timelineCheckpoint.deleteMany({ where: { entryId: id } });
      await tx.timelineCheckpoint.createMany({ data: steps.map((label, i) => ({ entryId: id, step: label, order: i })) });
    }
  });
  revalidatePath("/timeline");
  return { ok: true };
}

export async function setEntryStatus(id: string, status: string): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  await prisma.timelineEntry.update({ where: { id }, data: { status: status.trim() || "—" } });
  revalidatePath("/timeline");
  return { ok: true };
}

export async function moveEntry(id: string, field: "progress" | "target", toMonth: number): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  if (!Number.isInteger(toMonth) || toMonth < 0) return { ok: false, error: "Bulan tujuan tidak valid." };
  const entry = await prisma.timelineEntry.findUnique({ where: { id }, select: { progressMonth: true, targetMonth: true } });
  if (!entry) return { ok: false, error: "Item tidak ditemukan." };
  const fromMonth = field === "progress" ? entry.progressMonth : entry.targetMonth;
  await prisma.$transaction([
    prisma.timelineEntry.update({ where: { id }, data: field === "progress" ? { progressMonth: toMonth } : { targetMonth: toMonth } }),
    prisma.timelineMove.create({ data: { entryId: id, fromMonth, toMonth, byId: acting.id } }),
  ]);
  revalidatePath("/timeline");
  return { ok: true };
}

export async function deleteEntry(id: string): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };
  await prisma.timelineEntry.delete({ where: { id } });
  revalidatePath("/timeline");
  return { ok: true };
}

// ── Checkpoints ───────────────────────────────────────────────────────
export async function setCheckpoint(id: string, formData: FormData): Promise<Result> {
  const acting = await getActingUser();
  if (!acting) return { ok: false, error: "Tidak ada pengguna aktif." };

  const done = String(formData.get("done") ?? "") === "true";
  const note = String(formData.get("note") ?? "").trim() || null;
  const file = formData.get("proof");
  const hasNewFile = file instanceof File && file.size > 0;

  // Wajib ada bukti untuk menandai langkah selesai (baru atau sudah ada).
  if (done && !hasNewFile) {
    const existing = await prisma.timelineCheckpoint.findUnique({ where: { id }, select: { proofData: true } });
    if (!existing?.proofData) return { ok: false, error: "Lampirkan bukti untuk menyelesaikan langkah." };
  }

  const data: Record<string, unknown> = { note };
  if (done) {
    data.done = true;
    data.doneById = acting.id;
    data.doneAt = new Date();
  } else {
    data.done = false;
    data.doneById = null;
    data.doneAt = null;
  }

  if (hasNewFile) {
    if ((file as File).size > MAX) return { ok: false, error: "Bukti melebihi 15 MB." };
    data.proofData = Buffer.from(await (file as File).arrayBuffer());
    data.proofName = (file as File).name;
    data.proofMime = (file as File).type || "application/octet-stream";
  } else if (String(formData.get("removeProof") ?? "") === "true") {
    data.proofData = null;
    data.proofName = null;
    data.proofMime = null;
  }

  await prisma.timelineCheckpoint.update({ where: { id }, data });
  revalidatePath("/timeline");
  return { ok: true };
}
