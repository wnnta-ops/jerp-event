// Design tokens mirrored 1:1 from approver_app_prototype.html (:root CSS variables).
// Keep this the single source of truth for color/spacing so screens stay consistent.

import { Platform } from 'react-native';

// Top breathing room for screen headers. On a phone, SafeAreaView already inserts
// the notch/status-bar inset, so we only add a small gap below it. On web there is
// NO safe-area inset, so the header would otherwise hug the very top edge — give it
// a status-bar-sized gap so the browser demo doesn't look cornered.
export const headerTop = Platform.OS === 'web' ? 24 : 8;

// Shift a hex color toward white (amt > 0) or black (amt < 0). amt is -1..1.
function shade(hex: string, amt: number): string {
  const h = hex.replace('#', '');
  const n = parseInt(h.length === 3 ? h.replace(/(.)/g, '$1$1') : h, 16);
  const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
  const mix = (c: number) => Math.round(amt >= 0 ? c + (255 - c) * amt : c * (1 + amt));
  const to2 = (c: number) => Math.max(0, Math.min(255, c)).toString(16).padStart(2, '0');
  return `#${to2(mix(r))}${to2(mix(g))}${to2(mix(b))}`;
}

// Derive a diagonal gradient (lighter -> darker) from ANY base color, so the two
// stat cards stay vivid and update automatically if their base color changes.
export function gradient(base: string): [string, string] {
  return [shade(base, 0.18), shade(base, -0.28)];
}

export const colors = {
  ink: '#0F172A',
  sub: '#64748B',
  line: '#E2E8F0',
  bg: '#EEF2F7',

  blue: '#2563EB',
  blueDark: '#1E3A8A',
  blueBg: '#EAF3FF',
  purple: '#7C3AED',
  purpleBg: '#F3E8FF',

  green: '#16A34A',
  greenBg: '#E7F8EC',
  red: '#DC2626',
  redBg: '#FDECEC',
  amber: '#B45309',
  amberBg: '#FEF3C7',

  white: '#FFFFFF',
  slate100: '#F1F5F9',
  slate400: '#94A3B8',
  blue700: '#1D4ED8',
  blueChipBg: '#DBEAFE',
} as const;

// Tier -> color mapping (stripe/chip), matching .stripe / .chip in the prototype.
export type Tier = 't1' | 't2' | 't3';
export const tier = {
  t1: { label: 'T1', stripe: colors.blue, chipBg: colors.blueBg, chipFg: colors.blueDark },
  t2: { label: 'T2', stripe: colors.amber, chipBg: colors.amberBg, chipFg: colors.amber },
  t3: { label: 'T3', stripe: colors.red, chipBg: colors.redBg, chipFg: colors.red },
} as const;

// SLA pill color classes (.sla.red / .amb / .norm).
export type SlaClass = 'red' | 'amb' | 'norm';
export const sla = {
  red: { fg: colors.red, bg: colors.redBg },
  amb: { fg: colors.amber, bg: colors.amberBg },
  norm: { fg: colors.sub, bg: colors.slate100 },
} as const;

// Left color-code per request type (Memo / BPP / Kasbon / Kebutuhan Divisi / Others).
export const reqTypeColor: Record<string, string> = {
  Memo: '#2563EB', // blue
  BPP: '#7C3AED', // purple
  Kasbon: '#16A34A', // green
  'Kebutuhan Divisi': '#0D9488', // teal
  Others: '#64748B', // slate
};
export function typeColor(name?: string): string {
  return (name && reqTypeColor[name]) || reqTypeColor.Others;
}

// ── Javacore (MUK ERP) modules ─────────────────────────────────────────────────
// The approval inbox aggregates documents from many Javacore modules; each gets a
// stripe/legend color and a short label. Keyed by moduleKey (see types.ts).
export interface ModuleMeta {
  label: string;
  color: string;
}
export const modules: Record<string, ModuleMeta> = {
  PR: { label: 'Permintaan Pembelian', color: '#2563EB' }, // blue
  PO: { label: 'Order Pembelian', color: '#7C3AED' }, // purple
  SPK: { label: 'SPK / Work Order', color: '#0D9488' }, // teal
  RETENSI: { label: 'Pencairan Retensi', color: '#DB2777' }, // pink
  OPNAME: { label: 'Opname Prestasi', color: '#D97706' }, // orange
  MATERIAL: { label: 'Permintaan Material', color: '#16A34A' }, // green
  KONTRAK: { label: 'Kontrak Owner', color: '#0EA5E9' }, // sky
  Others: { label: 'Lainnya', color: '#64748B' }, // slate
};
export function moduleColor(key?: string): string {
  return (key && modules[key]?.color) || modules.Others.color;
}
// fallback = label sent by the backend for keys outside this table (e.g. J-ERP
// approval types like DISKON), so they don't all collapse into "Lainnya".
export function moduleLabel(key?: string, fallback?: string): string {
  return (key && modules[key]?.label) || fallback || modules.Others.label;
}

// Deadline urgency: <=3 days -> red, 4-7 days -> amber, else none (white).
export type Deadline = 'red' | 'amber' | 'none';
export const deadlineStyle = {
  red: { bg: '#FEF1F1', border: '#F4C4C1', chipBg: '#FDECEC', chipFg: '#DC2626', label: 'URGENT' },
  amber: { bg: '#FEFAEC', border: '#F1DCA0', chipBg: '#FEF3C7', chipFg: '#B45309', label: 'DUE SOON' },
  none: { bg: '#FFFFFF', border: '#E2E8F0', chipBg: '#EAF3FF', chipFg: '#1E3A8A', label: 'REGULER' },
} as const;

export const radius = {
  sm: 7,
  md: 11,
  lg: 14,
  xl: 19,
  pill: 999,
} as const;

export const space = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 22,
} as const;
