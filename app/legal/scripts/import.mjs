// REAL IMPORT — parses file/folder NAMES (never opens files) and inserts Document
// records into the local SQLite DB, referencing each file IN PLACE (no copy).
// Clean records import directly; incomplete/unmapped go to import-review.csv.
//   Run: node scripts/import.mjs
// Restore demo data anytime with: npm run db:reset
import { readdirSync, statSync, writeFileSync } from "fs";
import path from "path";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const ROOT_PARENT = path.resolve(process.cwd(), "..");
const ROOTS = [path.join(ROOT_PARENT, "DEPARTEMEN LEGAL"), path.join(ROOT_PARENT, "LEGALITAS")];
const DOC_EXT = new Set([".pdf", ".jpg", ".jpeg", ".png", ".xlsx"]);
const SKIP_NAME = /thumbs\.db|\.ds_store|^~\$/i;
const EXCLUDE_PATH = /\/Foto ?Unit\/|\/B-?ROLL\/|Foto GPS|\(Foto GPS\)|\/Desember\/|File Compres|WhatsApp Image|IMG[-_]\d/i;

function walk(dir, out = []) {
  let entries; try { entries = readdirSync(dir); } catch { return out; }
  for (const name of entries) {
    const full = path.join(dir, name);
    let st; try { st = statSync(full); } catch { continue; }
    if (st.isDirectory()) walk(full, out);
    else if (DOC_EXT.has(path.extname(name).toLowerCase()) && !SKIP_NAME.test(name)) out.push(full);
  }
  return out;
}

