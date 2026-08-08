// AJB IMPORT — from the new "DASHBOARD DATA USED" source. Step 1 of rebuilding
// the directory from the authoritative dataset (clean slate; other doc types
// added in later steps). Cluster comes from the top-level folder (C1/C2/C3/C5).
// Files are referenced IN PLACE (never copied, never opened).
//   Run: node scripts/import-ajb.mjs   ·   Restore demo with: npm run db:reset
import { readdirSync, statSync, writeFileSync } from "fs";
import path from "path";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const ROOT_PARENT = path.resolve(process.cwd(), "..");
const AJB_ROOT = path.join(ROOT_PARENT, "DASHBOARD DATA USED", "AKTA JUAL BELI");
const DOC_EXT = new Set([".pdf", ".jpg", ".jpeg", ".png"]);
const MIME = { ".pdf": "application/pdf", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png" };

// Top-level folder -> cluster label. "New folder" = duplicate copies → skip.
function clusterFor(topDir) {
  const u = topDir.toUpperCase();
  if (u.startsWith("NEW FOLDER")) return null;
  if (u.startsWith("C1")) return "Cluster 1";
  if (u.startsWith("C2")) return "Cluster 2";
  if (u.startsWith("C3")) return "Cluster 3";
  if (u.includes("C5")) return "Cluster 5";
  return null;
}

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = path.join(dir, name);
    let st; try { st = statSync(full); } catch { continue; }
    if (st.isDirectory()) walk(full, out);
    else if (DOC_EXT.has(path.extname(name).toLowerCase())) out.push(full);
  }
  return out;
}

const blokFmt = (b) => (/^[IVX]+$/i.test(b) ? `Blok ${b}` : b);
function parseUnit(codeSeg) {
  // "JRK I-10" · "JRK2 II-11A" · "JRS5 II-.21" · "JRK3 RUHA 1" (space) · "JRK RUHA-17"
  const m = codeSeg.match(/(?:JR[KS]?\d?\s+)?([IVX]+|RAYA|RUHA)[\s.\-]+\.?\s*(\d+[A-Za-z]?)\b/i);
  if (!m) return { blok: null, unitNo: null };
  const blok = m[1].toUpperCase();
  return { blok: blokFmt(blok), unitNo: `${blok}-${m[2]}` };
}
const DOCNO_RE = /^\s*(\d{1,4})\s+(\d{4})\b/;
function parseBuyerAndNo(parts) {
  let docIdx = -1;
  for (let i = 2; i < parts.length; i++) { if (DOCNO_RE.test(parts[i])) { docIdx = i; break; } }
  const buyerRaw = docIdx > 2 ? parts.slice(2, docIdx).join(" ").trim() : null;
  const buyer = buyerRaw && buyerRaw.length >= 3 ? buyerRaw.replace(/\s+/g, " ") : null;
  const dm = docIdx >= 0 ? parts[docIdx].match(DOCNO_RE) : null;
  return { buyer, docNo: dm ? `${dm[1]}/${dm[2]}` : null };
}
function parseDate(name) {
  const m = name.match(/\((\d{2})(\d{2})(\d{4})\)/);
  if (!m) return null;
  const dt = new Date(`${m[3]}-${m[2]}-${m[1]}T00:00:00Z`); // UTC = exact calendar date
  return Number.isNaN(dt.getTime()) ? null : dt;
}

async function main() {
  const legal = await prisma.user.findMany({ where: { role: { in: ["LEGAL_PIC", "HEAD_OF_LEGAL"] } } });
  if (!legal.length) { console.error("No legal users. Run `npm run db:seed` first."); process.exit(1); }
  const byName = (n) => legal.find((u) => u.name.includes(n)) || legal[0];
  const rina = byName("Rina"), dewi = byName("Dewi Lestari");
  const picFor = (cluster) => (/Cluster 1|Cluster 2/.test(cluster) ? rina : dewi);

  const tops = readdirSync(AJB_ROOT).filter((n) => { try { return statSync(path.join(AJB_ROOT, n)).isDirectory(); } catch { return false; } });
  const clean = [], review = [], seen = new Set();

  for (const top of tops) {
    const cluster = clusterFor(top);
    if (!cluster) continue; // skip New folder / unknown
    for (const f of walk(path.join(AJB_ROOT, top))) {
      const name = path.basename(f, path.extname(f));
      const rel = f.replace(ROOT_PARENT + path.sep, "");
      const parts = name.split("_");
      const { blok, unitNo } = parseUnit(parts[1] || "");
      const { buyer, docNo } = parseBuyerAndNo(parts);
      if (!unitNo) { review.push([rel, "no unit"]); continue; }
      const key = `${cluster}|${unitNo}|${docNo || name}`;
      if (seen.has(key)) { review.push([rel, "duplicate"]); continue; }
      seen.add(key);
      clean.push({ cluster, blok, unitNo, buyer, docNo, issueDate: parseDate(name), file: f, rel, fileName: path.basename(f) });
    }
  }

  console.log(`AJB files parsed → import ${clean.length} · review ${review.length}`);
  console.log("Clearing ALL existing documents (clean slate)…");
  await prisma.documentRelation.deleteMany();
  await prisma.documentFile.deleteMany();
  await prisma.document.deleteMany(); // cascades AccessRequest → steps/audit

  let n = 0; const CHUNK = 100;
  for (let i = 0; i < clean.length; i += CHUNK) {
    await Promise.all(clean.slice(i, i + CHUNK).map((r) => {
      const owner = picFor(r.cluster);
      return prisma.document.create({
        data: {
          docType: "AJB",
          title: `AJB — ${r.cluster} · ${r.unitNo}`,
          docNumber: r.docNo ?? null,
          scope: "unit",
          cluster: r.cluster,
          blok: r.blok ?? null,
          unitNo: r.unitNo,
          buyerName: r.buyer ?? null,
          status: "Registered",
          issueDate: r.issueDate ?? null,
          picId: owner.id,
          uploadedById: owner.id,
          uploadedAt: r.issueDate ?? new Date(),
          originalLocation: "OfficeSafe",
          accessLevel: "LegalOnly",
          files: { create: [{ url: `/api/file?p=${encodeURIComponent(r.rel)}`, fileName: r.fileName, label: "original (in-place)", mimeType: MIME[path.extname(r.fileName).toLowerCase()] ?? null }] },
        },
      });
    }));
    n += Math.min(CHUNK, clean.length - i);
    process.stdout.write(`\r  inserted ${n}/${clean.length}`);
  }
  console.log("");

  const csv = "path,reason\n" + review.map(([p, why]) => `"${p}","${why}"`).join("\n");
  writeFileSync(path.join(process.cwd(), "import-ajb-review.csv"), csv);
  const total = await prisma.document.count();
  const withBuyer = await prisma.document.count({ where: { buyerName: { not: null } } });
  console.log(`Done. ${total} AJB documents · ${withBuyer} with buyer name · review → import-ajb-review.csv (${review.length})`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
