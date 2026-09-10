import ExcelJS from "exceljs";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { getMatrixReport, MATRIX_COLS, MATRIX_ROWS } from "@/lib/matrix";
import { parseDayKey, wibToday, dayKey as toKey } from "@/lib/wib";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const DATA_COLS = MATRIX_COLS.map((c) => c.key); // 7 keys

// GET /api/matrix/export?date=YYYY-MM-DD -> .xlsx in the Matrix layout.
export async function GET(req: Request) {
  const session = await getSession();
  if (!session?.user) return new Response("Unauthorized", { status: 401 });

  const url = new URL(req.url);
  const dParam = url.searchParams.get("date");
  const day = dParam ? parseDayKey(dParam) : wibToday();
  const dKey = toKey(day);

  const [report, entries] = await Promise.all([
    getMatrixReport(day),
    prisma.matrixEntry.findMany({ where: { dayKey: dKey } }),
  ]);
  const manual = new Map<string, number>();
  for (const e of entries) manual.set(`${e.rowKey}::${e.colKey}`, e.value);
  const man = (row: string, col: string) => manual.get(`${row}::${col}`) ?? null;

  const cellValue = (row: (typeof MATRIX_ROWS)[number], col: string): number | null => {
    if (!row.key) return null;
    if (row.kind === "input") return man(row.key, col);
    if (col === "c1") return man(row.key, "c1");
    return report.derived[row.key]?.[col] ?? 0;
  };

  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet("Matrix", { views: [{ state: "frozen", ySplit: 6 }] });
  ws.columns = [
    { width: 30 },
    ...DATA_COLS.map(() => ({ width: 10 })),
    { width: 10 },
    { width: 10 },
    { width: 8 },
  ];
  const NCOL = 1 + DATA_COLS.length + 3; // 11
  const border = { style: "thin" as const, color: { argb: "FFBFBFBF" } };
  const allBorder = { top: border, left: border, bottom: border, right: border };

  // title
  ws.mergeCells(1, 1, 1, NCOL);
  const t = ws.getCell(1, 1);
  t.value = "MATRIX DAILY SALES REPORT";
  t.font = { bold: true, size: 14 };
  t.alignment = { horizontal: "center" };
  ws.getCell(2, 1).value = `Report per Tanggal :   ${report.dateLabel}`;
  ws.getCell(2, 1).font = { bold: true };

  // header block rows 4-6
  ws.mergeCells(4, 1, 6, 1);
  ws.getCell(4, 1).value = "Keterangan";
  ws.mergeCells(4, 2, 4, 8);
  ws.getCell(4, 2).value = "Cluster";
  ws.mergeCells(4, 9, 6, 9);
  ws.getCell(4, 9).value = "TOTAL";
  ws.mergeCells(4, 10, 6, 10);
  ws.getCell(4, 10).value = "TARGET";
  ws.mergeCells(4, 11, 6, 11);
  ws.getCell(4, 11).value = "Dev";

  const tu = report.derived.TOTAL_UNIT;
  const c3tot = (tu.c31_32 ?? 0) + (tu.c33 ?? 0) + (tu.c34 ?? 0);
  const c5tot = (tu.c51 ?? 0) + (tu.c52 ?? 0);
  ws.getCell(5, 2).value = "C1";
  ws.getCell(5, 3).value = "C2";
  ws.mergeCells(5, 4, 5, 6);
  ws.getCell(5, 4).value = `TOTAL C3 : ${c3tot}`;
  ws.mergeCells(5, 7, 5, 8);
  ws.getCell(5, 7).value = `TOTAL C5 : ${c5tot}`;
  MATRIX_COLS.forEach((c, i) => (ws.getCell(6, 2 + i).value = c.label));

  for (let r = 4; r <= 6; r++)
    for (let c = 1; c <= NCOL; c++) {
      const cell = ws.getCell(r, c);
      cell.border = allBorder;
      cell.font = { bold: true, size: 10 };
      cell.alignment = { horizontal: "center", vertical: "middle" };
      cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFF6C9A8" } };
    }

  // data rows
  let r = 7;
  for (const row of MATRIX_ROWS) {
    if (row.sep) {
      r++;
      continue;
    }
    if (row.section) {
      ws.mergeCells(r, 1, r, NCOL);
      const c = ws.getCell(r, 1);
      c.value = row.section + (row.note ? `        ${row.note}` : "");
      c.font = { bold: true };
      c.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFE2EFDA" } };
      c.border = allBorder;
      r++;
      continue;
    }
    const label = ws.getCell(r, 1);
    label.value = row.label ?? "";
    label.font = { bold: !!row.strong };
    label.border = allBorder;
    if (row.hi) label.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFFFF200" } };

    let total = 0;
    DATA_COLS.forEach((col, i) => {
      const v = cellValue(row, col);
      total += v ?? 0;
      const cell = ws.getCell(r, 2 + i);
      cell.value = v === null ? "" : v === 0 ? "-" : v;
      cell.alignment = { horizontal: "center" };
      cell.border = allBorder;
      cell.font = { bold: !!row.strong };
      if (row.hi) cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFFFF200" } };
    });
    const target = man(row.key!, "target");
    const totalCell = ws.getCell(r, 9);
    totalCell.value = total === 0 ? "-" : total;
    totalCell.alignment = { horizontal: "center" };
    totalCell.font = { bold: true };
    totalCell.border = allBorder;
    const targetCell = ws.getCell(r, 10);
    targetCell.value = target ?? "";
    targetCell.alignment = { horizontal: "center" };
    targetCell.border = allBorder;
    const devCell = ws.getCell(r, 11);
    devCell.value = target !== null ? total - target : "";
    devCell.alignment = { horizontal: "center" };
    devCell.border = allBorder;
    r++;
  }

  const buf = await wb.xlsx.writeBuffer();
  return new Response(buf, {
    headers: {
      "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "Content-Disposition": `attachment; filename="Matrix Daily Sales Report ${dKey}.xlsx"`,
      "Cache-Control": "no-store",
    },
  });
}
