import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  kategori: z.enum(["UTJ", "SPR", "WAITING_LIST"]),
  alasan: z.enum([
    "KOL_2",
    "KOL_5",
    "REJECT_BANK",
    "BATAL_SEPIHAK",
    "MASALAH_KELUARGA",
    "SKEMA_BAYAR",
    "OTHER",
  ]),
  refundStatus: z.enum(["NONE", "PENDING", "DONE"]).default("NONE"),
  refundAmount: z.number().optional().nullable(),
});

// Cancel a deal (Collection/Management). Returns the unit to Open Stock.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["COLLECTION", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());
    const deal = await prisma.deal.findUnique({ where: { id } });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");
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
          batalKategori: body.kategori,
          batalAlasan: body.alasan,
          batalTanggal: today,
          refundStatus: body.refundStatus,
          refundAmount: body.refundAmount ?? null,
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
          meta: { kategori: body.kategori, alasan: body.alasan },
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
