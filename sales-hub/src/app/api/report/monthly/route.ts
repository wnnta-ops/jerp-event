import { Resvg } from "@resvg/resvg-js";
import { getSession } from "@/lib/auth";
import { monthlySeries, yearlySeries } from "@/lib/derivations";
import { buildBarSVG } from "@/lib/report-svg";
import { wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

// GET /api/report/monthly?view=bulan|tahun[&download=1] -> PNG bar chart.
export async function GET(req: Request) {
  const session = await getSession();
  if (!session?.user) return new Response("Unauthorized", { status: 401 });

  const url = new URL(req.url);
  const view = url.searchParams.get("view") === "tahun" ? "tahun" : "bulan";
  const download = url.searchParams.get("download") === "1";
  const year = wibToday().getUTCFullYear();

  let data: { b: string; net: number }[];
  let title: string;
  if (view === "tahun") {
    data = await yearlySeries([year - 2, year - 1, year]);
    title = "UTJ Net per Tahun";
  } else {
    data = await monthlySeries(year);
    title = `UTJ Net per Bulan — ${year}`;
  }

  const svg = buildBarSVG(data, title);
  const png = new Resvg(svg, { fitTo: { mode: "width", value: 760 } }).render().asPng();

  return new Response(new Uint8Array(png), {
    headers: {
      "Content-Type": "image/png",
      "Cache-Control": "no-store",
      "Content-Disposition": download ? `attachment; filename="${title}.png"` : "inline",
    },
  });
}