function classifyType(full) {
  const name = path.basename(full).toUpperCase(), dir = path.dirname(full).toUpperCase();
  const t = (re) => re.test(name) || re.test(dir);
  if (t(/PPN[ _]?DTP|SUPER PPN/)) return "PPN_DTP";
  if (t(/BA ?STU|BAST|SERAH TERIMA/)) return "BA_STU";
  if (/COVERNOTE/.test(name)) return "Other";
  if (t(/PELEPASAN HAK|APHB/)) return "APH";
  if (t(/BPHTB/)) return "BPHTB";
  if (t(/VALIDASI PPH|PPH JUAL|\bPPH\b|\bSKET\b/)) return "PPh";
  if (t(/SERTIF|\bHGB\b|\bSHM\b|SERTIFIKAT/)) return "Sertifikat";
  if (t(/\bAJB\b|AKTA JUAL BELI/)) return "AJB";
  if (t(/PPJB|PIJB/)) return "PPJB";
  if (t(/IZIN LOKASI|PKKPR/)) return "IzinLokasi";
  if (t(/\bSKRK\b/)) return "SKRK";
  if (t(/\bPBG\b|PERSETUJUAN BANGUNAN/)) return "PBG";
  if (t(/PETA BIDANG|\bPBT\b/)) return "PetaBidang";
  if (t(/PERTEK|RKKPR|SK LSD|PERIZINAN/)) return "Perizinan";
  if (t(/SITEPLAN|GAMBAR|SIKUMBANG|LAYOUT|KIR /)) return "Siteplan";
  if (t(/\bPKS\b/)) return "PKS";
  if (t(/AKTA PENDIRIAN|AKTA MUP|AKTA AZA|AKTA NO/)) return "AktaPendirian";
  if (t(/RUPS/)) return "RUPS";
  if (t(/KUMHAM|PENGESAHAN/)) return "SKKumham";
  if (t(/\bKTP\b|NPWP|TANDA TERIMA|\bTT\b/)) return "Other";
  return null;
}
function clusterOf(P) {
  const u = P.toUpperCase();
  if (/JRK1|KRIAN 1|C1 T|JRK RUHA|JRK RAYA|\bJRK [IVX]/.test(u)) return "JR Krian 1";
  if (/JRK2|KRIAN.*2/.test(u)) return "JR Krian 2";
  if (/JRK3|KRIAN 3/.test(u)) return "JR Krian 3";
  if (/JRS5|JRS 5|C5S|C5 SUKODONO|CLUSTER 5 SUKODONO|SUKODONO/.test(u)) return "JR Cluster 5 Sukodono";
  if (/CLUSTER 2|\bC2\b/.test(u)) return "JR Cluster 2";
  if (/CLUSTER 3|\bC3\b/.test(u)) return "JR Cluster 3";
  return null;
}
const blokFmt = (b) => (/^[IVX]+$/i.test(b) ? `Blok ${b}` : b);
function unitOf(name) {
  let m = name.match(/(?:JRK\d?|JRS\d?|JRS5|KRIAN ?\d)[ _]([IVX]{1,4}|RAYA|RUHA|PER|BSN|[A-Z]{1,3})-(\d+[A-Z]?)/i);
  if (m) { const b = m[1].toUpperCase(); return { blok: blokFmt(b), unitNo: `${b}-${m[2]}` }; }
  m = name.match(/\bPER[ _](\d+[A-Z]?)(?=[_ .)]|$)/i);
  if (m) return { blok: "PER", unitNo: `PER-${m[1]}` };
  m = name.match(/[ _]([IVX]{1,4}|RAYA|RUHA|BSN)-(\d+[A-Z]?)(?=[_ .)]|$)/i);
  if (m) { const b = m[1].toUpperCase(); return { blok: blokFmt(b), unitNo: `${b}-${m[2]}` }; }
  return { blok: null, unitNo: null };
}
const BANK_NOTARIS = /BTN|BNI|BCA|MANDIRI|SBD|SDA|\bKPR\b|\bNOT\b|AYU MB|NOTARIS|CASH|\bMB\b|BTNS|\bBRI\b|HAIRIR/i;
const CLUSTERCODE = /^(JRK\d?|JRS\d?|C\d\w*|PER|RUHA|RAYA)$/i;
function buyerOf(name) {
  const base = name.replace(/\.[^.]+$/, "").replace(/\(.*?\)/g, "");
  for (const seg of base.split("_").slice(1)) {
    const s = seg.trim();
    if (s.length < 4 || s.length > 42 || /\d/.test(s)) continue;
    if (BANK_NOTARIS.test(s) || CLUSTERCODE.test(s)) continue;
    if (!/^[A-Za-z][A-Za-z .,'’-]+$/.test(s)) continue;
    return s.replace(/\b\w+/g, (w) => w[0] + w.slice(1).toLowerCase());
  }
  return null;
}
const PT_MAP = [["PROPERTINDO","PT Mitra Usaha Propertindo"],["INVESTAMA","PT Mitra Usaha Investama"],["KONSTRUKSI","PT Mitra Usaha Konstruksi"],["NIAGA LOGISTINDO","PT Mitra Niaga Logistindo"],["WIRA TATA","PT Wira Tata Mitra"],["AZA","PT AZA Anugerah Pratama"],["BNI","PT Bank BNI"],["MANDIRI","PT Bank Mandiri"]];
function entityOf(P) { const u = P.toUpperCase(); for (const [k, v] of PT_MAP) if (u.includes(k)) return v; return "Java Residence (umum)"; }
function docNumberOf(name) {
  const b = name.replace(/\.[^.]+$/, "");
  let m = b.match(/HGB[ _]?(\d+)/i); if (m) return `HGB ${m[1]}`;
  m = b.match(/_(\d{1,4}) (\d{4}) \(/); if (m) return `${m[1]}/${m[2]}`;
  m = b.match(/(\d{2,4}[\/_]\d{2,4}[\/_]\d{2,4})/); if (m) return m[1].replace(/_/g, "/");
  m = b.match(/^(\d{1,3})[ ._]/); if (m) return m[1];
  return null;
}
function dateOf(name) {
  const m = name.match(/\((\d{2})(\d{2})(\d{4})\)/);
  if (m) { const dt = new Date(`${m[3]}-${m[2]}-${m[1]}T00:00:00`); return Number.isNaN(dt.getTime()) ? null : dt; }
  return null;
}
const SHORT = { PPJB:"PPJB", AJB:"AJB", Sertifikat:"Sertifikat", BPHTB:"BPHTB", PPh:"PPh", BA_STU:"BA STU", PPN_DTP:"PPN DTP", IzinLokasi:"Izin Lokasi", SKRK:"SKRK", PBG:"PBG", PetaBidang:"Peta Bidang Tanah", Perizinan:"Perizinan", Siteplan:"Siteplan", PKS:"PKS", AktaPendirian:"Akta Pendirian", RUPS:"RUPS", SKKumham:"SK Kumham", APH:"Akta Pelepasan Hak", Template:"Template", Other:"Dokumen" };
const SCOPE = (t) => ["PPJB","AJB","Sertifikat","BPHTB","PPh","BA_STU","PPN_DTP"].includes(t) ? "unit" : ["PKS","AktaPendirian","RUPS","SKKumham"].includes(t) ? "entity" : "development";
const MIME_EXT = { ".pdf": "application/pdf", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png", ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" };
const ACCESS = (t) => ["Sertifikat","IzinLokasi","SKRK","PBG","PetaBidang","Perizinan","Siteplan"].includes(t) ? "Leadership" : ["BA_STU","PPN_DTP","PPh","BPHTB"].includes(t) ? "SalesVisible" : "LegalOnly";
const STATUS = (t) => t === "AJB" ? "Registered" : t === "PPJB" ? "Signed" : "Active";

async function main() {
  const legal = await prisma.user.findMany({ where: { role: { in: ["LEGAL_PIC", "HEAD_OF_LEGAL"] } } });
  if (!legal.length) { console.error("No legal users. Run `npm run db:seed` first."); process.exit(1); }
  const byName = (n) => legal.find((u) => u.name.includes(n)) || legal[0];
  const rina = byName("Rina"), dewiL = byName("Dewi Lestari"), budi = byName("Budi");
  // PIC default: corporate/PKS → Head of Legal; clusters split between the two Legal PICs.
  const picFor = (r) => {
    if (r.scope === "entity") return budi;
    const c = r.cluster || "";
    if (/Krian 1|Krian 2/.test(c)) return rina;
    if (/Krian 3|Sukodono|Cluster 2|Cluster 3/.test(c)) return dewiL;
    return rina;
  };

  const all = ROOTS.flatMap((r) => walk(r)).filter((f) => !EXCLUDE_PATH.test(f));
  const clean = [], review = [], seen = new Set();

  for (const f of all) {
    const name = path.basename(f);
    const rel = f.replace(ROOT_PARENT + path.sep, "");
    const type = classifyType(f);
    if (!type) { review.push([rel, "", "unclassified"]); continue; }
    const scope = SCOPE(type);
    const rec = { type, scope, file: f, name, rel };
    if (scope === "unit") { rec.cluster = clusterOf(f); Object.assign(rec, unitOf(name)); rec.buyer = buyerOf(name); }
    if (scope === "entity") rec.entity = entityOf(f);
    rec.docNumber = docNumberOf(name);
    rec.issueDate = dateOf(name);

    // completeness gate
    if (scope === "unit" && !rec.unitNo) { review.push([rel, type, "missing unit"]); continue; }

    // dedup by type+unit+docNumber when a doc number exists
    const key = rec.docNumber ? `${type}|${rec.unitNo || rec.entity || ""}|${rec.docNumber}` : null;
    if (key && seen.has(key)) { review.push([rel, type, "duplicate"]); continue; }
    if (key) seen.add(key);
    clean.push(rec);
  }

  console.log(`Found ${all.length} files · importing ${clean.length} · review ${review.length}`);
  console.log("Clearing existing documents (demo data + their access requests cascade)…");
  await prisma.documentRelation.deleteMany();
  await prisma.documentFile.deleteMany();
  await prisma.document.deleteMany(); // cascades AccessRequest -> ApprovalStep/AuditEntry

  let n = 0;
  const CHUNK = 100;
  for (let i = 0; i < clean.length; i += CHUNK) {
    await Promise.all(clean.slice(i, i + CHUNK).map((r) => {
      const loc = r.scope === "unit" ? r.unitNo : r.scope === "entity" ? r.entity : (r.cluster || "Java Residence");
      let st; try { st = statSync(r.file); } catch { st = null; }
      const owner = picFor(r);
      return prisma.document.create({
        data: {
          docType: r.type,
          title: `${SHORT[r.type]} — ${loc}`,
          docNumber: r.docNumber ?? null,
          scope: r.scope,
          cluster: r.scope === "unit" ? r.cluster ?? null : null,
          blok: r.scope === "unit" ? r.blok ?? null : null,
          unitNo: r.scope === "unit" ? r.unitNo ?? null : null,
          buyerName: r.scope === "unit" ? r.buyer ?? null : null,
          entityName: r.scope === "entity" ? r.entity ?? null : null,
          status: STATUS(r.type),
          issueDate: r.issueDate ?? null,
          picId: owner.id,
          uploadedById: owner.id,
          uploadedAt: r.issueDate ?? (st ? st.mtime : new Date()),
          originalLocation: "OfficeSafe",
          accessLevel: ACCESS(r.type),
          files: { create: [{ url: `/api/file?p=${encodeURIComponent(r.rel)}`, fileName: r.name, label: "original (in-place)", mimeType: MIME_EXT[path.extname(r.name).toLowerCase()] ?? null }] },
        },
      });
    }));
    n += Math.min(CHUNK, clean.length - i);
    process.stdout.write(`\r  inserted ${n}/${clean.length}`);
  }
  console.log("");

  const csv = "path,guessed_type,reason\n" + review.map(([p, t, why]) => `"${p}","${t}","${why}"`).join("\n");
  const csvPath = path.join(process.cwd(), "import-review.csv");
  writeFileSync(csvPath, csv);

  const total = await prisma.document.count();
  console.log(`\nDone. ${total} documents in the directory.`);
  console.log(`Review list (${review.length} items) → ${csvPath}`);
  console.log(`Restore demo data with: npm run db:reset`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
