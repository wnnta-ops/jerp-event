import { Resvg } from "@resvg/resvg-js";
import { getSession } from "@/lib/auth";
import { salesDashboardData } from "@/lib/derivations";
import { buildSalesDashboardSVG } from "@/lib/report-svg";
import { parseDayKey, wibToday, dayKey } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET /api/report/dashboard?date=YYYY-MM-DD[&download=1] -> Sales Dashboard PNG (Format 2).
export async function GET(req: Request) {
  const session = await getSession();
  if (!session?.user) return new Response("Unauthorized", { status: 401 });

  const url = new URL(req.url);
  const dateParam = url.searchParams.get("date");
  const download = url.searchParams.get("download") === "1";
  const day = dateParam ? parseDayKey(dateParam) : wibToday();

  const data = await salesDashboardData(day);
  const svg = buildSalesDashboardSVG(data);
  const png = new Resvg(svg, { fitTo: { mode: "width", value: 760 } }).render().asPng();

  return new Response(new Uint8Array(png), {
    headers: {
      "Content-Type": "image/png",
      "Cache-Control": "no-store",
      "Content-Disposition": download
        ? `attachment; filename="Sales Dashboard ${dayKey(day)}.png"`
        : "inline",
    },
  });
}
