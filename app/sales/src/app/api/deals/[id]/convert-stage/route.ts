import type { EventType } from "@prisma/client";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { STAGE_INDEX } from "@/lib/constants";
import { wibToday } from "@/lib/wib";

const schema = z.object({ stage: z.enum(["NUP", "UTJ"]) });
const DATE_FIELD = { NUP: "tglNup", UTJ: "tglUtj" } as const;

// Sales menandai lead lanjut ke NUP atau UTJ dari kartu follow-up.
// Efek: pindah stage + status lead jadi DONE + catat event (masuk Riwayat).
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const { stage } = schema.parse(await req.json());

    const deal = await prisma.deal.findUnique({ where: { id }, select: { id: true, stage: true, batal: true, unitId: true } });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");
    if (deal.batal) throw new ApiError(400, "Customer sudah batal.");
    if (STAGE_INDEX[deal.stage] >= STAGE_INDEX[stage]) {
      throw new ApiError(400, `Customer sudah di tahap ${deal.stage} — tidak bisa mundur ke ${stage}.`);
    }

    const today = wibToday();
    await prisma.$transaction(async (tx) => {
      const data: Record<string, unknown> = {
        stage,
        statusLead: "DONE", // status lead selesai begitu jadi NUP/UTJ
        [DATE_FIELD[stage]]: today,
      };
      if (stage === "UTJ" && !deal.unitId) data.waitingList = true;
      await tx.deal.update({ where: { id }, data });
      await tx.pipelineEvent.create({
        data: {
          dealId: id,
          type: stage as EventType,
          eventDate: today,
          fromStage: deal.stage,
          toStage: stage,
          actorId: session.user.id,
        },
      });
    });

    return ok({ ok: true, stage });
  } catch (e) {
    return handleError(e);
  }
}
