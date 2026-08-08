import { PDFDocument, StandardFonts, rgb, degrees } from "pdf-lib";

export type WatermarkResult = { bytes: Uint8Array; ext: string; converted: boolean };

/**
 * Tile a rotated watermark phrase diagonally across every page. Font size and
 * spacing scale to the page so it stays visible on huge pages too (e.g. an
 * image converted to a multi-thousand-pixel PDF page).
 */
function stampPages(pdf: PDFDocument, font: import("pdf-lib").PDFFont, phrase: string) {
  for (const page of pdf.getPages()) {
    const { width, height } = page.getSize();
    const min = Math.min(width, height);
    const size = Math.max(16, Math.round(min / 22)); // ~size relative to page
    const textWidth = font.widthOfTextAtSize(phrase, size);
    const stepX = Math.max(textWidth * 1.25, min / 2.5);
    const stepY = Math.max(size * 5, min / 4);
    for (let y = -height; y < height * 2; y += stepY) {
      for (let x = -width; x < width * 2; x += stepX) {
        page.drawText(phrase, {
          x, y, size, font,
          color: rgb(0.42, 0.42, 0.48),
          rotate: degrees(45),
          opacity: 0.22,
        });
      }
    }
  }
}

/** Watermark a PDF in place. */
async function watermarkPdf(input: Uint8Array, phrase: string): Promise<Uint8Array> {
  const pdf = await PDFDocument.load(input, { ignoreEncryption: true });
  const font = await pdf.embedFont(StandardFonts.HelveticaBold);
  stampPages(pdf, font, phrase);
  return pdf.save();
}

/** Embed an image as a full-page PDF, then watermark — output is always a watermarked PDF. */
async function watermarkImageToPdf(input: Uint8Array, mime: string, phrase: string): Promise<Uint8Array> {
  const pdf = await PDFDocument.create();
  const img = /png/i.test(mime) ? await pdf.embedPng(input) : await pdf.embedJpg(input);
  const page = pdf.addPage([img.width, img.height]);
  page.drawImage(img, { x: 0, y: 0, width: img.width, height: img.height });
  const font = await pdf.embedFont(StandardFonts.HelveticaBold);
  stampPages(pdf, font, phrase);
  return pdf.save();
}

/**
 * Apply a traceable watermark to a fulfillment file BEFORE it is stored.
 * - PDF        → stamped in place (stays .pdf)
 * - JPG / PNG  → wrapped into a watermarked PDF (becomes .pdf)
 * - other      → returned unchanged (cannot safely watermark)
 */
export async function watermarkFile(input: Uint8Array, mime: string, ext: string, phrase: string): Promise<WatermarkResult> {
  const e = ext.toLowerCase().replace(/^\./, "");
  try {
    if (e === "pdf" || /pdf/i.test(mime)) {
      return { bytes: await watermarkPdf(input, phrase), ext: "pdf", converted: false };
    }
    if (["jpg", "jpeg", "png"].includes(e) || /image\/(jpe?g|png)/i.test(mime)) {
      return { bytes: await watermarkImageToPdf(input, mime, phrase), ext: "pdf", converted: true };
    }
  } catch {
    // fall through — store the original rather than failing the handover
  }
  return { bytes: input, ext: e || "bin", converted: false };
}
