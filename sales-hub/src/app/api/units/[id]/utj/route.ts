import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { resolveCoordinatorId } from "@/lib/derivations";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  nama: z.string().min(1),
  salesId: z.string().min(1),
  agencyId: z.string().min(1),
  caraBayar: z.enum(["KPR", "CASH"]).default("KPR"),
  promoId: z.string().optional().nullable(),
  bankId: z.string().optional().nullable(),
  statusLead: z.enum(["HOT", "WARM", "COLD"]).default("COLD"),
});

// Input UTJ directly on an Open-Stock unit. Locks the unit (-> Proses) and
// creates the customer at the UTJ stage. Sales-owned.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());

    const unit = await prisma.unit.findUnique({
      where: { id },
      include: { phase: { include: { cluster: true } } },
    });
    if (!unit) throw new ApiError(404, "Unit tidak ditemukan.");
    if (unit.status !== "OPEN_STOCK") throw new ApiError(400, "Unit tidak Open Stock.");

    const today = wibToday();
    const coordinatorId = await resolveCoordinatorId(body.agencyId, today);

    const deal = await prisma.$transaction(async (tx) => {
      const d = await tx.deal.create({
        data: {
          nama: body.nama,
          channel: "SITE",
          agencyId: body.agencyId,
          salesId: body.salesId,
          coordinatorId,
          clusterId: unit.phase.clusterId,
          phaseId: unit.phaseId,
          unitTypeId: unit.unitTypeId,
          statusLead: body.statusLead,
          stage: "UTJ",
          tanggalWi: today,
          unitId: unit.id,
          caraBayar: body.caraBayar,
          promoId: body.promoId ?? null,
          bankId: body.bankId ?? null,
          tglUtj: today,
        },
      });
      await tx.unit.update({
        where: { id: unit.id },
        data: { status: "PROSES", currentDealId: d.id, tglUtj: today, statusOverride: false },
      });
      await tx.pipelineEvent.create({
        data: {
          dealId: d.id,
          unitId: unit.id,
          type: "UTJ",
          eventDate: today,
          toStage: "UTJ",
          actorId: session.user.id,
        },
      });
      return d;
    });

    return ok({ ok: true, id: deal.id });
  } catch (e) {
    return handleError(e);
  }
}
