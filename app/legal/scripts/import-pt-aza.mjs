// PT AZA ANUGERAH PRATAMA — entity/corporate documents from the new source.
// ADDITIVE (does not wipe; AJB did the clean slate). Idempotent: clears this
// entity's docs first, then re-inserts. Files referenced in place (never opened).
// Per data owner: append " (PT AZA AP)" to each doc name (search is by name).
//   Run: node scripts/import-pt-aza.mjs
import { readdirSync, statSync } from "fs";
import path from "path";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const ROOT_PARENT = path.resolve(process.cwd(), "..");
const ENTITY = "PT AZA Anugerah Pratama";
const TAG = "(PT AZA AP)";
const SRC = path.join(ROOT_PARENT, "DASHBOARD DATA USED", "PT.AZA ANUGERAH PRATAMA");
const DOC_EXT = new Set([".pdf", ".jpg", ".jpeg", ".png", ".xlsx", ".pptx", ".docx"]);
const SKIP = /thumbs\.db|\.ds_store|^~\$/i;
const MIME = {
  ".pdf": "application/pdf", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
  ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  ".pptx": "application/vnd.openxmlformats-officedocument.presentationml.presentation",
  ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
};

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    if (SKIP.test(name)) continue;
    const full = path.join(dir, name);
    let st; try { st = statSync(full); } catch { continue; }
    if (st.isDirectory()) walk(full, out);
    else if (DOC_EXT.has(path.extname(name).toLowerCase())) out.push(full);
  }
  return out;
}

function classify(full) {
  const name = path.basename(full).toUpperCase();
  const dir = path.dirname(full).toUpperCase();
  if (/MENKUMHAM|KUMHAM/.test(name)) return "SKKumham";
  if (/PENDIRIAN/.test(name)) return "AktaPendirian";
  if (/RUPS|PEMEGANG SAHAM|JUAL BELI SAHAM|PERNYATAAN KEPUTUSAN/.test(name)) return "RUPS";
  if (/AKTA PENDIRIAN/.test(dir)) return "AktaPendirian";
  if (/RUPS/.test(dir)) return "RUPS";
  return "Other";
}

// "after the file name add (PT AZA AP)" — but don't double-tag names that already say AZA AP.
function titleFor(full) {
  const base = path.basename(full, path.extname(full)).trim();
  return /AZA\s*AP|AZA ANUGERAH/i.test(base) ? base : `${base} ${TAG}`;
}

async function main() {
  const head = await prisma.user.findFirst({ where: { role: "HEAD_OF_LEGAL" } })
    || await prisma.user.findFirst({ where: { role: "LEGAL_PIC" } });
  if (!head) { console.error("No legal users. Run `npm run db:seed` first."); process.exit(1); }

  const files = walk(SRC);
  const removed = await prisma.document.deleteMany({ where: { entityName: ENTITY } });
  if (removed.count) console.log(`Re-run: removed ${removed.count} existing ${ENTITY} doc(s).`);

  const counts = {};
  for (const f of files) {
    const type = classify(f);
    counts[type] = (counts[type] || 0) + 1;
    const rel = f.replace(ROOT_PARENT + path.sep, "");
    const fileName = path.basename(f);
    await prisma.document.create({
      data: {
        docType: type,
        title: titleFor(f),
        scope: "entity",
        entityName: ENTITY,
        status: "Active",
        picId: head.id,
        uploadedById: head.id,
        originalLocation: "OfficeSafe",
        accessLevel: "LegalOnly",
        files: { create: [{ url: `/api/file?p=${encodeURIComponent(rel)}`, fileName, label: "original (in-place)", mimeType: MIME[path.extname(fileName).toLowerCase()] ?? null }] },
      },
    });
  }
  console.log(`Imported ${files.length} ${ENTITY} docs →`, counts);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
