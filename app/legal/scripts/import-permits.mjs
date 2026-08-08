// REGISTRY IMPORT — master permits ("perizinan induk") entered from a phase
// registry table (the kind kept per Tahap), NOT from the file-folder scan.
// Additive + idempotent: deletes only the rows it owns (by docNumber) then
// re-inserts, so it can run alongside scripts/import.mjs without wiping it.
//   Run: node scripts/import-permits.mjs
import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

// Source: T1&T2 permit table = JR Krian 1, Tahap 1–2.
// docType: PKKPR → IzinLokasi; SKRK → SKRK; PBG → PBG; Peta Bidang Tanah → PetaBidang.
// luas/spec is kept in the title (no dedicated schema column yet).
const CLUSTER = "JR Krian 1";
const PHASE = "T1-2";
const PERMITS = [
  { type: "IzinLokasi", label: "PKKPR",                no: "5072410313515070",        date: "2024-07-05", luas: "122.453 m²" },
  { type: "SKRK",       label: "SKRK",                 no: "600.3/3195/438.5.4/2025", date: "2025-06-03", luas: "31.934 m²" },
  { type: "PBG",        label: "PBG Tipe Cici",        no: "SK-PBG-351511-23072025-001", date: "2025-07-23", luas: "46/50" },
  { type: "PBG",        label: "PBG Tipe Cica",        no: "SK-PBG-351511-23072025-006", date: "2025-07-23", luas: "36/75" },
  { type: "PBG",        label: "PBG Tipe Cica Hook",   no: "SK-PBG-351511-23072025-007", date: "2025-07-23", luas: "39/88" },
  { type: "PBG",        label: "PBG Tipe Camar",       no: "SK-PBG-351511-23072025-005", date: "2025-07-23", luas: "36/90" },
  { type: "PBG",        label: "PBG Tipe Camar Hook",  no: "SK-PBG-351511-23072025-003", date: "2025-07-23", luas: "39/105" },
  { type: "PBG",        label: "PBG Tipe Caladi",      no: "SK-PBG-351511-23072025-002", date: "2025-07-23", luas: "39/105" },
  { type: "PetaBidang", label: "Peta Bidang Tanah",    no: "3192/2024",               date: "2024-12-24", luas: "27.574 m²" },
  { type: "PetaBidang", label: "Peta Bidang Tanah",    no: "3125/2024",               date: "2024-12-16", luas: "4.360 m²" },
];

async function main() {
  const legal = await prisma.user.findMany({ where: { role: { in: ["LEGAL_PIC", "HEAD_OF_LEGAL"] } } });
  if (!legal.length) { console.error("No legal users. Run `npm run db:seed` first."); process.exit(1); }
  // JR Krian 1 PIC = Rina Sari (matches scripts/import.mjs picFor rule).
  const owner = legal.find((u) => u.name.includes("Rina")) || legal[0];

  const numbers = PERMITS.map((p) => p.no);
  const removed = await prisma.document.deleteMany({ where: { docNumber: { in: numbers }, cluster: CLUSTER } });
  if (removed.count) console.log(`Re-run: removed ${removed.count} previously imported permit(s).`);

  let n = 0;
  for (const p of PERMITS) {
    await prisma.document.create({
      data: {
        docType: p.type,
        title: `${p.label} — ${CLUSTER} (${PHASE}) · ${p.luas}`,
        docNumber: p.no,
        scope: "development",
        cluster: CLUSTER,
        status: "Active",
        issueDate: new Date(`${p.date}T00:00:00Z`), // UTC midnight = exact calendar date (machine is WIB/UTC+7)
        picId: owner.id,
        uploadedById: owner.id,
        uploadedAt: new Date(`${p.date}T00:00:00Z`),
        originalLocation: "OfficeSafe",
        accessLevel: "Leadership", // izin/perizinan are leadership-gated
      },
    });
    n++;
  }
  console.log(`Imported ${n} master permits for ${CLUSTER} (${PHASE}).`);
  console.log("Note: per-unit Sertipikat come from the file scan; blok-summary cert rows were intentionally skipped.");
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
