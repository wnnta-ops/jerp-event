import { Resvg } from "@resvg/resvg-js";
import { getSession } from "@/lib/auth";
import { dailyReportData } from "@/lib/derivations";
import { buildDailyReportSVG } from "@/lib/report-svg";
import { parseDayKey, wibToday, dayKey } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET /api/report/daily?date=YYYY-MM-DD[&download=1] -> PNG infographic for WA.
export async function GET(req: Request) {
  const session = await getSession();
  if (!session?.user) return new Response("Unauthorized", { status: 401 });

  const url = new URL(req.url);
  const dateParam = url.searchParams.get("date");
  const download = url.searchParams.get("download") === "1";
  const today = wibToday();
  const day = dateParam ? parseDayKey(dateParam) : today;

  const data = await dailyReportData(day, today);
  const svg = buildDailyReportSVG(data);

  const resvg = new Resvg(svg, { fitTo: { mode: "width", value: 760 } });
  const png = resvg.render().asPng();

  return new Response(new Uint8Array(png), {
    headers: {
      "Content-Type": "image/png",
      "Cache-Control": "no-store",
      "Content-Disposition": download
        ? `attachment; filename="Daily Report ${dayKey(day)}.png"`
        : "inline",
    },
  });
}
