import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { dailyRecap, salesDashboardData } from "@/lib/derivations";
import { parseDayKey, wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET nilai untuk mengisi form: `entry` = nilai manual mentah (untuk input);
// `daily` + `dashboard` = nilai turunan/linked (untuk ditampilkan read-only).
export async function GET(req: Request) {
  try {
    await requireSession();
    const dateParam = new URL(req.url).searchParams.get("date");
    const day = dateParam ? parseDayKey(dateParam) : wibToday();
    const dk = `${day.getUTCFullYear()}-${String(day.getUTCMonth() + 1).padStart(2, "0")}-${String(day.getUTCDate()).padStart(2, "0")}`;
    const [entry, daily, dashboard] = await Promise.all([
      prisma.dailyDashboardEntry.findUnique({ where: { dayKey: dk } }),
      dailyRecap(day),
      salesDashboardData(day),
    ]);
    return ok({ entry, daily, dashboard });
  } catch (e) {
    return handleError(e);
  }
}

const num = z.coerce.number().int().nullable();
const blok = z.array(z.object({ code: z.string(), n: z.coerce.number().int() }));
const schema = z.object({
  dayKey: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  // Sales Dashboard (bulanan) — manual:
  sprToday: num.optional(),
  utjNett: num.optional(),
  nupNett: num.optional(),
  // Daily Report (harian) — manual:
  utjGrossDay: num.optional(),
  sprGrossDay: num.optional(),
  nupDay: num.optional(),
  utjPerBlok: blok.nullable().optional(),
});

// Simpan HANYA field manual (Sales / Management). Field linked tidak diterima.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { dayKey, utjPerBlok, ...nums } = schema.parse(await req.json());
    const data: Record<string, unknown> = Object.fromEntries(
      Object.entries(nums).map(([k, v]) => [k, v ?? null]),
    );
    if (utjPerBlok !== undefined) data.utjPerBlok = utjPerBlok ?? undefined;
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
