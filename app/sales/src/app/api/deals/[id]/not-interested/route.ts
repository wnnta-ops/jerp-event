import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { wibToday } from "@/lib/wib";

// Mark a follow-up lead as "tidak berminat" → batal (masuk Pipeline Gagal).
// Sales-owned action (a lead declining before UTJ). Frees any held unit.
export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const deal = await prisma.deal.findUnique({ where: { id } });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");
    if (deal.batal) throw new ApiError(400, "Customer ini sudah tidak aktif.");
    const today = wibToday();

    await prisma.$transaction(async (tx) => {
      if (deal.unitId) {
        await tx.unit.update({
          where: { id: deal.unitId },
          data: { status: "OPEN_STOCK", currentDealId: null, statusOverride: false },
        });
      }
      await tx.deal.update({
        where: { id },
        data: {
          batal: true,
          batalAlasan: "TIDAK_BERMINAT",
          batalTanggal: today,
          statusLead: "COLD",
        },
      });
      await tx.pipelineEvent.create({
        data: {
          dealId: id,
          unitId: deal.unitId,
          type: "BATAL",
          eventDate: today,
          fromStage: deal.stage,
          actorId: session.user.id,
          meta: { alasan: "TIDAK_BERMINAT", note: "Lead tidak berminat (follow-up)" },
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
