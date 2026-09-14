import type { DailyReportData } from "./derivations";
import { formatIdDateLong, parseDayKey, wibClock } from "./wib";

// Colors (orange header per §12.2 decision).
const MAIN = "#f0a94e";
const ACCENT = "#f58c8c";
const TEAL = "#4ec3c7";
const GREEN = "#5cc2a8";
const AMBER = "#f4b46b";

function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/**
 * Portrait infographic for the WhatsApp group — a bullet recap driven by
 * DERIVED data (never re-typed). Height grows with content. "Total" figures
 * are month-to-date through the report day.
 */
export function buildDailyReportSVG(d: DailyReportData): string {
  const dateLabel = formatIdDateLong(parseDayKey(d.dayKey));
  const r = d.recap;
  const p: string[] = [];
  const LX = 40;
  const IX = 66;
  let y = 152;

  const text = (
    x: number,
    yy: number,
    size: number,
    weight: number,
    fill: string,
    t: string,
    anchor = "start",
  ) =>
    `<text x="${x}" y="${yy}" font-size="${size}" font-weight="${weight}" fill="${fill}" text-anchor="${anchor}">${esc(t)}</text>`;

  const secLabel = (t: string) => {
    p.push(`<text x="${LX}" y="${y}" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">${esc(t)}</text>`);
    y += 28;
  };
  const bullet = (t: string, bold = false) => {
    p.push(`<circle cx="${LX + 4}" cy="${y - 5}" r="3.2" fill="${MAIN}"/>`);
    p.push(text(LX + 16, y, 16, bold ? 700 : 600, "#1d2433", t));
    y += 27;
  };
  const sub = (t: string) => {
    p.push(text(IX, y, 15, 400, "#41505f", t));
    y += 24;
  };

  // ---- Rekap harian (bullets) ----
  secLabel("REKAP HARIAN");
  bullet("WI Hari ini :", true);
  if (r.wiByCluster.length === 0) sub("- belum ada WI hari ini");
  else
    for (const c of r.wiByCluster)
      sub(`- ${c.cluster} : ${c.total} (WI Murni ${c.murni}, WI Janjian ${c.janjian})`);
  y += 4;

  const utjTodayStr = r.utjToday.length
    ? r.utjToday.map((u) => `${u.code} (${u.n})`).join(", ")
    : "-";
  bullet(`UTJ Nett Total ${r.utjNett}, UTJ hari ini : ${utjTodayStr}`);
  bullet(`UTJ Batal ${r.utjBatal}`);
  bullet(`Waiting List Total ${r.waitingList}`);
  bullet(`Hold Unit Total ${r.holdUnit}`);
  bullet(`SPR Nett Total ${r.sprNett}`);
  bullet(`SPR Batal Total ${r.sprBatal}`);
  bullet(`NUP Total ${r.nupTotal}`);
  y += 16;

  // ---- Progress target ----
  secLabel("PROGRESS TARGET");
  const boxY = y;
  p.push(`<rect x="40" y="${boxY}" width="680" height="106" rx="11" fill="#f7f9fb" stroke="#e5e7eb"/>`);
  const monthFill = Math.min(d.monthTarget ? d.monthNet / d.monthTarget : 0, 1) * 636;
  const annualFill = Math.min(d.annualTarget ? d.sprYtd / d.annualTarget : 0, 1) * 636;
  p.push(`<text x="62" y="${boxY + 28}" font-size="14" fill="#1d2433">Target Bulan Ini: <tspan font-weight="700">${d.monthNet} / ${d.monthTarget}</tspan> unit (<tspan font-weight="700" fill="${GREEN}">${d.monthPct}%</tspan>)</text>`);
  p.push(`<rect x="62" y="${boxY + 36}" width="636" height="14" rx="7" fill="#eef0f3"/><rect x="62" y="${boxY + 36}" width="${monthFill}" height="14" rx="7" fill="${GREEN}"/>`);
  p.push(`<text x="62" y="${boxY + 80}" font-size="14" fill="#1d2433">Target 2026: <tspan font-weight="700">${d.sprYtd} / ${d.annualTarget}</tspan> SPR (<tspan font-weight="700" fill="${TEAL}">${d.annualPct}%</tspan>)</text>`);
  p.push(`<rect x="62" y="${boxY + 88}" width="636" height="14" rx="7" fill="#eef0f3"/><rect x="62" y="${boxY + 88}" width="${annualFill}" height="14" rx="7" fill="${TEAL}"/>`);
  y = boxY + 106 + 34;

  // ---- UTJ net per tim ----
  const teams = d.teams.filter((t) => t.teamId).slice(0, 2);
  secLabel("UTJ NET PER TIM");
  if (teams.length === 0) {
    p.push(text(LX, y, 13, 400, "#9aa6bf", "Belum ada pembagian tim."));
    y += 26;
  } else {
    for (const t of teams) {
      const pr = t.target ? t.net / t.target : 0;
      const col = t.target && t.net >= t.target ? GREEN : AMBER;
      p.push(text(LX, y, 14, 700, "#1d2433", t.tim));
      p.push(text(720, y, 14, 700, col, `${t.net} / ${t.target}`, "end"));
      y += 9;
      p.push(`<rect x="40" y="${y}" width="680" height="15" rx="7" fill="#eef0f3"/><rect x="40" y="${y}" width="${Math.min(pr, 1) * 680}" height="15" rx="7" fill="${col}"/>`);
      y += 40;
    }
  }
  y += 8;

  // ---- Weekly trend ----
  secLabel("TREND UTJ MINGGUAN (NET)");
  const baseY = y + 100;
  const weekly = d.weekly;
  const wkMax = Math.max(...weekly.map((v) => Math.abs(v)), 1);
  p.push(`<line x1="40" y1="${baseY}" x2="720" y2="${baseY}" stroke="#e5e7eb"/>`);
  weekly.forEach((v, i) => {
    const h = (Math.max(v, 0) / wkMax) * 92;
    const x = 60 + i * 120;
    p.push(`<rect x="${x}" y="${baseY - h}" width="70" height="${h}" rx="6" fill="${TEAL}"/>`);
    p.push(text(x + 35, baseY - h - 9, 16, 700, "#1d2433", String(v), "middle"));
    p.push(text(x + 35, baseY + 22, 13, 400, "#6b7280", `W${i + 1}`, "middle"));
  });
  y = baseY + 48;

  // ---- Footer ----
  p.push(`<line x1="40" y1="${y}" x2="720" y2="${y}" stroke="#e5e7eb"/>`);
  y += 26;
  p.push(text(LX, y, 12.5, 400, "#9aa6bf", "Total = bulan ini s/d tanggal report · angka auto dari sistem"));
  p.push(`<text x="720" y="${y}" text-anchor="end" font-size="12.5" fill="#9aa6bf">Java Sales Hub · ${wibClock()}</text>`);
  const H = y + 34;

  return `<svg xmlns="http://www.w3.org/2000/svg" width="760" height="${H}" viewBox="0 0 760 ${H}" font-family="Inter, Arial, sans-serif">
  <rect width="760" height="${H}" fill="#ffffff"/>
  <rect width="760" height="118" fill="${MAIN}"/><rect width="760" height="6" y="112" fill="${ACCENT}"/>
  <text x="40" y="52" font-size="27" font-weight="800" fill="#ffffff">DAILY REPORT SALES</text>
  <text x="40" y="84" font-size="17" fill="#fff6ea">Divisi Sales · ${esc(dateLabel)}${d.isArchive ? " · arsip" : ""}</text>
  <text x="720" y="52" text-anchor="end" font-size="15" fill="#fff6ea">Java Sales Hub</text>
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
