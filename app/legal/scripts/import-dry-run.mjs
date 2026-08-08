// DRY RUN v2 — reads ONLY file/folder names, writes NOTHING.
// Parses real legal documents into the app schema using the observed naming
// conventions. Run: node scripts/import-dry-run.mjs
import { readdirSync, statSync } from "fs";
import path from "path";

const ROOTS = [
  path.resolve(process.cwd(), "..", "DEPARTEMEN LEGAL"),
  path.resolve(process.cwd(), "..", "LEGALITAS"),
];
const DOC_EXT = new Set([".pdf", ".jpg", ".jpeg", ".png", ".xlsx"]);
const SKIP_NAME = /thumbs\.db|\.ds_store|^~\$/i;
// Not legal documents → excluded from the import entirely.
const EXCLUDE_PATH = /\/Foto ?Unit\/|\/B-?ROLL\/|Foto GPS|\(Foto GPS\)|\/Desember\/|File Compres|WhatsApp Image|IMG[-_]\d/i;

function walk(dir, out = []) {
  let entries;
  try { entries = readdirSync(dir); } catch { return out; }
  for (const name of entries) {
    const full = path.join(dir, name);
    let st; try { st = statSync(full); } catch { continue; }
    if (st.isDirectory()) walk(full, out);
    else if (DOC_EXT.has(path.extname(name).toLowerCase()) && !SKIP_NAME.test(name)) out.push(full);
  }
  return out;
}

// ---- type: check the FILENAME first (most specific), then folder context ----
function classifyType(full) {
  const name = path.basename(full).toUpperCase();
  const dir = path.dirname(full).toUpperCase();
  const test = (re) => re.test(name) || re.test(dir);
  if (test(/PPN[ _]?DTP|SUPER PPN/)) return "PPN_DTP";
  if (test(/BA ?STU|BAST|SERAH TERIMA/)) return "BA_STU";
  if (/COVERNOTE/.test(name)) return "Other";
  if (test(/PELEPASAN HAK|APHB/)) return "APH";
  if (test(/BPHTB/)) return "BPHTB";
  if (test(/VALIDASI PPH|PPH JUAL|\bPPH\b|\bSKET\b/)) return "PPh";
  if (test(/SERTIF|\bHGB\b|\bSHM\b|SERTIFIKAT/)) return "Sertifikat";
  if (test(/\bAJB\b|AKTA JUAL BELI/)) return "AJB";
  if (test(/PPJB|PIJB/)) return "PPJB";
  if (test(/IZIN LOKASI|PKKPR/)) return "IzinLokasi";
  if (test(/PERTEK|RKKPR|\bPBT\b|SK LSD|SKRK|\bPBG\b|PERIZINAN/)) return "Perizinan";
  if (test(/SITEPLAN|GAMBAR|SIKUMBANG|LAYOUT|KIR /)) return "Siteplan";
  if (test(/\bPKS\b/)) return "PKS";
  if (test(/AKTA PENDIRIAN|AKTA MUP|AKTA AZA|AKTA NO/)) return "AktaPendirian";
  if (test(/RUPS/)) return "RUPS";
  if (test(/KUMHAM|PENGESAHAN/)) return "SKKumham";
  if (test(/\bKTP\b|NPWP/)) return "Other";
  if (test(/TANDA TERIMA|\bTT\b/)) return "Other";
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

// blok (roman / RAYA / RUHA / PER / BSN) + unit number — from the filename.
// NB: units are followed by "_" (a regex word char), so we must NOT anchor with \b.
const blokFmt = (b) => (/^[IVX]+$/i.test(b) ? `Blok ${b}` : b);
function unitOf(name) {
  // primary: "<cluster> <blok>-<unit>", e.g. "JRK VIII-11", "JRK2 RAYA-50", "JRK1_I-15", "KRIAN 2 II-1"
  let m = name.match(/(?:JRK\d?|JRS\d?|JRS5|KRIAN ?\d)[ _]([IVX]{1,4}|RAYA|RUHA|PER|BSN|[A-Z]{1,3})-(\d+[A-Z]?)/i);
  if (m) { const b = m[1].toUpperCase(); return { blok: blokFmt(b), unitNo: `${b}-${m[2]}` }; }
  // "PER_1" / "PER 1"
  m = name.match(/\bPER[ _](\d+[A-Z]?)(?=[_ .)]|$)/i);
  if (m) return { blok: "PER", unitNo: `PER-${m[1]}` };
  // bare "RAYA-6" / "I-15" preceded by a separator
  m = name.match(/[ _]([IVX]{1,4}|RAYA|RUHA|BSN)-(\d+[A-Z]?)(?=[_ .)]|$)/i);
  if (m) { const b = m[1].toUpperCase(); return { blok: blokFmt(b), unitNo: `${b}-${m[2]}` }; }
  return { blok: null, unitNo: null };
}

const BANK_NOTARIS = /BTN|BNI|BCA|MANDIRI|SBD|SDA|\bKPR\b|\bNOT\b|AYU MB|NOTARIS|CASH|\bMB\b|BTNS/i;
const CLUSTERCODE = /^(JRK\d?|JRS\d?|C\d\w*|PER|RUHA|RAYA)$/i;

