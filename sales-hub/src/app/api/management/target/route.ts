import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { monthKey, wibToday } from "@/lib/wib";
import { ANNUAL_TARGET_PERIOD } from "@/lib/constants";

const schema = z.object({
  annualSpr: z.coerce.number().int().min(0),
  monthlyUnit: z.coerce.number().int().min(0),
  teams: z.array(z.object({ id: z.string().min(1), value: z.coerce.number().int().min(0) })).default([]),
});

// Save targets (Management only). Feeds Dashboard & Executive Summary.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["MANAGEMENT"]);
    const body = schema.parse(await req.json());
    const mk = monthKey(wibToday());

    const upsertTarget = async (
      scope: "ANNUAL" | "MONTHLY" | "TEAM_MONTH",
      metric: "UNIT" | "SPR",
      period: string,
      teamId: string | null,
      value: number,
    ) => {
      const existing = await prisma.target.findFirst({ where: { scope, metric, period, teamId } });
      if (existing) await prisma.target.update({ where: { id: existing.id }, data: { value } });
      else await prisma.target.create({ data: { scope, metric, period, teamId, value } });
    };

    await upsertTarget("ANNUAL", "SPR", ANNUAL_TARGET_PERIOD, null, body.annualSpr);
    await upsertTarget("MONTHLY", "UNIT", mk, null, body.monthlyUnit);
    for (const t of body.teams) await upsertTarget("TEAM_MONTH", "UNIT", mk, t.id, t.value);

    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
