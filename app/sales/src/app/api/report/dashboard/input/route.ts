import { z } from "zod";
import { Prisma } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { dailyRecapPure, salesDashboardDerived } from "@/lib/derivations";
import { parseDayKey, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET nilai untuk form: `entry` = override manual mentah (null = ikut web);
// `derivedDaily` + `derivedDash` = nilai MURNI dari web (untuk auto-fill default
// & untuk tahu apakah user meng-override).
export async function GET(req: Request) {
  try {
    await requireSession();
    const dateParam = new URL(req.url).searchParams.get("date");
    const day = dateParam ? parseDayKey(dateParam) : wibToday();
    const dk = `${day.getUTCFullYear()}-${String(day.getUTCMonth() + 1).padStart(2, "0")}-${String(day.getUTCDate()).padStart(2, "0")}`;
    const [entry, derivedDaily, derivedDash] = await Promise.all([
      prisma.dailyDashboardEntry.findUnique({ where: { dayKey: dk } }),
      dailyRecapPure(day),
      salesDashboardDerived(day),
    ]);
    return ok({ entry, derivedDaily, derivedDash });
  } catch (e) {
    return handleError(e);
  }
}

const num = z.coerce.number().int().nullable();
const wiArr = z
  .array(z.object({ cluster: z.string(), murni: z.coerce.number().int(), janjian: z.coerce.number().int(), total: z.coerce.number().int().optional() }))
  .nullable();
const blokArr = z.array(z.object({ code: z.string(), n: z.coerce.number().int() })).nullable();
const schema = z.object({
  dayKey: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  // snapshot bersama:
  holdUnit: num.optional(),
  waitingList: num.optional(),
  // Daily Report (harian):
  wiByCluster: wiArr.optional(),
  utjGrossDay: num.optional(),
  utjGagalDay: num.optional(),
  sprGrossDay: num.optional(),
  sprGagalDay: num.optional(),
  nupDay: num.optional(),
  utjPerBlok: blokArr.optional(),
  // Sales Dashboard (bulanan / YTD):
  target2026: num.optional(),
  targetUtj: num.optional(),
  sprToday: num.optional(),
  utjNett: num.optional(),
  nupNett: num.optional(),
  salesPotensial: num.optional(),
  wiHot: num.optional(),
  remainingDays: num.optional(),
});

// Simpan override (Sales / Management). Field yang null = ikut web (linked).
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { dayKey, wiByCluster, utjPerBlok, ...nums } = schema.parse(await req.json());
    const data: Record<string, unknown> = Object.fromEntries(
      Object.entries(nums).map(([k, v]) => [k, v ?? null]),
    );
    // Json fields: array = simpan override; null = hapus override (ikut web).
    if (wiByCluster !== undefined) data.wiByCluster = wiByCluster ?? Prisma.DbNull;
    if (utjPerBlok !== undefined) data.utjPerBlok = utjPerBlok ?? Prisma.DbNull;
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
