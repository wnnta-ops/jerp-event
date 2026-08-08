import type { DailyReportData } from "./derivations";
import { formatIdDateLong, parseDayKey, wibClock } from "./wib";

// Colors (orange header per §12.2 decision).
const MAIN = "#f0a94e";
const ACCENT = "#f58c8c";
const TEAL = "#4ec3c7";
const GREEN = "#5cc2a8";
const AMBER = "#f4b46b";
const BLUE = "#7db8e8";
const PURPLE = "#b19cd9";

function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

/**
 * Portrait 760×1080 infographic for the WhatsApp group. Ported from the
 * approved prototype's SVG, driven by DERIVED data (never re-typed numbers).
 */
export function buildDailyReportSVG(d: DailyReportData): string {
  const dateLabel = formatIdDateLong(parseDayKey(d.dayKey));

  const tiles = [
    ["WI", d.tally.wi, TEAL],
    ["NUP", d.tally.nup, BLUE],
    ["UTJ", d.tally.utj, GREEN],
    ["SPR", d.tally.spr, PURPLE],
    ["Batal", d.tally.batal, ACCENT],
  ] as const;
  const tw = 126.4;
  const tileSvg = tiles
    .map(([lab, val, col], i) => {
      const x = 40 + i * (tw + 12);
      return `<rect x="${x}" y="162" width="${tw}" height="86" rx="11" fill="#f7f9fb" stroke="#e5e7eb"/><text x="${x + tw / 2}" y="208" text-anchor="middle" font-size="34" font-weight="800" fill="${col}">${val}</text><text x="${x + tw / 2}" y="233" text-anchor="middle" font-size="13" font-weight="600" fill="#6b7280">${lab}</text>`;
    })
    .join("");

  // team bars (up to 2)
  const teams = d.teams.filter((t) => t.teamId).slice(0, 2);
  const teamBar = (y: number, t: { tim: string; net: number; target: number }) => {
    const p = t.target ? t.net / t.target : 0;
    const col = t.target && t.net >= t.target ? GREEN : AMBER;
    return `<text x="40" y="${y - 7}" font-size="14" font-weight="700" fill="#1d2433">${esc(t.tim)}</text><text x="720" y="${y - 7}" text-anchor="end" font-size="14" font-weight="700" fill="${col}">${t.net} / ${t.target}</text><rect x="40" y="${y}" width="680" height="15" rx="7" fill="#eef0f3"/><rect x="40" y="${y}" width="${Math.min(p, 1) * 680}" height="15" rx="7" fill="${col}"/>`;
  };
  const teamSvg = teams.map((t, i) => teamBar(465 + i * 57, t)).join("");

  // type bars (up to 5)
  const types = d.utjByType.slice(0, 5);
  const typeMax = Math.max(...types.map((t) => t.n), 1);
  const typeSvg = types
    .map((t, i) => {
      const y = 600 + i * 26;
      const w = (t.n / typeMax) * 430;
      return `<text x="40" y="${y + 11}" font-size="13" fill="#1d2433">${esc(t.type)}</text><rect x="200" y="${y}" width="430" height="15" rx="7" fill="#eef0f3"/><rect x="200" y="${y}" width="${w}" height="15" rx="7" fill="${TEAL}"/><text x="644" y="${y + 12}" text-anchor="end" font-size="13" font-weight="700" fill="#1d2433">${t.n}</text>`;
    })
    .join("");

  // weekly trend (W1..W5)
  const weekly = d.weekly;
  const wkMax = Math.max(...weekly.map((v) => Math.abs(v)), 1);
  const wkSvg = weekly
    .map((v, i) => {
      const h = (Math.max(v, 0) / wkMax) * 92;
      const x = 60 + i * 120;
      return `<rect x="${x}" y="${848 - h}" width="70" height="${h}" rx="6" fill="${TEAL}"/><text x="${x + 35}" y="${839 - h}" text-anchor="middle" font-size="16" font-weight="700" fill="#1d2433">${v}</text><text x="${x + 35}" y="870" text-anchor="middle" font-size="13" fill="#6b7280">W${i + 1}</text>`;
    })
    .join("");

  // sisa stock per cluster (up to 3)
  const clusters = d.clusterSisa.slice(0, 3);
  const sisaSvg = clusters
    .map((c, i) => {
      const x = 40 + i * 234;
      return `<rect x="${x}" y="916" width="212" height="72" rx="11" fill="#f7f9fb" stroke="#e5e7eb"/><text x="${x + 18}" y="953" font-size="30" font-weight="800" fill="${c.sisa > 0 ? TEAL : "#9aa6bf"}">${c.sisa}</text><text x="${x + 18}" y="976" font-size="13" fill="#6b7280">${esc(c.cluster)} · sisa</text>`;
    })
    .join("");

  const monthFill = Math.min(d.monthTarget ? d.monthNet / d.monthTarget : 0, 1) * 636;
  const annualFill = Math.min(d.annualTarget ? d.sprYtd / d.annualTarget : 0, 1) * 636;

  return `<svg xmlns="http://www.w3.org/2000/svg" width="760" height="1080" viewBox="0 0 760 1080" font-family="Inter, Arial, sans-serif">
  <rect width="760" height="1080" fill="#ffffff"/>
  <rect width="760" height="118" fill="${MAIN}"/><rect width="760" height="6" y="112" fill="${ACCENT}"/>
  <text x="40" y="52" font-size="27" font-weight="800" fill="#ffffff">DAILY REPORT SALES</text>
  <text x="40" y="84" font-size="17" fill="#fff6ea">Divisi Sales · ${esc(dateLabel)}${d.isArchive ? " · arsip" : ""}</text>
  <text x="720" y="52" text-anchor="end" font-size="15" fill="#fff6ea">Java Sales Hub</text>
  <text x="40" y="150" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">HARI INI</text>${tileSvg}
  <text x="40" y="284" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">PROGRESS TARGET</text>
  <rect x="40" y="298" width="680" height="106" rx="11" fill="#f7f9fb" stroke="#e5e7eb"/>
  <text x="62" y="326" font-size="14" fill="#1d2433">Target Bulan Ini: <tspan font-weight="700">${d.monthNet} / ${d.monthTarget}</tspan> unit (<tspan font-weight="700" fill="${GREEN}">${d.monthPct}%</tspan>)</text>
  <rect x="62" y="334" width="636" height="14" rx="7" fill="#eef0f3"/><rect x="62" y="334" width="${monthFill}" height="14" rx="7" fill="${GREEN}"/>
  <text x="62" y="378" font-size="14" fill="#1d2433">Target 2026: <tspan font-weight="700">${d.sprYtd} / ${d.annualTarget}</tspan> SPR (<tspan font-weight="700" fill="${TEAL}">${d.annualPct}%</tspan>)</text>
  <rect x="62" y="386" width="636" height="14" rx="7" fill="#eef0f3"/><rect x="62" y="386" width="${annualFill}" height="14" rx="7" fill="${TEAL}"/>
  <text x="40" y="438" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">UTJ NET PER TIM</text>
  ${teamSvg || `<text x="40" y="470" font-size="13" fill="#9aa6bf">Belum ada pembagian tim.</text>`}
  <text x="40" y="578" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">UTJ PER TYPE (BULAN)</text>${typeSvg}
  <text x="40" y="730" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">TREND UTJ MINGGUAN (NET)</text>
  <line x1="40" y1="848" x2="720" y2="848" stroke="#e5e7eb"/>${wkSvg}
  <text x="40" y="906" font-size="14" font-weight="700" fill="#6b7280" letter-spacing="1">SISA STOCK</text>${sisaSvg}
  <line x1="40" y1="1018" x2="720" y2="1018" stroke="#e5e7eb"/>
  <text x="40" y="1046" font-size="13" fill="#1d2433">Hold: <tspan font-weight="700">${d.hold}</tspan> · Waiting List: <tspan font-weight="700">${d.waitingList}</tspan> · Sales Potensial: <tspan font-weight="700">${d.salesPotensial}</tspan></text>
  <text x="720" y="1046" text-anchor="end" font-size="13" fill="#9aa6bf">Auto · Java Sales Hub · ${wibClock()}</text>
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
