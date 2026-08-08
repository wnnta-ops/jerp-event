// One-off: split existing "Perizinan" documents into the new first-class types
// SKRK / PBG / PetaBidang, based on each doc's filename (or title as fallback).
// Docs that are genuinely other technical permits (Pertek, RKKPR, SK LSD…)
// stay as "Perizinan". Re-runnable + idempotent.
//   Run: node scripts/reclassify-perizinan.mjs
import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

function retype(signal) {
  const s = (signal || "").toUpperCase();
  if (/\bSKRK\b/.test(s)) return "SKRK";
  if (/\bPBG\b|PERSETUJUAN BANGUNAN/.test(s)) return "PBG";
  if (/PETA BIDANG|\bPBT\b/.test(s)) return "PetaBidang";
  return "Perizinan"; // leave as catch-all
}

async function main() {
  const docs = await prisma.document.findMany({
    where: { docType: "Perizinan" },
    select: { id: true, title: true, files: { select: { fileName: true }, take: 1 } },
  });
  const counts = { SKRK: 0, PBG: 0, PetaBidang: 0, Perizinan: 0 };
  for (const d of docs) {
    const signal = d.files[0]?.fileName || d.title;
    const next = retype(signal);
    counts[next]++;
    if (next !== "Perizinan") {
      await prisma.document.update({ where: { id: d.id }, data: { docType: next } });
    }
  }
  console.log(`Scanned ${docs.length} "Perizinan" docs →`, counts);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
