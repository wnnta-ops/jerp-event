import type { EventType, Stage } from "@prisma/client";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, ok, handleError, ApiError } from "@/lib/api";
import { STAGE_INDEX } from "@/lib/constants";
import { allowedTargets, canTransition } from "@/lib/pipeline-flow";
import { wibToday } from "@/lib/wib";

const schema = z.object({ toStage: z.string().optional() });

const DATE_FIELD: Partial<Record<Stage, string>> = {
  NUP: "tglNup",
  UTJ: "tglUtj",
  SPR: "tglSpr",
  ACC: "tglAcc",
  AKAD: "tglAkad",
  REALISASI: "tglRealisasi",
};

// Advance a deal. Non-linear: from Follow-Up you can go NUP or UTJ. The target
// is validated against the transition map + role ownership.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    const { id } = await params;
    const body = schema.parse(await req.json().catch(() => ({})));

    const deal = await prisma.deal.findUnique({ where: { id }, include: { unit: true } });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");
    if (deal.batal) throw new ApiError(400, "Customer sudah batal.");

    const options = allowedTargets(session.user.role, deal.stage);
    if (options.length === 0) {
      throw new ApiError(403, "Tahap ini bukan tanggung jawab peran Anda / sudah tahap akhir.");
    }
    const nextStage = (body.toStage as Stage) ?? options[0];
    if (!canTransition(session.user.role, deal.stage, nextStage)) {
      throw new ApiError(400, "Perpindahan tahap tidak valid untuk peran Anda.");
    }

    const today = wibToday();

    await prisma.$transaction(async (tx) => {
      const data: Record<string, unknown> = { stage: nextStage };
      const df = DATE_FIELD[nextStage];
      if (df) data[df] = today;
      if (nextStage === "UTJ" && !deal.unitId) data.waitingList = true;
      if (nextStage === "REALISASI" && deal.unit && !deal.unit.statusOverride) {
        await tx.unit.update({
          where: { id: deal.unit.id },
          data: {
            status: deal.caraBayar === "CASH" ? "CASH_LUNAS" : "REALISASI",
            tglAkad: today,
          },
        });
      }
      await tx.deal.update({ where: { id: deal.id }, data });
      await tx.pipelineEvent.create({
        data: {
          dealId: deal.id,
          unitId: deal.unitId,
          type: nextStage as EventType,
          eventDate: today,
          fromStage: deal.stage,
          toStage: nextStage,
          actorId: session.user.id,
        },
      });
    });

    return ok({ ok: true, stage: nextStage });
  } catch (e) {
    return handleError(e);
  }
}
