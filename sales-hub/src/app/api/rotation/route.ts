import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  agencyId: z.string().min(1),
  coordinatorId: z.string().min(1),
  effectiveFrom: z.string().optional(), // YYYY-MM-DD
});

// Reassign a bendera to a coordinator (Sales/Management). Effective-dated:
// the previous open assignment is closed the day before, preserving history so
// past reports still resolve the coordinator valid at their time.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const body = schema.parse(await req.json());

    const from = body.effectiveFrom
      ? new Date(`${body.effectiveFrom}T00:00:00.000Z`)
      : wibToday();
    const dayBefore = new Date(from.getTime() - 24 * 3600_000);

    await prisma.$transaction(async (tx) => {
      const open = await tx.rotationAssignment.findMany({
        where: { agencyId: body.agencyId, effectiveTo: null },
      });
      for (const a of open) {
        if (a.coordinatorId === body.coordinatorId) continue;
        await tx.rotationAssignment.update({
          where: { id: a.id },
          data: { effectiveTo: dayBefore },
        });
      }
      const exists = open.find((a) => a.coordinatorId === body.coordinatorId);
      if (!exists) {
        await tx.rotationAssignment.create({
          data: {
            agencyId: body.agencyId,
            coordinatorId: body.coordinatorId,
            effectiveFrom: from,
          },
        });
      }
      await tx.auditLog.create({
        data: {
          actorId: session.user.id,
          entity: "RotationAssignment",
          entityId: body.agencyId,
          action: "reassign",
          after: { coordinatorId: body.coordinatorId, effectiveFrom: from.toISOString() },
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
