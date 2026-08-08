// Seed the editable timeline from the Exsum data (the 6 sections / items).
// targetMonth = the item's target/done month; progress = full if it was DONE.
import { PrismaClient } from "@prisma/client";
const prisma = new PrismaClient();

const MO12 = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
const MO_SUK = ["Sep", "Okt", "Nov", "Des", "Jan", "Feb", "Mar", "Apr"];
const STEPS = ["tanda_terima", "survey_lokasi", "rapat", "product"];

const SECTIONS = [
  { title: "C3 Tahap 3 & 4 — Perizinan", range: "2026", months: MO12, rows: [
    { item: "SK Siteplan", status: "Express", durasi: "3 Bulan", cells: { 2: "done", 4: "target" } },
    { item: "AMDAL", status: "Proses", durasi: "2 Bulan", cells: { 5: "target" } },
    { item: "PBG Induk", status: "Express", durasi: "1 Bulan", cells: { 5: "done", 6: "target" } },
    { item: "PBG Split", status: "Proses", durasi: "1 Bulan", cells: { 6: "target" } },
  ] },
  { title: "C3 Tahap 3 — Sertifikasi", range: "2026", months: MO12, rows: [
    { item: "PBT", status: "On Target", durasi: "—", cells: {} },
    { item: "SKHGB Kantah", status: "Express", durasi: "3 Bulan", cells: { 0: "target" } },
    { item: "Sertif Induk", status: "Done (+1 Bln)", durasi: "5 Bulan", cells: { 1: "target", 2: "done" } },
  ] },
  { title: "C3 Tahap 4 — Sertifikasi", range: "2026", months: MO12, rows: [
    { item: "PBT", status: "Express", durasi: "2 Bulan", cells: { 0: "target" } },
    { item: "SKHGB", status: "On Target", durasi: "3 Bulan", cells: { 2: "donetarget" } },
    { item: "Sertif Induk", status: "Delay", durasi: "4 Bulan", cells: { 2: "target", 4: "done" } },
    { item: "Gabung Sertif", status: "On Target", durasi: "6 Bulan", cells: { 4: "donetarget" } },
    { item: "Split Sertifikat", status: "On Target", durasi: "10 Bulan", cells: { 5: "donetarget" } },
    { item: "Split PBB", status: "Proses", durasi: "11 Bulan", cells: { 6: "target" } },
  ] },
  { title: "C5 Sukodono — Sertifikasi (Performance 2025)", range: "Sep 2025 → Apr 2026", months: MO_SUK, rows: [
    { item: "SKHGB Pusat", status: "Done (+5 Bln)", durasi: "11 Bulan", cells: { 0: "target", 5: "done" } },
    { item: "Cetak Sertif Induk", status: "Done (+5 Bln)", durasi: "11 Bulan", cells: { 1: "target", 5: "done" } },
    { item: "Pecah Sertif", status: "Done (+6 Bln)", durasi: "12 Bulan", cells: { 2: "target", 6: "done" } },
    { item: "Pecah PBB", status: "Done (+7 Bln)", durasi: "13 Bulan", cells: { 3: "target", 7: "done" } },
  ] },
  { title: "C5 Extend — Perizinan", range: "2026", months: MO12, rows: [
    { item: "Aturan LBS/LSD", status: "Eksternal", durasi: "2 Bulan", cells: { 4: "target" } },
    { item: "SK Siteplan", status: "—", durasi: "6 Bulan", cells: { 9: "target" } },
    { item: "PBG Induk", status: "—", durasi: "7 Bulan", cells: { 10: "target" } },
    { item: "PBG Split", status: "—", durasi: "8 Bulan", cells: { 11: "target" } },
  ] },
  { title: "C5 Extend — Sertifikasi", range: "2026", months: MO12, rows: [
    { item: "PBT", status: "On Target", durasi: "2 Bulan", cells: { 3: "donetarget" } },
    { item: "SKHGB Kanta", status: "—", durasi: "4 Bulan", cells: { 7: "target" } },
    { item: "Cetak SHGB", status: "—", durasi: "5 Bulan", cells: { 8: "target" } },
    { item: "Pecah Sertif", status: "—", durasi: "6 Bulan", cells: { 9: "target" } },
    { item: "Pecah PBB", status: "—", durasi: "7 Bulan", cells: { 10: "target" } },
  ] },
];

function derive(cells) {
  const entries = Object.entries(cells).map(([i, k]) => [Number(i), k]);
  const target = entries.find(([, k]) => k === "target" || k === "donetarget");
  const done = entries.find(([, k]) => k === "done" || k === "donetarget");
  return { progressMonth: done ? done[0] : null, targetMonth: target ? target[0] : null, isDone: !!done };
}

async function main() {
  await prisma.timelineSection.deleteMany({}); // cascade removes entries + checkpoints
  let so = 0;
  for (const sec of SECTIONS) {
    const cm = sec.title.match(/C(\d+)/i);
    const cluster = cm ? Number(cm[1]) : null;
    const afterDash = sec.title.split("—")[1];
    const productType = afterDash ? afterDash.trim().split(/\s+/)[0] : null; // "Perizinan" / "Sertifikasi"
    const section = await prisma.timelineSection.create({
      data: { title: sec.title, cluster, productType, range: sec.range, months: JSON.stringify(sec.months), order: so++ },
    });
    let eo = 0;
    const yr = /^\d{4}$/.test(sec.range) ? Number(sec.range) : null;
    const standard = sec.months[0] === "Jan";
    for (const row of sec.rows) {
      const { progressMonth, targetMonth, isDone } = derive(row.cells);
      // done seed -> mid target month so it reads as on-time (green), not late.
      const onTimeRef = targetMonth ?? progressMonth;
      const doneAt = isDone ? (standard && yr != null && onTimeRef != null ? new Date(yr, onTimeRef, 15) : new Date()) : null;
      await prisma.timelineEntry.create({
        data: {
          sectionId: section.id,
          name: row.item,
          status: row.status,
          durasi: row.durasi,
          progressMonth,
          targetMonth,
          order: eo++,
          checkpoints: {
            create: STEPS.map((step, i) => ({ step, order: i, done: isDone, doneAt })),
          },
        },
      });
    }
  }
  const n = await prisma.timelineEntry.count();
  console.log(`Seeded ${SECTIONS.length} sections, ${n} entries (4 checkpoints each).`);
}

main().then(() => prisma.$disconnect()).catch(async (e) => { console.error(e); await prisma.$disconnect(); process.exit(1); });
