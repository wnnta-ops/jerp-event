import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  note: z.string().min(1),
  statusLead: z.enum(["HOT", "WARM", "COLD"]),
});

// Add a follow-up to a deal (Sales-owned). Updates lead status + FU count.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());
    const deal = await prisma.deal.findUnique({ where: { id } });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");
    const today = wibToday();

    await prisma.$transaction(async (tx) => {
      await tx.followUp.create({
        data: {
          dealId: id,
          date: today,
          note: body.note,
          statusLead: body.statusLead,
          actorId: session.user.id,
        },
      });
      await tx.deal.update({
        where: { id },
        data: {
          fuCount: { increment: 1 },
          statusLead: body.statusLead,
          catatan: body.note,
          stage: deal.stage === "WI" ? "FOLLOW_UP" : deal.stage,
        },
      });
      await tx.pipelineEvent.create({
        data: {
          dealId: id,
          type: "FOLLOW_UP",
          eventDate: today,
          toStage: "FOLLOW_UP",
          actorId: session.user.id,
          meta: { note: body.note, statusLead: body.statusLead },
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
