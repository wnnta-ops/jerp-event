import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";

const schema = z.object({
  status: z.enum(["OPEN_STOCK", "HOLD", "PROSES", "REALISASI", "CASH_LUNAS", "BATAL"]),
});

// Manual status override on a unit (Sales/Management). Marks statusOverride so
// the auto-derivation from deal stage no longer clobbers it.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());
    const unit = await prisma.unit.findUnique({ where: { id } });
    if (!unit) throw new ApiError(404, "Unit tidak ditemukan.");

    await prisma.$transaction(async (tx) => {
      await tx.unit.update({
        where: { id },
        data: { status: body.status, statusOverride: true },
      });
      await tx.auditLog.create({
        data: {
          actorId: session.user.id,
          entity: "Unit",
          entityId: id,
          action: "status_override",
          before: { status: unit.status },
          after: { status: body.status },
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
