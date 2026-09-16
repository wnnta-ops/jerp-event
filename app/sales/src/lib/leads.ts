import type { LeadFollowUp, LeadStatus } from "@prisma/client";
import { prisma } from "./prisma";
import { idMonthShort } from "./wib";

// ---- identitas Sales Koor untuk logged-in user ----
// Menentukan Coordinator (Sales Koor) dari akun yang login. Prioritas:
//   1. User.coordinatorId (tautan eksplisit) — cara yang benar.
//   2. cocokkan nama user dengan nama Coordinator (case-insensitive) — fallback
//      supaya modul tetap jalan sebelum tautan akun dirapikan.
// Return null bila tidak ketemu (user itu bukan koor).
export async function getMyCoordinatorId(userId: string, name: string): Promise<string | null> {
  const u = await prisma.user.findUnique({
    where: { id: userId },
    select: { coordinatorId: true },
  });
  if (u?.coordinatorId) return u.coordinatorId;
  const byName = await prisma.coordinator.findFirst({
    where: { name: { equals: name.trim(), mode: "insensitive" } },
    select: { id: true },
  });
  return byName?.id ?? null;
}

export interface LeadRow {
  id: string;
  nama: string;
  phone: string | null;
  source: string | null;
  campaign: string | null;
  tanggal: string; // YYYY-MM-DD
  koor: string; // nama koor ("-" bila belum ada)
  // diisi Sales Koor (null = belum diisi):
  minat: string | null;
  status: LeadStatus | null;
  followUp: LeadFollowUp | null;
  note: string | null;
  converted: boolean;
}

const toKey = (d: Date) => d.toISOString().slice(0, 10);

function shape(l: {
  id: string;
  nama: string;
  phone: string | null;
  source: string | null;
  campaign: string | null;
  tanggal: Date;
  minat: string | null;
  status: LeadStatus | null;
  followUp: LeadFollowUp | null;
  note: string | null;
  convertedDealId: string | null;
  coordinator: { name: string } | null;
}): LeadRow {
  return {
    id: l.id,
    nama: l.nama,
    phone: l.phone,
    source: l.source,
    campaign: l.campaign,
    tanggal: toKey(l.tanggal),
    koor: l.coordinator?.name ?? "-",
    minat: l.minat,
    status: l.status,
    followUp: l.followUp,
    note: l.note,
    converted: !!l.convertedDealId,
  };
}

// Ambil daftar leads. Bila coordinatorId diberikan (role koor), HANYA lead milik
// koor itu yang dikembalikan — batas privasi per koor.
export async function getLeads(coordinatorId: string | null): Promise<LeadRow[]> {
  const rows = await prisma.lead.findMany({
    where: coordinatorId ? { coordinatorId } : {},
    orderBy: [{ tanggal: "desc" }, { createdAt: "desc" }],
    take: 3000,
    select: {
      id: true,
      nama: true,
      phone: true,
      source: true,
      campaign: true,
      tanggal: true,
      minat: true,
      status: true,
      followUp: true,
      note: true,
      convertedDealId: true,
      coordinator: { select: { name: true } },
    },
  });
  return rows.map(shape);
}

export interface LeadSummary {
  total: number;
  belumDiisi: number; // lead yang status-nya masih kosong (belum disentuh sales)
  bySource: { source: string; count: number }[];
  byStatus: { status: LeadStatus; count: number }[];
  byFollowUp: { followUp: LeadFollowUp; count: number }[];
  // matrik bulanan × koor (khusus Management). Kosong untuk koor.
  months: string[]; // label bulan yang ada datanya
  koors: string[]; // nama koor
  matrix: Record<string, Record<string, number>>; // matrix[month][koor] = count
}

// Ringkasan leads. Scope mengikuti coordinatorId (null = semua, utk Management).
export async function getLeadSummary(coordinatorId: string | null): Promise<LeadSummary> {
  const rows = await prisma.lead.findMany({
    where: coordinatorId ? { coordinatorId } : {},
    select: {
      source: true,
      status: true,
      followUp: true,
      tanggal: true,
      coordinator: { select: { name: true } },
    },
  });

  const bySourceMap = new Map<string, number>();
  const byStatusMap = new Map<LeadStatus, number>();
  const byFollowUpMap = new Map<LeadFollowUp, number>();
  const matrix: Record<string, Record<string, number>> = {};
  const koorSet = new Set<string>();
  const monthIdx = new Set<number>(); // 0..11 bulan yang punya data (tahun berjalan)
  let belumDiisi = 0;

  for (const r of rows) {
    const src = r.source?.trim() || "Lainnya";
    bySourceMap.set(src, (bySourceMap.get(src) ?? 0) + 1);
    if (r.status) byStatusMap.set(r.status, (byStatusMap.get(r.status) ?? 0) + 1);
    else belumDiisi++;
    if (r.followUp) byFollowUpMap.set(r.followUp, (byFollowUpMap.get(r.followUp) ?? 0) + 1);

    const m0 = r.tanggal.getMonth();
    monthIdx.add(m0);
    const mLabel = idMonthShort(m0);
    const koor = r.coordinator?.name ?? "-";
    koorSet.add(koor);
    matrix[mLabel] ??= {};
    matrix[mLabel][koor] = (matrix[mLabel][koor] ?? 0) + 1;
  }

  const months = [...monthIdx].sort((a, b) => a - b).map((m) => idMonthShort(m));
  const koors = [...koorSet].sort((a, b) => a.localeCompare(b));

  return {
    total: rows.length,
    belumDiisi,
    bySource: [...bySourceMap.entries()]
      .map(([source, count]) => ({ source, count }))
      .sort((a, b) => b.count - a.count),
    byStatus: [...byStatusMap.entries()].map(([status, count]) => ({ status, count })),
    byFollowUp: [...byFollowUpMap.entries()].map(([followUp, count]) => ({ followUp, count })),
    months,
    koors,
    matrix,
  };
}

// Fire-and-forget write-back ke sheet MarComm bila endpoint-nya diset.
// (Butuh URL + service account dari MarComm — belum aktif kalau env kosong.)
export async function writeBackToMarcomm(payload: unknown): Promise<void> {
  const url = process.env.LEADS_WRITEBACK_URL;
  if (!url) return; // integrasi belum disiapkan — lewati diam-diam
  try {
    await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(process.env.LEADS_WRITEBACK_SECRET
          ? { "x-leads-secret": process.env.LEADS_WRITEBACK_SECRET }
          : {}),
      },
      body: JSON.stringify(payload),
    });
  } catch (e) {
    console.error("writeBackToMarcomm gagal:", e);
  }
}
