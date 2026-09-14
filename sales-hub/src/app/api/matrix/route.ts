import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";

const schema = z.object({
  dayKey: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  rowKey: z.string().min(1),
  colKey: z.string().min(1),
  value: z.number().int().nullable(),
});

// Save (or clear) one manual Matrix cell. Sales + Management.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { dayKey, rowKey, colKey, value } = schema.parse(await req.json());

    if (value === null) {
      await prisma.matrixEntry.deleteMany({ where: { dayKey, rowKey, colKey } });
      return ok({ ok: true, cleared: true });
    }
    await prisma.matrixEntry.upsert({
      where: { dayKey_rowKey_colKey: { dayKey, rowKey, colKey } },
      create: { dayKey, rowKey, colKey, value, updatedBy: session.user.id },
      update: { value, updatedBy: session.user.id },
    });
    return ok();
  } catch (e) {
    return handleError(e);
  }
}
