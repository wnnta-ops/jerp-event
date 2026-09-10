import { prisma } from "@/lib/prisma";
import { requireSession, handleError, ApiError, ok } from "@/lib/api";
import { resolveActors, actorName } from "@/lib/actors";
import { BATAL_ALASAN_LABEL, UNIT_STATUS_LABEL } from "@/lib/constants";
import { formatIdDateLong } from "@/lib/wib";

// Per-house history: all bookings ever placed on this unit (incl. cancelled,
// with full info) + the change log (AuditLog). Powers the unit detail modal.
export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    await requireSession();
    const { id } = await params;

    const unit = await prisma.unit.findUnique({
      where: { id },
      select: {
        currentDealId: true,
        deals: {
          orderBy: { tanggalWi: "desc" },
          select: {
            id: true,
            nama: true,
            caraBayar: true,
            batal: true,
            batalAlasan: true,
            batalTanggal: true,
            tglUtj: true,
            tglSpr: true,
            tglAkad: true,
            sales: { select: { name: true } },
            agency: { select: { name: true } },
          },
        },
      },
    });
    if (!unit) throw new ApiError(404, "Unit tidak ditemukan.");

    const logsRaw = await prisma.auditLog.findMany({
      where: { entity: "Unit", entityId: id },
      orderBy: { createdAt: "desc" },
      take: 50,
    });

    const actors = await resolveActors(logsRaw.map((l) => l.actorId));

    const bookings = unit.deals.map((d) => ({
      current: d.id === unit.currentDealId,
      nama: d.nama,
      sales: d.sales.name,
      agent: d.agency.name,
      bayar: d.caraBayar ?? "-",
      batal: d.batal,
      batalAlasan: d.batalAlasan ? BATAL_ALASAN_LABEL[d.batalAlasan] ?? d.batalAlasan : null,
      tglUtj: d.tglUtj ? formatIdDateLong(d.tglUtj) : null,
      tglSpr: d.tglSpr ? formatIdDateLong(d.tglSpr) : null,
      tglAkad: d.tglAkad ? formatIdDateLong(d.tglAkad) : null,
      batalTanggal: d.batalTanggal ? formatIdDateLong(d.batalTanggal) : null,
    }));

    const fmt = (v: unknown): string => {
      if (v && typeof v === "object") {
        const o = v as Record<string, unknown>;
        if (typeof o.status === "string") return UNIT_STATUS_LABEL[o.status] ?? o.status;
      }
      return String(v ?? "-");
    };

    const logs = logsRaw.map((l) => {
      const before = (l.before ?? {}) as Record<string, unknown>;
      const after = (l.after ?? {}) as Record<string, unknown>;
      const parts: string[] = [];
      if (l.action === "batal") parts.push("Dibatalkan → Open Stock");
      else {
        if (after.status) parts.push(`Status: ${fmt(before)} → ${UNIT_STATUS_LABEL[String(after.status)] ?? after.status}`);
        if (after.user) parts.push(`User: ${before.user ?? "-"} → ${after.user}`);
        if (after.salesId) parts.push("Sales diubah");
        if (after.agencyId) parts.push("Agent diubah");
        if (after.caraBayar) parts.push(`Bayar: ${after.caraBayar}`);
      }
      return {
        action: l.action,
        detail: parts.join(" · ") || l.action,
        dateLabel: formatIdDateLong(l.createdAt),
        actor: actorName(l.actorId, actors),
      };
    });

    return ok({ bookings, logs });
  } catch (e) {
    return handleError(e);
  }
}