// buyer = first person-name segment (after type), excluding banks/notaris/cluster/numbers
function buyerOf(name) {
  const base = name.replace(/\.[^.]+$/, "").replace(/\(.*?\)/g, "");
  for (const seg of base.split("_").slice(1)) {
    const s = seg.trim();
    if (s.length < 4 || s.length > 42) continue;
    if (/\d/.test(s)) continue;
    if (BANK_NOTARIS.test(s) || CLUSTERCODE.test(s)) continue;
    if (!/^[A-Za-z][A-Za-z .,'’-]+$/.test(s)) continue;
    return s.replace(/\b\w/g, (c) => c.toUpperCase()).replace(/\b\w+/g, (w) => w[0] + w.slice(1).toLowerCase());
  }
  return null;
}

const PT_MAP = [
  ["PROPERTINDO", "PT Mitra Usaha Propertindo"], ["INVESTAMA", "PT Mitra Usaha Investama"],
  ["KONSTRUKSI", "PT Mitra Usaha Konstruksi"], ["NIAGA LOGISTINDO", "PT Mitra Niaga Logistindo"],
  ["WIRA TATA", "PT Wira Tata Mitra"], ["AZA", "PT AZA Anugerah Pratama"],
  ["BNI", "PT Bank BNI"], ["MANDIRI", "PT Bank Mandiri"], ["AREBI", "Agen (Arebi)"],
];
function entityOf(P) { const u = P.toUpperCase(); for (const [k, v] of PT_MAP) if (u.includes(k)) return v; return null; }

function docNumberOf(name) {
  const b = name.replace(/\.[^.]+$/, "");
  let m = b.match(/HGB[ _]?(\d+)/i); if (m) return `HGB ${m[1]}`;
  m = b.match(/_(\d{1,4}) (\d{4}) \(/); if (m) return `${m[1]}/${m[2]}`; // "_30 2024 ("
  m = b.match(/(\d{2,4}[\/_]\d{2,4}[\/_]\d{2,4})/); if (m) return m[1].replace(/_/g, "/");
  m = b.match(/^(\d{1,3})[ ._]/); if (m) return m[1];
  return null;
}

const SCOPE = (t) =>
  ["PPJB", "AJB", "Sertifikat", "BPHTB", "PPh", "BA_STU", "PPN_DTP"].includes(t) ? "unit"
  : ["PKS", "AktaPendirian", "RUPS", "SKKumham"].includes(t) ? "entity" : "development";

// ---- run ----
const all = ROOTS.flatMap((r) => walk(r));
const excluded = all.filter((f) => EXCLUDE_PATH.test(f));
const files = all.filter((f) => !EXCLUDE_PATH.test(f));
const typeCount = {}, mapped = [], unmapped = [];
let buyerFound = 0, unitFound = 0;

for (const f of files) {
  const name = path.basename(f);
  const rel = f.replace(path.resolve(process.cwd(), "..") + "/", "");
  const t = classifyType(f);
  if (!t) { unmapped.push(rel); continue; }
  typeCount[t] = (typeCount[t] || 0) + 1;
  const scope = SCOPE(t);
  const rec = { type: t, scope, name };
  if (scope === "unit") { rec.cluster = clusterOf(f); Object.assign(rec, unitOf(name)); rec.buyer = buyerOf(name); if (rec.unitNo) unitFound++; if (rec.buyer) buyerFound++; }
  if (scope === "entity") rec.entity = entityOf(f);
  rec.docNumber = docNumberOf(name);
  mapped.push(rec);
}

const unitDocs = mapped.filter((r) => r.scope === "unit").length;
console.log(`\n===== DRY RUN v2 (no DB writes) =====`);
console.log(`Scanned ${all.length} · excluded(photos) ${excluded.length} · classified ${mapped.length} · unmapped ${unmapped.length}`);
console.log(`Unit docs: ${unitDocs} · with unit parsed ${unitFound} (${Math.round(unitFound/unitDocs*100)}%) · with buyer parsed ${buyerFound} (${Math.round(buyerFound/unitDocs*100)}%)\n`);
console.log(`Type distribution:`);
Object.entries(typeCount).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => console.log(`  ${k.padEnd(14)} ${v}`));

const sample = (arr, n) => { const step = Math.max(1, Math.floor(arr.length / n)); return arr.filter((_, i) => i % step === 0).slice(0, n); };
console.log(`\n----- sample mapped (${Math.min(28, mapped.length)} of ${mapped.length}) -----`);
for (const r of sample(mapped, 28)) {
  const loc = r.scope === "unit" ? `${r.cluster ?? "?"} · ${r.unitNo ?? "?"}${r.buyer ? " · " + r.buyer : ""}`
            : r.scope === "entity" ? (r.entity ?? "?") : "(development)";
  console.log(`  [${r.type}] ${loc}${r.docNumber ? " · #" + r.docNumber : ""}\n      ↳ ${r.name}`);
}
console.log(`\n----- sample UNMAPPED (${Math.min(12, unmapped.length)} of ${unmapped.length}) → review list -----`);
for (const u of sample(unmapped, 12)) console.log(`  ${u}`);
console.log("");
