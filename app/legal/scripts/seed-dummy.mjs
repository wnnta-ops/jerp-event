// Dummy data — 3 documents per document type (per tab) so the directory looks
// populated. Wipes documents first; keeps user accounts. Run make-sample-docs
// afterwards to give each one a placeholder PDF.
//   Run: node scripts/seed-dummy.mjs
import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

// type key → label (matches lib/enums DOC_TYPE_LABELS)
const TYPES = {
  IzinLokasi: "Izin Lokasi", IzinLingkungan: "Izin Lingkungan", SKRK: "SKRK", PBGInduk: "PBG Induk", PBGSplit: "PBG Split",
  PetaBidang: "Peta Bidang", SKHGB: "SKHGB", SHGBInduk: "SHGB Induk", SHGBSplit: "SHGB Split", PBBSplit: "PBB Split",
  AktaPendirian: "Akta Pendirian", RUPS: "RUPS",
  PerjanjianAgent: "Perjanjian Agent", PerjanjianKPR: "Perjanjian KPR", PerjanjianKredit: "Perjanjian Kredit", PerjanjianJaminanAset: "Perjanjian Jaminan Aset", PerjanjianPihakKetiga: "Perjanjian Pihak Ketiga Lainnya",
};
const UNIT = new Set(["PBGSplit", "SHGBSplit", "PBBSplit"]);
const ENTITY = new Set(["AktaPendirian", "RUPS"]);
const scopeOf = (t) => (UNIT.has(t) ? "unit" : ENTITY.has(t) ? "entity" : "development");

const clusters = ["Cluster 1", "Cluster 2", "Cluster 3"];
const pts = ["PT Mitra Usaha Propertindo", "PT Mitra Usaha Investama", "PT Wira Tata Mitra"];
const statuses = ["Active", "Registered", "Expiring"];
const access = ["LegalOnly", "Leadership", "SalesVisible"];
const buyers = ["Budi Santoso", "Siti Aminah", "Andi Wijaya"];
const bloks = ["A", "B", "C"];
const units = ["12", "07", "03"];

async function main() {
  const pic = await prisma.user.findFirst({ where: { role: "LEGAL_PIC" } });
  const head = await prisma.user.findFirst({ where: { role: "HEAD_OF_LEGAL" } });
  if (!pic) { console.error("No legal users — run seed first."); process.exit(1); }

  // clean slate (keep users)
  await prisma.documentRelation.deleteMany();
  await prisma.documentFile.deleteMany();
  await prisma.document.deleteMany();

  let n = 0;
  for (const [type, label] of Object.entries(TYPES)) {
    for (let i = 0; i < 3; i++) {
      const sc = scopeOf(type);
      const owner = i % 2 ? head : pic;
      const data = {
        docType: type, scope: sc, status: statuses[i], accessLevel: access[i],
        originalLocation: "OfficeSafe", docNumber: `${type}/${101 + i}/2026`,
        issueDate: new Date(`2026-0${(i % 9) + 1}-1${i}T00:00:00Z`),
        picId: owner.id, uploadedById: owner.id,
      };
      if (statuses[i] === "Expiring") data.expiryDate = new Date(Date.now() + 20 * 86400000); // ~20 hari lagi
      if (sc === "unit") {
        data.cluster = clusters[i]; data.blok = `Blok ${bloks[i]}`; data.unitNo = `${bloks[i]}-${units[i]}`; data.buyerName = buyers[i];
        data.title = `${label} — ${clusters[i]} ${bloks[i]}-${units[i]}`;
      } else if (sc === "entity") {
        data.entityName = pts[i];
        data.title = `${label} — ${pts[i]}`;
      } else {
        data.cluster = clusters[i];
        data.title = `${label} — ${clusters[i]}`;
      }
      await prisma.document.create({
        data: { ...data, files: { create: [{ url: "", fileName: `${data.title}.pdf`, mimeType: "application/pdf", label: "contoh" }] } },
      });
      n++;
    }
  }
  console.log(`Created ${n} dummy docs across ${Object.keys(TYPES).length} types (3 each). Now run: node scripts/make-sample-docs.mjs`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
