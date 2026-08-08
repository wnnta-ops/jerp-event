// DRY RUN — parse AJB from the new "DASHBOARD DATA USED" source. No DB writes.
// Cluster comes from the top-level folder (C1/C2/C3/C5), per the data owner.
import { readdirSync, statSync } from "fs";
import path from "path";

const ROOT = path.resolve(process.cwd(), "..", "DASHBOARD DATA USED", "AKTA JUAL BELI");
const DOC_EXT = new Set([".pdf", ".jpg", ".jpeg", ".png"]);

// Top-level folder -> cluster (skip "New folder" = duplicate copies).
function clusterFor(topDir) {
  const u = topDir.toUpperCase();
  if (u.startsWith("NEW FOLDER")) return null;
  if (u.startsWith("C1")) return "Cluster 1";
  if (u.startsWith("C2")) return "Cluster 2";
  if (u.startsWith("C3")) return "Cluster 3";
  if (u.includes("C5")) return "Cluster 5";
  return null;
}
function phaseFor(topDir) {
  const m = topDir.match(/\bT\s?(\d(?:-\d)?)\b/i);
  return m ? `T${m[1]}` : null;
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
  // codeSeg e.g. "JRK I-10", "JRK2 II-11A", "JRS5 II-.21", "JRK RUHA-17"
  const m = codeSeg.match(/(?:JR[KS]\d?\s+)?([IVX]+|RAYA|RUHA)\s*-\s*\.?\s*(\d+[A-Za-z]?)/i);
  if (!m) return { blok: null, unitNo: null };
  const blok = m[1].toUpperCase();
  return { blok: blokFmt(blok), unitNo: `${blok}-${m[2]}` };
}
function parseDate(seg) {
  const m = seg.match(/\((\d{2})(\d{2})(\d{4})\)/);
  if (!m) return null;
  const dt = new Date(`${m[3]}-${m[2]}-${m[1]}T00:00:00Z`);
  return Number.isNaN(dt.getTime()) ? null : dt;
}
function parseDocNo(seg) {
  const m = seg.match(/^\s*(\d{1,4})\s+(\d{4})\b/);
  return m ? `${m[1]}/${m[2]}` : null;
}

const tops = readdirSync(ROOT).filter((n) => {
  try { return statSync(path.join(ROOT, n)).isDirectory(); } catch { return false; }
});

const rows = [], bad = [];
const byCluster = {};
for (const top of tops) {
  const cluster = clusterFor(top);
  if (!cluster) continue; // skip New folder
  const phase = phaseFor(top);
  for (const f of walk(path.join(ROOT, top))) {
    const name = path.basename(f, path.extname(f));
    const parts = name.split("_");
    const { blok, unitNo } = parseUnit(parts[1] || "");
    const buyer = (parts[2] || "").trim() || null;
    const docNo = parseDocNo(parts[3] || "");
    const issueDate = parseDate(name);
    const rec = { cluster, phase, blok, unitNo, buyer, docNo, issueDate, name };
    if (!unitNo || !buyer) bad.push(rec); else rows.push(rec);
    byCluster[cluster] = (byCluster[cluster] || 0) + 1;
  }
}

console.log("=== AJB files by cluster (excl. New folder) ===");
for (const c of Object.keys(byCluster).sort()) console.log(`  ${byCluster[c]}  ${c}`);
console.log(`\nParsed cleanly: ${rows.length} · needs review (no unit/buyer): ${bad.length}`);
console.log("\n=== sample parsed ===");
for (const r of rows.slice(0, 8)) console.log(`  ${r.cluster}${r.phase ? "/" + r.phase : ""} · ${r.unitNo} · ${r.buyer} · ${r.docNo || "—"} · ${r.issueDate ? r.issueDate.toISOString().slice(0, 10) : "—"}`);
if (bad.length) { console.log("\n=== sample needs-review ==="); for (const r of bad.slice(0, 12)) console.log(`  [${r.cluster}] ${r.name}`); }
