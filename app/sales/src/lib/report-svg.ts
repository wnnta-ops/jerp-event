import type { DailyReportData } from "./derivations";
import { formatIdDateLong, parseDayKey, wibClock } from "./wib";

// Colors — matched to app globals.css theme.
const MAIN = "#f0a94e";
const TEAL = "#4ec3c7";
const GREEN = "#5cc2a8";
const BLUE = "#7db8e8";
const RED = "#f58c8c";
const INK = "#41505f";
const MUTED = "#9aa7b6";
const LINE = "#e6ecec";
const GRID = "#eef0f3";
const SOFT = "#fbedd8";
const SOFTINK = "#8a4d13";

function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/**
 * Portrait data-viz report for the WhatsApp group — KPI tiles, WI split bars,
 * UTJ chips, progress bars, weekly chart. All DERIVED (never re-typed). "Total"
 * figures are month-to-date through the report day. Height grows with content.
 */
export function buildDailyReportSVG(d: DailyReportData): string {
  const dateLabel = formatIdDateLong(parseDayKey(d.dayKey));
  const r = d.recap;
  const p: string[] = [];
  const M = 32;
  const W = 760;
  const INNER = W - 2 * M;
  let y = 120;

  const text = (x: number, yy: number, size: number, weight: number, fill: string, t: string, anchor = "start") =>
    `<text x="${x}" y="${yy}" font-size="${size}" font-weight="${weight}" fill="${fill}" text-anchor="${anchor}">${esc(t)}</text>`;
  const label = (t: string) => {
    p.push(`<text x="${M}" y="${y}" font-size="11" font-weight="700" fill="${MUTED}" letter-spacing="1.4">${esc(t)}</text>`);
    y += 18;
  };

  // ---- KPI strip — 3 kolom (UTJ / SPR / NUP), semua HARI INI ----
  const kw = (INNER - 2 * 12) / 3;
  const kh = 104;
  // grouped card: title + 3 rows (gross/nett/gagal)
  const group = (x: number, title: string, accent: string, rows: [string, number, string][]) => {
    p.push(`<rect x="${x}" y="${y}" width="${kw}" height="${kh}" rx="7" fill="#ffffff" stroke="${LINE}"/>`);
    p.push(`<rect x="${x}" y="${y}" width="${kw}" height="30" rx="7" fill="${accent}"/><rect x="${x}" y="${y + 14}" width="${kw}" height="16" fill="${accent}"/>`);
    p.push(text(x + 14, y + 20, 12.5, 800, "#ffffff", title));
    rows.forEach(([lab, val, col], i) => {
      const ry = y + 30 + 16 + i * 20;
      p.push(text(x + 14, ry, 12, 600, MUTED, lab));
      p.push(text(x + kw - 14, ry, 16, 800, col, String(val), "end"));
    });
  };
  group(M, "UTJ HARI INI", TEAL, [
    ["Gross", r.utjGross, INK],
    ["Nett", r.utjNett, TEAL],
    ["Gagal", r.utjGagal, RED],
  ]);
  group(M + kw + 12, "SPR HARI INI", GREEN, [
    ["Gross", r.sprGross, INK],
    ["Nett", r.sprNett, GREEN],
    ["Gagal", r.sprGagal, RED],
  ]);
  // NUP card — single big number
  const nx = M + 2 * (kw + 12);
  p.push(`<rect x="${nx}" y="${y}" width="${kw}" height="${kh}" rx="7" fill="#ffffff" stroke="${LINE}"/>`);
  p.push(`<rect x="${nx}" y="${y}" width="${kw}" height="30" rx="7" fill="${BLUE}"/><rect x="${nx}" y="${y + 14}" width="${kw}" height="16" fill="${BLUE}"/>`);
  p.push(text(nx + 14, y + 20, 12.5, 800, "#ffffff", "NUP HARI INI"));
  p.push(text(nx + kw / 2, y + 82, 44, 800, BLUE, String(r.nup), "middle"));
  y += kh + 26;

  // ---- WI hari ini ----
  const wiTotal = r.wiByCluster.reduce((s, c) => s + c.total, 0);
  label(`WI HARI INI · ${wiTotal} PENGUNJUNG`);
  y += 4;
  if (r.wiByCluster.length === 0) {
    p.push(text(M, y + 6, 13, 500, MUTED, "Belum ada WI hari ini."));
    y += 24;
  } else {
    const tx = M + 92;
    const tw = INNER - 92 - 36;
    for (const c of r.wiByCluster) {
      const total = c.total || 1;
      const mw = (c.murni / total) * tw;
      const jw = (c.janjian / total) * tw;
      p.push(text(M, y + 17, 13, 700, INK, c.cluster));
      p.push(`<rect x="${tx}" y="${y}" width="${tw}" height="24" rx="5" fill="${GRID}"/>`);
      p.push(`<clipPath id="wc${y}"><rect x="${tx}" y="${y}" width="${tw}" height="24" rx="5"/></clipPath>`);
      p.push(`<g clip-path="url(#wc${y})">`);
      p.push(`<rect x="${tx}" y="${y}" width="${mw}" height="24" fill="${TEAL}"/>`);
      p.push(`<rect x="${tx + mw}" y="${y}" width="${jw}" height="24" fill="${BLUE}"/>`);
      if (mw > 16) p.push(text(tx + mw / 2, y + 16, 11, 700, "#ffffff", String(c.murni), "middle"));
      if (jw > 16) p.push(text(tx + mw + jw / 2, y + 16, 11, 700, "#ffffff", String(c.janjian), "middle"));
      p.push(`</g>`);
      p.push(text(W - M, y + 17, 15, 800, INK, String(c.total), "end"));
      y += 32;
    }
    y += 2;
    p.push(`<rect x="${M}" y="${y - 2}" width="11" height="11" rx="3" fill="${TEAL}"/>`);
    p.push(text(M + 17, y + 8, 11.5, 500, MUTED, "Murni (site/pameran)"));
    p.push(`<rect x="${M + 170}" y="${y - 2}" width="11" height="11" rx="3" fill="${BLUE}"/>`);
    p.push(text(M + 187, y + 8, 11.5, 500, MUTED, "Janjian"));
    y += 22;
  }
  y += 16;

  // ---- UTJ hari ini chips ----
  label("UTJ HARI INI · PER CLUSTER·PHASE");
  y += 6;
  let cx = M;
  const chip = (t: string, muted = false) => {
    const w = 22 + t.length * 8;
    if (cx + w > W - M) { cx = M; y += 34; }
    p.push(`<rect x="${cx}" y="${y}" width="${w}" height="28" rx="5" fill="${muted ? GRID : SOFT}"/>`);
    p.push(text(cx + w / 2, y + 19, 13, 700, muted ? MUTED : SOFTINK, t, "middle"));
    cx += w + 8;
  };
  if (r.utjToday.length === 0) chip("Belum ada UTJ", true);
  else for (const u of r.utjToday) chip(`${u.code}  ${u.n}`);
  y += 28 + 20;

  // ---- mini stats (Waiting List / Hold Unit — snapshot) ----
  const mini = [
    { l: "Waiting List", v: r.waitingList },
    { l: "Hold Unit", v: r.holdUnit },
  ];
  const mw2 = (INNER - 11) / 2;
  mini.forEach((m, i) => {
    const x = M + i * (mw2 + 11);
    p.push(`<rect x="${x}" y="${y}" width="${mw2}" height="44" rx="8" fill="#ffffff" stroke="${LINE}"/>`);
    p.push(text(x + 15, y + 27, 12.5, 500, MUTED, m.l));
    p.push(text(x + mw2 - 15, y + 30, 22, 800, INK, String(m.v), "end"));
  });
  y += 44 + 24;

  // ---- progress target ----
  label("PROGRESS TARGET");
  y += 6;
  const boxY = y;
  p.push(`<rect x="${M}" y="${boxY}" width="${INNER}" height="96" rx="10" fill="#f8fbfb" stroke="${LINE}"/>`);
  const barW = INNER - 44;
  const monthFill = Math.min(d.monthTarget ? d.monthNet / d.monthTarget : 0, 1) * barW;
  const annualFill = Math.min(d.annualTarget ? d.sprYtd / d.annualTarget : 0, 1) * barW;
  p.push(`<text x="${M + 22}" y="${boxY + 26}" font-size="13" fill="${INK}">Bulan ini (UTJ Nett): <tspan font-weight="800">${d.monthNet} / ${d.monthTarget}</tspan> · <tspan font-weight="800" fill="${GREEN}">${d.monthPct}%</tspan></text>`);
  p.push(`<rect x="${M + 22}" y="${boxY + 33}" width="${barW}" height="11" rx="4" fill="${GRID}"/><rect x="${M + 22}" y="${boxY + 33}" width="${monthFill}" height="11" rx="4" fill="${GREEN}"/>`);
  p.push(`<text x="${M + 22}" y="${boxY + 68}" font-size="13" fill="${INK}">Target 2026 (SPR): <tspan font-weight="800">${d.sprYtd} / ${d.annualTarget}</tspan> · <tspan font-weight="800" fill="${TEAL}">${d.annualPct}%</tspan></text>`);
  p.push(`<rect x="${M + 22}" y="${boxY + 75}" width="${barW}" height="11" rx="4" fill="${GRID}"/><rect x="${M + 22}" y="${boxY + 75}" width="${annualFill}" height="11" rx="4" fill="${TEAL}"/>`);
  y = boxY + 96 + 26;

  // ---- UTJ net per tim ----
  const teams = d.teams.filter((t) => t.teamId).slice(0, 2);
  if (teams.length > 0) {
    label("UTJ NET PER TIM");
    y += 10;
    for (const t of teams) {
      const pr = t.target ? Math.min(t.net / t.target, 1) : 0;
      const col = t.target && t.net >= t.target ? GREEN : MAIN;
      p.push(text(M, y + 11, 13, 700, INK, t.tim));
      p.push(text(W - M, y + 11, 13, 700, col, `${t.net} / ${t.target}`, "end"));
      y += 19;
      p.push(`<rect x="${M}" y="${y}" width="${INNER}" height="12" rx="4" fill="${GRID}"/><rect x="${M}" y="${y}" width="${pr * INNER}" height="12" rx="4" fill="${col}"/>`);
      y += 30;
    }
    y += 8;
  }

  // ---- weekly trend ----
  label("TREND UTJ MINGGUAN (NET)");
  y += 8;
  const baseY = y + 104;
  const weekly = d.weekly;
  const wkMax = Math.max(...weekly.map((v) => Math.abs(v)), 1);
  const n = weekly.length || 1;
  const slot = INNER / n;
  const bw = Math.min(64, slot * 0.6);
  p.push(`<line x1="${M}" y1="${baseY}" x2="${W - M}" y2="${baseY}" stroke="${LINE}"/>`);
  weekly.forEach((v, i) => {
    const h = (Math.max(v, 0) / wkMax) * 88;
    const cxb = M + slot * (i + 0.5);
    p.push(`<rect x="${cxb - bw / 2}" y="${baseY - h}" width="${bw}" height="${h}" rx="3" fill="${TEAL}"/>`);
    p.push(text(cxb, baseY - h - 8, 14, 800, INK, String(v), "middle"));
    p.push(text(cxb, baseY + 20, 11, 500, MUTED, `W${i + 1}`, "middle"));
  });
  y = baseY + 42;

  // ---- footer ----
  p.push(`<line x1="${M}" y1="${y}" x2="${W - M}" y2="${y}" stroke="${LINE}"/>`);
  y += 24;
  p.push(text(M, y, 11, 500, MUTED, "Semua angka = per hari (tanggal report) · auto dari sistem"));
  p.push(`<text x="${W - M}" y="${y}" text-anchor="end" font-size="11" font-weight="600" fill="${MUTED}">Java Sales Hub · ${wibClock()}</text>`);
  const H = y + 28;

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" font-family="Inter, Arial, sans-serif">
  <rect width="${W}" height="${H}" fill="#ffffff"/>
  <rect width="${W}" height="96" fill="${MAIN}"/>
  <text x="${M}" y="48" font-size="24" font-weight="800" fill="#ffffff" letter-spacing="0.3">DAILY SALES REPORT</text>
  <text x="${M}" y="72" font-size="14" fill="#fff6ea">Divisi Sales · ${esc(dateLabel)}${d.isArchive ? " · arsip" : ""}</text>
  <text x="${W - M}" y="48" text-anchor="end" font-size="13" font-weight="600" fill="#fff6ea">Java Sales Hub</text>
  ${p.join("")}
 </svg>`;
}

// Simple monthly/yearly bar chart SVG (760×380).
export function buildBarSVG(data: { b: string; net: number }[], title: string): string {
  const max = Math.max(...data.map((d) => d.net), 1);
  const cw = Math.min(88, 560 / Math.max(data.length, 1));
  const gap = (680 - cw * data.length) / (data.length + 1);
  const bars = data
    .map((d, i) => {
      const h = (d.net / max) * 220;
      const x = 40 + gap + (cw + gap) * i;
      return `<rect x="${x}" y="${300 - h}" width="${cw}" height="${h}" rx="6" fill="${TEAL}"/><text x="${x + cw / 2}" y="${292 - h}" text-anchor="middle" font-size="15" font-weight="700" fill="#1d2433">${d.net}</text><text x="${x + cw / 2}" y="326" text-anchor="middle" font-size="13" fill="#6b7280">${esc(d.b)}</text>`;
    })
    .join("");
  return `<svg xmlns="http://www.w3.org/2000/svg" width="760" height="380" viewBox="0 0 760 380" font-family="Inter, Arial, sans-serif"><rect width="760" height="380" fill="#fff"/><text x="40" y="42" font-size="19" font-weight="800" fill="#41505f">${esc(title)}</text><line x1="40" y1="300" x2="720" y2="300" stroke="#e5e7eb"/>${bars}<text x="720" y="360" text-anchor="end" font-size="12" fill="#9aa6bf">Java Sales Hub</text></svg>`;
}
