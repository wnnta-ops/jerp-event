import { NextRequest } from "next/server";
import ExcelJS from "exceljs";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { DOC_CATEGORIES } from "@/lib/enums";
import { EXPORT_COLUMNS, type ExportDoc } from "@/lib/export-spec";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const FIELDS = {
  title: true, docType: true, status: true, docNumber: true, cluster: true,
  issueDate: true, expiryDate: true, landArea: true, objectLocation: true,
  originalLocation: true, originalLocationDetail: true, entityName: true,
  boardStructure: true, authorizedCapital: true, issuedCapital: true,
  shareholders: true, parties: true,
} as const;

/**
 * Export the directory to Excel, one sheet per tab — matching the reference
 * workbook "tabel data export Legal.xlsx" (+ a STATUS column for keaktifan).
 *   /api/export            -> all tabs (5 sheets)
 *   /api/export?tab=perizinan -> just that tab (1 sheet)
 */
export async function GET(req: NextRequest) {
  const acting = await getActingUser();
  if (!acting) return new Response("Unauthorized", { status: 401 });

  const tab = (req.nextUrl.searchParams.get("tab") || "all").toLowerCase();
  const cats =
    tab === "all" || tab === "semua"
      ? DOC_CATEGORIES
      : DOC_CATEGORIES.filter((c) => c.key === tab);
  if (cats.length === 0) return new Response("Unknown tab", { status: 400 });

  const wb = new ExcelJS.Workbook();
  wb.creator = "Legal · MUP";
  wb.created = new Date();

  for (const cat of cats) {
    const cols = EXPORT_COLUMNS[cat.key];
    if (!cols) continue;

    const ws = wb.addWorksheet(cat.label.toUpperCase());
    ws.columns = cols.map((c) => ({ header: c.header, width: c.width }));
    const head = ws.getRow(1);
    head.font = { bold: true, color: { argb: "FFFFFFFF" } };
    head.alignment = { vertical: "middle" };
    head.eachCell((cell) => {
      cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FF1F4E78" } };
    });
    ws.views = [{ state: "frozen", ySplit: 1 }];
    ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: cols.length } };

    // Only types belonging to this tab (Aset has its own single type).
    const docs = cat.types.length
      ? ((await prisma.document.findMany({
          where: { docType: { in: cat.types } },
          orderBy: [{ docType: "asc" }, { title: "asc" }],
          select: FIELDS,
        })) as ExportDoc[])
      : [];

    for (const doc of docs) ws.addRow(cols.map((c) => c.get(doc)));
  }

  const buf = await wb.xlsx.writeBuffer();
  const single = cats.length === 1 ? cats[0].label : "Direktori";
  const stamp = new Date().toISOString().slice(0, 10);
  const filename = `Legal - ${single} (${stamp}).xlsx`;

  return new Response(buf, {
    headers: {
      "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "Content-Disposition": `attachment; filename="${filename}"`,
      "Cache-Control": "no-store",
    },
  });
}
