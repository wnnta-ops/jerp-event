import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";

const schema = z.object({
  global: z.object({
    days: z.coerce.number().int().min(1).max(60),
    notifyApp: z.boolean(),
    notifyWa: z.boolean(),
    active: z.boolean(),
  }),
  overrides: z
    .array(z.object({ coordinatorId: z.string().min(1), days: z.coerce.number().int().min(1).max(60), active: z.boolean() }))
    .default([]),
});

// Save follow-up reminder config. Management + Sales (koor manage their team).
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const body = schema.parse(await req.json());

    const g = await prisma.reminderSetting.findFirst({ where: { coordinatorId: null } });
    if (g) await prisma.reminderSetting.update({ where: { id: g.id }, data: body.global });
    else await prisma.reminderSetting.create({ data: { coordinatorId: null, ...body.global } });

    for (const o of body.overrides) {
      await prisma.reminderSetting.upsert({
        where: { coordinatorId: o.coordinatorId },
        create: { coordinatorId: o.coordinatorId, days: o.days, active: o.active },
        update: { days: o.days, active: o.active },
      });
    }
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
