import { NextRequest } from "next/server";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { renderDocx, buildPdf, safeFileBase } from "@/lib/templates";
import { docxToPdf } from "@/lib/docx-to-pdf";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const DOCX_MIME = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";

/** Generate a filled document from a template. POST { values, format: "docx"|"pdf" }. */
export async function POST(req: NextRequest, { params }: { params: { id: string } }) {
  const acting = await getActingUser();
  if (!acting) return new Response("Unauthorized", { status: 401 });

  let body: { values?: Record<string, string>; format?: string };
  try {
    body = await req.json();
  } catch {
    return new Response("Bad request", { status: 400 });
  }
  const values = body.values ?? {};
  const format = body.format === "pdf" ? "pdf" : "docx";

  const tpl = await prisma.documentTemplate.findUnique({ where: { id: params.id } });
  if (!tpl) return new Response("Template tidak ditemukan", { status: 404 });

  const buf = Buffer.from(tpl.data);
  let docx: Buffer, text: string;
  try {
    ({ docx, text } = renderDocx(buf, values));
  } catch {
    return new Response("Gagal membuat dokumen dari template", { status: 500 });
  }

  const base = safeFileBase(tpl.name);

  if (format === "pdf") {
    // Prefer a pixel-faithful PDF via LibreOffice (same layout as the Word file).
    // Falls back to the simple text PDF if LibreOffice isn't available (e.g. Vercel).
    const pdf = (await docxToPdf(docx)) ?? (await buildPdf(text, tpl.name));
    return new Response(new Uint8Array(pdf), {
      headers: {
        "Content-Type": "application/pdf",
        "Content-Disposition": `attachment; filename="${base}.pdf"`,
        "Cache-Control": "no-store",
      },
    });
  }

  return new Response(new Uint8Array(docx), {
    headers: {
      "Content-Type": DOCX_MIME,
      "Content-Disposition": `attachment; filename="${base}.docx"`,
      "Cache-Control": "no-store",
    },
  });
}
