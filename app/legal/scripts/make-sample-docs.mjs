// Generate SAMPLE placeholder documents — one A4 PDF per DocumentFile whose only
// content is the file's own name. Keeps the real names/metadata, but NO real
// document content (no PII) is ever uploaded. Output → public/sample/<id>.pdf
// (statically served, deploy-safe). Re-points every DocumentFile to its placeholder.
//   Run: node scripts/make-sample-docs.mjs
import { mkdir, writeFile, rm } from "fs/promises";
import path from "path";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const OUT = path.join(process.cwd(), "public", "sample");
const A4 = [595.28, 841.89];
const MARGIN = 56;

function wrap(text, font, size, maxW) {
  const lines = [];
  let line = "";
  for (let word of text.split(/\s+/)) {
    // hard-break a single token that's wider than the page
    while (font.widthOfTextAtSize(word, size) > maxW) {
      if (line) { lines.push(line); line = ""; }
      let i = 1;
      while (i < word.length && font.widthOfTextAtSize(word.slice(0, i + 1), size) <= maxW) i++;
      lines.push(word.slice(0, i));
      word = word.slice(i);
    }
    const trial = line ? `${line} ${word}` : word;
    if (font.widthOfTextAtSize(trial, size) > maxW) { if (line) lines.push(line); line = word; }
    else line = trial;
  }
  if (line) lines.push(line);
  return lines;
}

async function main() {
  // Clear stale placeholders (e.g. from a previous DB with different ids).
  await rm(OUT, { recursive: true, force: true });
  await mkdir(OUT, { recursive: true });
  const files = await prisma.documentFile.findMany({ select: { id: true, fileName: true } });
  const maxW = A4[0] - MARGIN * 2;
  let n = 0;
  for (const f of files) {
    const pdf = await PDFDocument.create();
    const page = pdf.addPage(A4);
    const font = await pdf.embedFont(StandardFonts.Helvetica);
    const bold = await pdf.embedFont(StandardFonts.HelveticaBold);

    page.drawText("DOKUMEN CONTOH", { x: MARGIN, y: A4[1] - MARGIN, size: 11, font: bold, color: rgb(0.5, 0.5, 0.55) });
    page.drawText("Java Residence · Legal — konten asli tidak diunggah", { x: MARGIN, y: A4[1] - MARGIN - 16, size: 9, font, color: rgb(0.6, 0.6, 0.65) });

    // The filename IS the content.
    let y = A4[1] - MARGIN - 64;
    for (const line of wrap(f.fileName, bold, 16, maxW)) {
      page.drawText(line, { x: MARGIN, y, size: 16, font: bold, color: rgb(0.12, 0.12, 0.14) });
      y -= 24;
    }

    await writeFile(path.join(OUT, `${f.id}.pdf`), Buffer.from(await pdf.save()));
    await prisma.documentFile.update({ where: { id: f.id }, data: { url: `/sample/${f.id}.pdf`, mimeType: "application/pdf" } });
    n++;
    if (n % 100 === 0) process.stdout.write(`\r  ${n}/${files.length}`);
  }
  console.log(`\nGenerated ${n} sample placeholder PDFs → public/sample/ and re-pointed all DocumentFiles.`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
