import PizZip from "pizzip";
import Docxtemplater from "docxtemplater";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";

// Placeholders use {{double braces}} so they don't clash with literal { } text.
const DELIMITERS = { start: "{{", end: "}}" };

export type TemplateField = { key: string; label: string };

function humanize(key: string): string {
  const s = key.replace(/[_\-.]+/g, " ").replace(/\s+/g, " ").trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Detect {{placeholder}} tags from a .docx buffer (robust to Word run-splits). */
export function parseFields(buf: Buffer): TemplateField[] {
  const zip = new PizZip(buf);
  const doc = new Docxtemplater(zip, { delimiters: DELIMITERS, paragraphLoop: true, linebreaks: true });
  const text = doc.getFullText();
  const seen = new Set<string>();
  const fields: TemplateField[] = [];
  for (const m of text.matchAll(/\{\{\s*([^{}]+?)\s*\}\}/g)) {
    const key = m[1].trim();
    if (key && !seen.has(key)) {
      seen.add(key);
      fields.push({ key, label: humanize(key) });
    }
  }
  return fields;
}

/** Fill the template with values; returns the rendered .docx + its plain text. */
export function renderDocx(buf: Buffer, values: Record<string, string>): { docx: Buffer; text: string } {
  const zip = new PizZip(buf);
  const doc = new Docxtemplater(zip, {
    delimiters: DELIMITERS,
    paragraphLoop: true,
    linebreaks: true,
    nullGetter: () => "", // missing values -> empty, never throw
  });
  doc.render(values);
  const docx = doc.getZip().generate({ type: "nodebuffer", compression: "DEFLATE" });
  return { docx, text: doc.getFullText() };
}

// pdf-lib standard fonts encode WinAnsi (Latin-1); map the common typographic
// characters and drop anything else unencodable so drawText never throws.
function pdfSafe(s: string): string {
  return s
    .replace(/[‘’‚′]/g, "'")
    .replace(/[“”„″]/g, '"')
    .replace(/[–—]/g, "-")
    .replace(/…/g, "...")
    .replace(/→/g, "->")
    .replace(/ /g, " ")
    // eslint-disable-next-line no-control-regex
    .replace(/[^\x09\x0A\x0D\x20-\xFF]/g, "");
}

/** Lay out plain text into a clean A4 PDF (one paragraph per line break). */
export async function buildPdf(text: string, title?: string): Promise<Buffer> {
  const pdf = await PDFDocument.create();
  const font = await pdf.embedFont(StandardFonts.TimesRoman);
  const bold = await pdf.embedFont(StandardFonts.TimesRomanBold);
  const [W, H] = [595.28, 841.89]; // A4
  const margin = 64;
  const size = 11;
  const lineH = size * 1.55;
  const maxW = W - margin * 2;
  const ink = rgb(0.1, 0.1, 0.12);

  let page = pdf.addPage([W, H]);
  let y = H - margin;
  const newline = (h = lineH) => {
    y -= h;
    if (y < margin) {
      page = pdf.addPage([W, H]);
      y = H - margin;
    }
  };
  const draw = (line: string, f = font, fs = size) => {
    page.drawText(line, { x: margin, y, size: fs, font: f, color: ink });
  };

  if (title) {
    const t = pdfSafe(title);
    draw(t, bold, 15);
    newline(lineH * 1.6);
  }

  for (const rawPara of pdfSafe(text).split(/\r?\n/)) {
    const para = rawPara.replace(/\s+$/g, "");
    if (para.trim() === "") {
      newline(lineH * 0.7);
      continue;
    }
    const words = para.split(/\s+/);
    let line = "";
    for (const w of words) {
      const test = line ? `${line} ${w}` : w;
      if (font.widthOfTextAtSize(test, size) > maxW && line) {
        draw(line);
        newline();
        line = w;
      } else {
        line = test;
      }
    }
    if (line) {
      draw(line);
      newline();
    }
  }

  return Buffer.from(await pdf.save());
}

type DocxError = {
  message?: string;
  properties?: {
    explanation?: string;
    errors?: Array<{ properties?: { context?: string; explanation?: string } }>;
  };
};

/** Turn a docxtemplater parse error into a human, actionable Indonesian message. */
export function templateErrorMessage(e: unknown): string {
  const err = e as DocxError;
  const errors = err?.properties?.errors;
  if (Array.isArray(errors) && errors.length) {
    const spots = Array.from(
      new Set(
        errors
          .map((x) => x?.properties?.context)
          .filter((c): c is string => !!c)
          .map((c) => c.replace(/\s+/g, " ").trim().slice(0, 36)),
      ),
    ).slice(0, 5);
    if (spots.length) {
      return `Ada placeholder yang kurang kurung tutup — pakai tepat DUA kurung: {{nama}}. Periksa bagian ini di dokumen: ${spots.map((s) => `“${s}…”`).join(" · ")}`;
    }
  }
  const expl = err?.properties?.explanation;
  if (expl) return `Template bermasalah: ${expl}`;
  return "Gagal membaca .docx. Pastikan placeholder pakai format {{nama}} dan file Word valid.";
}

export function safeFileBase(name: string): string {
  return (name.replace(/[^a-zA-Z0-9._ -]+/g, "_").replace(/\s+/g, " ").trim() || "dokumen").slice(0, 80);
}
