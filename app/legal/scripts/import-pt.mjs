// PT / corporate entity documents from the new source. Generalized over all PT
// folders (supersedes import-pt-aza.mjs). ADDITIVE + idempotent per entity:
// clears each entity's docs first, then re-inserts. Files referenced in place.
// Per data owner: append a short entity tag to each doc name (search is by name).
//   Run: node scripts/import-pt.mjs
import { readdirSync, statSync } from "fs";
import path from "path";
import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();
const ROOT_PARENT = path.resolve(process.cwd(), "..");
const BASE = path.join(ROOT_PARENT, "DASHBOARD DATA USED");

const ENTITIES = [
  { folder: "PT.AZA ANUGERAH PRATAMA",   entity: "PT AZA Anugerah Pratama", tag: "(PT AZA AP)", keyword: /AZA/i },
  { folder: "PT.MITRA NIAGA LOGISTINDO", entity: "PT Mitra Niaga Logistindo", tag: "(PT MNL)", keyword: /LOGISTINDO|\bMNL\b/i },
  { folder: "PT.MITRA USAHA INVESTAMA",  entity: "PT Mitra Usaha Investama", tag: "(PT MUI)", keyword: /INVESTAMA|\bMUI\b/i,
    exclude: /\/PT AZA\/|\/PT WIRA TATA\// }, // nested copies of other entities → skip
  { folder: "PT.MITRA USAHA KONSTRUKSI", entity: "PT Mitra Usaha Konstruksi", tag: "(PT MUK)", keyword: /KON[ST]?TRUKSI|\bMUK\b/i },
  { folder: "PT.MITRA USAHA PROPERTINDO", entity: "PT Mitra Usaha Propertindo", tag: "(PT MUP)", keyword: /PROPERTINDO|\bMUP\b/i },
  { folder: "PT.WIRA TATA MITRA",        entity: "PT Wira Tata Mitra", tag: "(PT WTM)", keyword: /WIRA|\bWTM\b/i },
];

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
  const has = (re) => re.test(name);
  if (has(/\bKTP\b|\bNPWP\b|\bNIB\b|\bPKP\b|SKDU|DOMISILI|\bKK\b|BUKU NIKAH|PROFIL|ANUITAS|KEANGGOTAAN|PIAGAM|STRUKTUR SAHAM|KESEPAKATAN|PEMBERITAHUAN|ANGGARAN DASAR|PERSEROAN|CETAK_|\bSP\b|LAMPIRAN|\bPBB\b|IMG[-_]|WHATSAPP/)) return "Other";
  if (has(/JUAL BELI SAHAM|JUBEL SAHAM|PEMEGANG SAHAM|PERNYATAAN KEPUTUSAN|BERITA ACARA|\bRUPS\b|\bBAR\b|RAPAT|PENEGASAN/)) return "RUPS";
  if (has(/MENKUMHAM|KUMHAM|PENGESAHAN/)) return "SKKumham";
  if (has(/PENDIRIAN/)) return "AktaPendirian";
  if (has(/\bAJB\b/)) return "AJB";
  if (has(/SERTIP|SERTIF|\bHGB\b|\bSHM\b|\bHM\b/)) return "Sertifikat";
  if (has(/PERIZINAN|\bSIUP\b|\bTDP\b/)) return "Perizinan";
  if (/AKTA PENDIRIAN/.test(dir)) return "AktaPendirian";
  if (/RUPS/.test(dir)) return "RUPS";
  return "Other";
}

function parseDate(name) {
  let m = name.match(/\((\d{2})-(\d{2})-(\d{4})\)/) || name.match(/\((\d{2})(\d{2})(\d{4})\)/);
  if (!m) return null;
  const d = new Date(`${m[3]}-${m[2]}-${m[1]}T00:00:00Z`);
  return Number.isNaN(d.getTime()) ? null : d;
}

function titleFor(full, cfg) {
  const base = path.basename(full, path.extname(full)).trim();
  return cfg.keyword.test(base) ? base : `${base} ${cfg.tag}`;
}
const dedupKey = (full) => path.basename(full, path.extname(full)).toLowerCase().replace(/\s*\(\d+\)\s*$/, "").trim();

async function main() {
  const head = await prisma.user.findFirst({ where: { role: "HEAD_OF_LEGAL" } })
    || await prisma.user.findFirst({ where: { role: "LEGAL_PIC" } });
  if (!head) { console.error("No legal users. Run `npm run db:seed` first."); process.exit(1); }

  const grand = {};
  for (const cfg of ENTITIES) {
    const src = path.join(BASE, cfg.folder);
    let files;
    try { files = walk(src); } catch { console.log(`! missing folder: ${cfg.folder}`); continue; }
    if (cfg.exclude) files = files.filter((f) => !cfg.exclude.test(f.replace(/\\/g, "/")));

    const removed = await prisma.document.deleteMany({ where: { entityName: cfg.entity } });
    const seen = new Set(), counts = {};
    let n = 0;
    for (const f of files) {
      const k = dedupKey(f);
      if (seen.has(k)) continue; // drop "X (1)" duplicate scans
      seen.add(k);
      const type = classify(f);
      counts[type] = (counts[type] || 0) + 1;
      const rel = f.replace(ROOT_PARENT + path.sep, "");
      const fileName = path.basename(f);
      await prisma.document.create({
        data: {
          docType: type, title: titleFor(f, cfg), scope: "entity", entityName: cfg.entity,
          status: "Active", issueDate: parseDate(fileName), picId: head.id, uploadedById: head.id,
          uploadedAt: parseDate(fileName) ?? new Date(), originalLocation: "OfficeSafe", accessLevel: "LegalOnly",
          files: { create: [{ url: `/api/file?p=${encodeURIComponent(rel)}`, fileName, label: "original (in-place)", mimeType: MIME[path.extname(fileName).toLowerCase()] ?? null }] },
        },
      });
      n++;
    }
    grand[cfg.entity] = n;
    console.log(`${cfg.entity} (${cfg.tag}): ${n} docs${removed.count ? ` [re-run: removed ${removed.count}]` : ""} →`, counts);
  }
  const total = Object.values(grand).reduce((a, b) => a + b, 0);
  console.log(`\nTotal entity docs imported: ${total}`);
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
