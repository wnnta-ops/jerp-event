import { PrismaClient } from "@prisma/client";
import { mkdirSync, writeFileSync } from "fs";
import path from "path";

const prisma = new PrismaClient();

/** Write a tiny valid PDF the seed documents point at, so previews render. */
function writePlaceholderPdf() {
  const objs = [
    "<</Type/Catalog/Pages 2 0 R>>",
    "<</Type/Pages/Kids[3 0 R]/Count 1>>",
    "<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>",
    "<</Length 78>>\nstream\nBT /F1 16 Tf 24 110 Td (Contoh berkas dokumen) Tj 0 -28 Td (LDCMS — pratinjau) Tj ET\nendstream",
    "<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
  ];
  let pdf = "%PDF-1.4\n";
  const offsets = [];
  objs.forEach((body, i) => {
    offsets.push(Buffer.byteLength(pdf, "latin1"));
    pdf += `${i + 1} 0 obj\n${body}\nendobj\n`;
  });
  const xrefStart = Buffer.byteLength(pdf, "latin1");
  pdf += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`;
  offsets.forEach((o) => (pdf += String(o).padStart(10, "0") + " 00000 n \n"));
  pdf += `trailer\n<</Size ${objs.length + 1}/Root 1 0 R>>\nstartxref\n${xrefStart}\n%%EOF`;
  const dir = path.join(process.cwd(), "public", "uploads", "seed");
  mkdirSync(dir, { recursive: true });
  writeFileSync(path.join(dir, "placeholder.pdf"), Buffer.from(pdf, "latin1"));
}

/** People from the reference mockups, one per role + a few cross-dept requesters. */
const USERS = [
  { name: "Rina Sari", email: "rina.sari@mup.co.id", role: "LEGAL_PIC", department: "Legal", initials: "RS" },
  { name: "Dewi Lestari", email: "dewi.lestari@mup.co.id", role: "LEGAL_PIC", department: "Legal", initials: "DL" },
  { name: "Budi Hartono", email: "budi.hartono@mup.co.id", role: "HEAD_OF_LEGAL", department: "Legal", initials: "BH" },
  { name: "Sari Wijaya", email: "sari.wijaya@mup.co.id", role: "GENERAL_MANAGER", department: "Manajemen", initials: "SW" },
  { name: "Joko Pranoto", email: "joko.pranoto@mup.co.id", role: "DIRECTOR", department: "Manajemen", initials: "JP" },
  { name: "Andi Pratama", email: "andi.pratama@mup.co.id", role: "REQUESTER", department: "Finance", initials: "AP" },
  { name: "Dewi Kusuma", email: "dewi.kusuma@mup.co.id", role: "REQUESTER", department: "Sales", initials: "DK" },
  { name: "Rio Santoso", email: "rio.santoso@mup.co.id", role: "REQUESTER", department: "Marketing", initials: "RS" },
];

const d = (s) => new Date(`${s}T00:00:00`);

/**
 * Sample documents, dated around the reference "today" of 8 Jun 2026 so the
 * expiring/overdue colors and the "≤30 days" quick filter are meaningful.
 * `pic`/`by` are user emails; resolved to ids below. Each gets a placeholder
 * file row (real file preview comes with the detail drawer).
 */
const DOCUMENTS = [
  // --- Transaksi (per unit) — JR Krian 1, Blok I, Unit 12 (Budi Santoso) ---
  { docType: "PPJB", title: "PPJB — JRK1 I-12", docNumber: "045/PPJB/VI/2026", scope: "unit", cluster: "JR Krian 1", blok: "Blok I", unitNo: "I-12", buyerName: "Budi Santoso", status: "AwaitingSignature", issueDate: d("2026-06-02"), originalLocation: "NotarisPPAT", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "dewi.kusuma@mup.co.id", uploadedAt: d("2026-06-05") },
  { docType: "AJB", title: "AJB — JRK1 I-12", docNumber: "112/AJB/V/2026", scope: "unit", cluster: "JR Krian 1", blok: "Blok I", unitNo: "I-12", buyerName: "Budi Santoso", status: "Registered", issueDate: d("2026-05-18"), originalLocation: "NotarisPPAT", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "andi.pratama@mup.co.id", uploadedAt: d("2026-05-19") },
  { docType: "Sertifikat", title: "Sertifikat HGB — JRK1 I-12", docNumber: "HGB 00328", scope: "unit", cluster: "JR Krian 1", blok: "Blok I", unitNo: "I-12", buyerName: "Budi Santoso", status: "Active", issueDate: d("2024-09-12"), expiryDate: d("2054-09-12"), originalLocation: "BankKPR", accessLevel: "Leadership", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2024-09-20") },
  { docType: "BPHTB", title: "BPHTB — JRK1 I-12", docNumber: "BPHTB/2026/0112", scope: "unit", cluster: "JR Krian 1", blok: "Blok I", unitNo: "I-12", buyerName: "Budi Santoso", status: "Active", issueDate: d("2026-05-10"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "dewi.lestari@mup.co.id", by: "dewi.lestari@mup.co.id", uploadedAt: d("2026-05-12") },
  { docType: "PPh", title: "PPh Jual Beli — JRK1 I-12", docNumber: "PPH/2026/0112", scope: "unit", cluster: "JR Krian 1", blok: "Blok I", unitNo: "I-12", buyerName: "Budi Santoso", status: "Active", issueDate: d("2026-05-10"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "dewi.lestari@mup.co.id", by: "dewi.lestari@mup.co.id", uploadedAt: d("2026-05-12") },
  // --- Transaksi — JR Krian 2, Blok II, Unit 11 (Mufidatun Nisa) ---
  { docType: "BA_STU", title: "BA Serah Terima — JRK2 II-11", docNumber: "BAST/2026/211", scope: "unit", cluster: "JR Krian 2", blok: "Blok II", unitNo: "II-11", buyerName: "Mufidatun Nisa", status: "Signed", issueDate: d("2026-05-22"), originalLocation: "OfficeSafe", accessLevel: "SalesVisible", pic: "rina.sari@mup.co.id", by: "dewi.kusuma@mup.co.id", uploadedAt: d("2026-05-23") },
  { docType: "PPN_DTP", title: "PPN DTP — JRK2 II-11", docNumber: "SUPER/PPN/2025/211", scope: "unit", cluster: "JR Krian 2", blok: "Blok II", unitNo: "II-11", buyerName: "Mufidatun Nisa", status: "Active", issueDate: d("2025-12-01"), originalLocation: "OfficeSafe", accessLevel: "SalesVisible", pic: "dewi.lestari@mup.co.id", by: "dewi.lestari@mup.co.id", uploadedAt: d("2025-12-03") },
  { docType: "PPJB", title: "PPJB — C5S PER-1", docNumber: "35/PPJB/VII/2023", scope: "unit", cluster: "JR Cluster 5 Sukodono", blok: "PER", unitNo: "PER-1", buyerName: "Fatchul", status: "Signed", issueDate: d("2023-07-27"), originalLocation: "NotarisPPAT", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "dewi.kusuma@mup.co.id", uploadedAt: d("2023-07-28") },
  // --- Perizinan (development) ---
  { docType: "IzinLokasi", title: "Izin Lokasi — Cluster 3 & 4", docNumber: "IL/2022/0044", scope: "development", status: "Active", issueDate: d("2022-03-10"), expiryDate: d("2027-03-10"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2022-03-12") },
  { docType: "Perizinan", title: "PBG — Java Residence", docNumber: "PBG/2021/0098", scope: "development", status: "Expiring", issueDate: d("2021-01-12"), expiryDate: d("2026-06-30"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2021-01-12") },
  { docType: "Perizinan", title: "PERTEK — C5 Sukodono", docNumber: "PERTEK/C5S/2024", scope: "development", status: "Expiring", issueDate: d("2024-07-12"), expiryDate: d("2026-06-14"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2024-07-15") },
  { docType: "Siteplan", title: "Siteplan / SKRK — C5 Sukodono", docNumber: "SKRK/C5S/2025", scope: "development", status: "Active", issueDate: d("2025-09-08"), originalLocation: "OfficeSafe", accessLevel: "Leadership", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2025-09-10") },
  // --- Korporasi (per PT) ---
  { docType: "AktaPendirian", title: "Akta Pendirian — PT MUP", docNumber: "Akta No 62 (23-05-2022)", scope: "entity", entityName: "PT Mitra Usaha Propertindo", status: "Active", issueDate: d("2022-05-23"), originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2022-05-25") },
  { docType: "RUPS", title: "RUPS — PT MUP No 09", docNumber: "Akta RUPS No 09 (04-07-2022)", scope: "entity", entityName: "PT Mitra Usaha Propertindo", status: "Active", issueDate: d("2022-07-04"), originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2022-07-06") },
  { docType: "SKKumham", title: "SK Kemenkumham — PT MUP", docNumber: "SK Pengesahan Kumham", scope: "entity", entityName: "PT Mitra Usaha Propertindo", status: "Active", issueDate: d("2022-05-30"), originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2022-06-01") },
  { docType: "AktaPendirian", title: "Akta Pendirian — PT AZA", docNumber: "Akta AZA No 14", scope: "entity", entityName: "PT AZA Anugerah Pratama", status: "Active", issueDate: d("2021-08-10"), originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2021-08-12") },
  // --- Perjanjian ---
  { docType: "PKS", title: "PKS Bank — BNI (Fasilitas Kredit)", docNumber: "PKS_BNI-MUP_C2_14082024", scope: "entity", entityName: "PT Bank BNI", status: "Signed", issueDate: d("2024-08-14"), originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "andi.pratama@mup.co.id", uploadedAt: d("2024-08-16") },
  { docType: "PKS", title: "PKS Agen — Maxima", docNumber: "001/PKS/Arebi/2024", scope: "entity", entityName: "Maxima Marketing Service", status: "Active", issueDate: d("2024-04-01"), originalLocation: "OfficeSafe", accessLevel: "SalesVisible", pic: "dewi.lestari@mup.co.id", by: "dewi.kusuma@mup.co.id", uploadedAt: d("2024-04-03") },
  // --- Template ---
  { docType: "Template", title: "Template PPJB Standar", version: "v2.1", isCurrentApproved: true, scope: "development", status: "Active", originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2026-04-01") },
  { docType: "Template", title: "Template PPJB Standar", version: "v2.0", isCurrentApproved: false, scope: "development", status: "Superseded", originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "budi.hartono@mup.co.id", by: "budi.hartono@mup.co.id", uploadedAt: d("2025-10-01") },
  { docType: "Template", title: "Template Surat Kuasa", version: "v1.3", isCurrentApproved: true, scope: "development", status: "Active", originalLocation: "OfficeSafe", accessLevel: "LegalOnly", pic: "rina.sari@mup.co.id", by: "rina.sari@mup.co.id", uploadedAt: d("2026-02-15") },
];

/** Thread examples: SHGB B-07 <- AJB B-07 <- PPJB? (by title). */
const RELATIONS = [
  ["Sertifikat HGB — JRK1 I-12", "AJB — JRK1 I-12"],
  ["AJB — JRK1 I-12", "PPJB — JRK1 I-12"],
  ["RUPS — PT MUP No 09", "Akta Pendirian — PT MUP"],
];

const t = (s, h = 0, m = 0) => new Date(`${s}T00:00:00`);
const mins = (h, m = 0) => h * 60 + m;

/**
 * Work items (blue) for the day-to-day schedule, around 8 Jun 2026. Expiry items
 * (amber) are NOT seeded here — they are derived from document expiry dates at
 * read time. A few manual `expiry` items cover non-document deadlines.
 */
const TIMELINE = [
  // work
  { itemType: "work", title: "Tanda tangan AJB · B-07", description: "Tanda tangan akta jual beli (AJB) unit B-07 di hadapan notaris/PPAT bersama pembeli.", date: t("2026-06-08"), startMinutes: mins(10), durationMinutes: 60, unit: "Blok B · B-07", pic: "rina.sari@mup.co.id" },
  { itemType: "work", title: "Sinkronisasi sertifikat", description: "Cek kecocokan data sertifikat dengan catatan BPN sebelum proses balik nama.", date: t("2026-06-08"), startMinutes: mins(14), durationMinutes: 60, pic: "rina.sari@mup.co.id" },
  { itemType: "work", title: "Janji temu notaris", description: "Koordinasi kelengkapan berkas PPJB dan menjadwalkan penandatanganan akta.", date: t("2026-06-09"), startMinutes: mins(11), durationMinutes: 60, pic: "rina.sari@mup.co.id" },
  { itemType: "work", title: "Review PPJB · C-03", description: "Tinjau klausul kontrak dan lengkapi data pembeli sebelum penandatanganan PPJB.", date: t("2026-06-10"), startMinutes: mins(9), durationMinutes: 120, unit: "Blok C · C-03", pic: "dewi.lestari@mup.co.id" },
  { itemType: "work", title: "Kirim dokumen KPR → BCA", description: "Kirim berkas pencairan kredit pemilikan rumah ke tim BCA.", date: t("2026-06-11"), startMinutes: mins(13), durationMinutes: 60, pic: "rina.sari@mup.co.id" },
  { itemType: "work", title: "Serah terima · A-12", description: "Serah terima kunci dan dokumen kepemilikan unit A-12 kepada pembeli.", date: t("2026-06-12"), startMinutes: mins(15), durationMinutes: 60, unit: "Blok A · A-12", pic: "rina.sari@mup.co.id" },
  { itemType: "work", title: "Update ke direksi", description: "Sampaikan ringkasan status legal dan risiko mingguan kepada direksi.", date: t("2026-06-18"), startMinutes: mins(10), durationMinutes: 60, pic: "budi.hartono@mup.co.id" },
  { itemType: "work", title: "Closing · Anggrek", description: "Penutupan transaksi dan finalisasi seluruh berkas di cluster Anggrek.", date: t("2026-06-26"), startMinutes: mins(9), durationMinutes: 120, pic: "rina.sari@mup.co.id" },
  // manual expiry (non-document deadlines)
  { itemType: "expiry", title: "Review izin lokasi", date: t("2026-06-03"), startMinutes: mins(12), pic: "rina.sari@mup.co.id" },
  { itemType: "expiry", title: "Polis asuransi proyek", date: t("2026-06-13"), startMinutes: mins(17), pic: "rina.sari@mup.co.id" },
  { itemType: "expiry", title: "NDA vendor", date: t("2026-06-24"), startMinutes: mins(16), pic: "budi.hartono@mup.co.id" },
];

async function main() {
  writePlaceholderPdf();
  for (const u of USERS) {
    await prisma.user.upsert({ where: { email: u.email }, update: u, create: u });
  }
  const users = await prisma.user.findMany();
  const byEmail = Object.fromEntries(users.map((u) => [u.email, u.id]));

  // Reset document data so the seed is idempotent.
  await prisma.auditEntry.deleteMany();
  await prisma.approvalStep.deleteMany();
  await prisma.accessRequest.deleteMany();
  await prisma.timelineItem.deleteMany();
  await prisma.documentRelation.deleteMany();
  await prisma.documentFile.deleteMany();
  await prisma.document.deleteMany();

  const idByTitleVersion = {};
  for (const doc of DOCUMENTS) {
    const { pic, by, version, isCurrentApproved, ...rest } = doc;
    const created = await prisma.document.create({
      data: {
        ...rest,
        version: version ?? null,
        isCurrentApproved: !!isCurrentApproved,
        picId: byEmail[pic],
        uploadedById: byEmail[by],
        files: {
          create: [
            // public/seed/placeholder.pdf IKUT DI-COMMIT & ter-bake ke image, jadi
            // tautannya selalu hidup. Jangan arahkan ke /uploads/: folder itu
            // gitignored + tidak punya volume, sehingga berkasnya tak pernah ada
            // di server → semua dokumen contoh 404 saat dibuka.
            { url: "/seed/placeholder.pdf", fileName: `${rest.title}.pdf`, label: "scan", mimeType: "application/pdf", size: 102400 },
          ],
        },
      },
    });
    idByTitleVersion[`${doc.title}::${version ?? ""}`] = created.id;
  }

  // Relations by title (first match).
  for (const [fromTitle, toTitle] of RELATIONS) {
    const from = await prisma.document.findFirst({ where: { title: fromTitle } });
    const to = await prisma.document.findFirst({ where: { title: toTitle } });
    if (from && to) {
      await prisma.documentRelation.create({ data: { fromId: from.id, toId: to.id } });
    }
  }

  for (const item of TIMELINE) {
    const { pic, ...rest } = item;
    await prisma.timelineItem.create({ data: { ...rest, picId: pic ? byEmail[pic] : null } });
  }

  // ---- Feature 4: access requests + chains + audit ----
  const firstByRole = {};
  for (const r of ["HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR", "LEGAL_PIC"]) {
    const u = users.find((x) => x.role === r);
    if (u) firstByRole[r] = u.id;
  }
  const chainRoles = (accessLevel, accessType) => {
    if (accessType === "checkout_original") return ["LEGAL_PIC", "HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR"];
    if (accessLevel === "SalesVisible") return [];
    return ["LEGAL_PIC", "HEAD_OF_LEGAL"];
  };
  const at = (s, h = 9, m = 0) => new Date(`${s}T${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:00`);

  const REQUESTS = [
    { docTitle: "Sertifikat HGB — JRK1 I-12", requester: "andi.pratama@mup.co.id", accessType: "checkout_original", neededBy: "2026-06-12", createdAt: "2026-06-06", approved: 1 },
    { docTitle: "PPh Jual Beli — JRK1 I-12", requester: "rio.santoso@mup.co.id", accessType: "view", neededBy: "2026-06-10", createdAt: "2026-06-08", approved: 1 },
    { docTitle: "PPJB — JRK1 I-12", requester: "dewi.kusuma@mup.co.id", accessType: "download", neededBy: "2026-06-15", createdAt: "2026-06-07", approved: 0 },
    { docTitle: "BA Serah Terima — JRK2 II-11", requester: "dewi.kusuma@mup.co.id", accessType: "view", neededBy: "2026-06-13", createdAt: "2026-06-08", approved: 0 },
    { docTitle: "AJB — JRK1 I-12", requester: "andi.pratama@mup.co.id", accessType: "checkout_original", neededBy: "2026-06-09", createdAt: "2026-06-05", approved: 1, rejected: true },
    // --- resolved history ---
    { docTitle: "PPJB — JRK1 I-12", requester: "dewi.kusuma@mup.co.id", accessType: "view", neededBy: "2026-05-20", createdAt: "2026-05-15", approved: 2 },
    { docTitle: "PKS Agen — Maxima", requester: "dewi.kusuma@mup.co.id", accessType: "view", neededBy: "2026-05-25", createdAt: "2026-05-22" },
    { docTitle: "BPHTB — JRK1 I-12", requester: "andi.pratama@mup.co.id", accessType: "download", neededBy: "2026-05-28", createdAt: "2026-05-24", approved: 2 },
    { docTitle: "Izin Lokasi — Cluster 3 & 4", requester: "rio.santoso@mup.co.id", accessType: "view", neededBy: "2026-05-30", createdAt: "2026-05-26", approved: 2 },
    { docTitle: "Sertifikat HGB — JRK1 I-12", requester: "andi.pratama@mup.co.id", accessType: "checkout_original", neededBy: "2026-06-02", createdAt: "2026-05-28", approved: 4 },
    { docTitle: "Akta Pendirian — PT MUP", requester: "dewi.kusuma@mup.co.id", accessType: "view", neededBy: "2026-05-18", createdAt: "2026-05-14", approved: 2 },
    { docTitle: "PKS Bank — BNI (Fasilitas Kredit)", requester: "rio.santoso@mup.co.id", accessType: "view", neededBy: "2026-05-12", createdAt: "2026-05-09", approved: 2 },
    { docTitle: "Sertifikat HGB — JRK1 I-12", requester: "rio.santoso@mup.co.id", accessType: "checkout_original", neededBy: "2026-05-21", createdAt: "2026-05-17", approved: 2, rejected: true },
    { docTitle: "AJB — JRK1 I-12", requester: "dewi.kusuma@mup.co.id", accessType: "download", neededBy: "2026-05-19", createdAt: "2026-05-15", approved: 1, rejected: true },
    { docTitle: "PPJB — JRK1 I-12", requester: "andi.pratama@mup.co.id", accessType: "checkout_original", neededBy: "2026-05-16", createdAt: "2026-05-11", approved: 3, rejected: true },
  ];

  for (let idx = 0; idx < REQUESTS.length; idx++) {
    const r = REQUESTS[idx];
    const doc = await prisma.document.findFirst({ where: { title: r.docTitle } });
    if (!doc) continue;
    const roles = chainRoles(doc.accessLevel, r.accessType);
    const code = `DR-${String(142 + idx).padStart(4, "0")}`;
    const auto = roles.length === 0;
    const currentStep = r.rejected ? r.approved : Math.min(r.approved, Math.max(roles.length - 1, 0));
    const status = auto ? "approved" : r.rejected ? "rejected" : r.approved >= roles.length ? "approved" : "pending";
    const base = at(r.createdAt, 10, 24);

    const req = await prisma.accessRequest.create({
      data: {
        code, docId: doc.id, requesterId: byEmail[r.requester], dept: users.find((u) => u.email === r.requester)?.department ?? "—",
        purpose: r.accessType === "checkout_original" ? "Berkas pencairan KPR" : "Verifikasi & arsip internal",
        accessType: r.accessType, neededBy: at(r.neededBy), status, currentStep: auto ? 0 : currentStep, createdAt: base,
      },
    });

    const audit = [{ actorId: byEmail[r.requester], action: "created", detail: `Diminta oleh ${users.find((u) => u.email === r.requester)?.name}`, createdAt: base }];

    if (auto) {
      audit.push({ action: "auto_approved", detail: "Dokumen Sales-visible — akses langsung diberikan.", createdAt: new Date(base.getTime() + 60000) });
    } else {
      for (let i = 0; i < roles.length; i++) {
        const approverId = roles[i] === "LEGAL_PIC" ? doc.picId : firstByRole[roles[i]];
        const decision = i < r.approved ? "approved" : r.rejected && i === r.approved ? "rejected" : null;
        const decidedAt = decision ? new Date(base.getTime() + (i + 1) * 3600000) : null;
        const notifiedAt = i < r.approved || (i === currentStep && status === "pending") ? new Date(base.getTime() + i * 3600000) : null;
        await prisma.approvalStep.create({
          data: { requestId: req.id, order: i, role: roles[i], approverId, decision, decidedAt, notifiedAt, note: decision === "rejected" ? "Perlu memo persetujuan direksi dulu." : null },
        });
        if (decision === "approved") audit.push({ actorId: approverId, action: "approved", createdAt: decidedAt });
        if (decision === "rejected") audit.push({ actorId: approverId, action: "rejected", detail: "Perlu memo persetujuan direksi dulu.", createdAt: decidedAt });
      }
      if (status === "pending") audit.push({ action: "notified", detail: "Auto-notifikasi approver giliran.", createdAt: new Date(base.getTime() + currentStep * 3600000 + 60000) });
    }

    for (const a of audit) await prisma.auditEntry.create({ data: { requestId: req.id, ...a } });
  }

  const [uc, dc, tc, rc] = await Promise.all([
    prisma.user.count(),
    prisma.document.count(),
    prisma.timelineItem.count(),
    prisma.accessRequest.count(),
  ]);
  console.log(`Seeded ${uc} users, ${dc} documents, ${tc} timeline items, ${rc} access requests.`);
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());
