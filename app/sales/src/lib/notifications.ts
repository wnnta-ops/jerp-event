import type { Role } from "@prisma/client";
import { prisma } from "./prisma";
import { wibToday } from "./wib";
import { getMyCoordinatorId } from "./leads";

export interface NotifItem {
  dealId: string;
  nama: string;
  koor: string;
  cluster: string;
  days: number; // hari sejak follow-up terakhir / WI
}
export interface NotifData {
  count: number;
  items: NotifItem[];
}

// Daftar customer yang perlu di-follow-up (overdue), DI-SCOPE ke user yang login:
//  - MANAGEMENT / SYSADMIN → semua
//  - Sales Koor (punya coordinator) → customer di koornya
//  - Sales biasa (punya salesId) → customer miliknya
//  - selain itu → kosong
// Ambang hari mengikuti pengaturan Reminder (global + override per koor).
export async function getNotifications(
  userId: string,
  name: string,
  role: Role,
  salesId: string | null,
): Promise<NotifData> {
  const isManager = role === "MANAGEMENT" || role === "SYSADMIN";

  // scope
  let where: { batal: boolean; stage: { in: ("WI" | "FOLLOW_UP" | "NUP")[] }; coordinatorId?: string; salesId?: string } = {
    batal: false,
    stage: { in: ["WI", "FOLLOW_UP", "NUP"] },
  };
  if (!isManager) {
    const coordId = await getMyCoordinatorId(userId, name);
    if (coordId) where = { ...where, coordinatorId: coordId };
    else if (salesId) where = { ...where, salesId };
    else return { count: 0, items: [] };
  }

  const today = wibToday();
  const [settings, deals] = await Promise.all([
    prisma.reminderSetting.findMany(),
    prisma.deal.findMany({
      where,
      select: {
        id: true,
        nama: true,
        tanggalWi: true,
        coordinatorId: true,
        coordinator: { select: { name: true } },
        cluster: { select: { name: true } },
        followUps: { orderBy: { createdAt: "desc" }, take: 1, select: { createdAt: true } },
      },
    }),
  ]);

  const globalRow = settings.find((r) => !r.coordinatorId);
  // reminder dimatikan global → tidak ada notif
  if (globalRow && globalRow.active === false) return { count: 0, items: [] };
  const globalDays = globalRow?.days ?? 3;
  const overrideMap = new Map(settings.filter((r) => r.coordinatorId).map((r) => [r.coordinatorId!, r]));

  const items = deals
    .map((d) => {
      const last = d.followUps[0]?.createdAt ?? d.tanggalWi;
      const days = Math.floor((today.getTime() - last.getTime()) / 86400_000);
      const ov = d.coordinatorId ? overrideMap.get(d.coordinatorId) : undefined;
      const active = ov ? ov.active : true;
      const threshold = ov?.days ?? globalDays;
      return {
        dealId: d.id,
        nama: d.nama,
        koor: d.coordinator?.name ?? "-",
        cluster: d.cluster?.name ?? "-",
        days,
        threshold,
        active,
      };
    })
    .filter((x) => x.active && x.days >= x.threshold)
    .sort((a, b) => b.days - a.days)
    .slice(0, 50)
    .map(({ dealId, nama, koor, cluster, days }) => ({ dealId, nama, koor, cluster, days }));

  return { count: items.length, items };
}
