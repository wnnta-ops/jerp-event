// Design tokens mirrored 1:1 from approver_app_prototype.html (:root CSS variables).
// Keep this the single source of truth for color/spacing so screens stay consistent.

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
