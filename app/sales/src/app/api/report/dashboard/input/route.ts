import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { salesDashboardData } from "@/lib/derivations";
import { parseDayKey, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET current resolved values for a day (to prefill the input form).
export async function GET(req: Request) {
  try {
    await requireSession();
    const dateParam = new URL(req.url).searchParams.get("date");
    const day = dateParam ? parseDayKey(dateParam) : wibToday();
    return ok(await salesDashboardData(day));
  } catch (e) {
    return handleError(e);
  }
}

const num = z.coerce.number().int().nullable();
const schema = z.object({
  dayKey: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  target2026: num.optional(),
  sprToday: num.optional(),
  targetUtj: num.optional(),
  utjNett: num.optional(),
  nupNett: num.optional(),
  holdUnit: num.optional(),
  waitingList: num.optional(),
  salesPotensial: num.optional(),
  wiHot: num.optional(),
  remainingDays: num.optional(),
});

// Save manual Sales Dashboard values for a day (Sales / Management).
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { dayKey, ...fields } = schema.parse(await req.json());
    const data = Object.fromEntries(
      Object.entries(fields).map(([k, v]) => [k, v ?? null]),
    );
    await prisma.dailyDashboardEntry.upsert({
      where: { dayKey },
      create: { dayKey, ...data },
      update: data,
    });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
