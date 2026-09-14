import { prisma } from "./prisma";
import { STAGES, STAGE_INDEX, BATAL_ALASAN_LABEL, CHANNEL_LABEL } from "./constants";
import { formatIdDateLong, idMonthShort, wibToday } from "./wib";

// A deal is "gagal" (lost) if it was cancelled (batal) OR it is a non-closing
// deal that has been stuck (no movement) for longer than STALE_DAYS.
export const STALE_DAYS = 30;

export type LostCategory = "BATAL" | "MANDEK";

export interface LostItem {
  id: string;
  nama: string;
  category: LostCategory;
  stageLabel: string;
  alasanKey: string; // enum key, or "MANDEK"
  alasanLabel: string;
  alasanRaw: string | null;
  cluster: string;
  type: string;
  channel: string;
  caraBayar: string;
  sales: string;
  koor: string;
  bendera: string;
  lastActivity: string; // label
  lastActivityTs: number;
  appeal: string;
  // Tier-2 demographic (from external system; null until synced)
  ageBand: string | null;
  pekerjaan: string | null;
  domisili: string | null;
  penghasilan: string | null;
}

export interface Slice {
  label: string;
  n: number;
  pct: number;
}

export interface LostAnalysis {
  total: number;
  batal: number;
  mandek: number;
  topReason: string;
  byAlasan: Slice[];
  byStage: Slice[];
  byCluster: Slice[];
  byType: Slice[];
  byBendera: Slice[];
  byKoor: Slice[];
  byChannel: Slice[];
  byCaraBayar: Slice[];
  byMonth: Slice[];
  profil: string;
  items: LostItem[];
}

// Appeal strategy per cancellation reason (the "gimana biar follow-through").
const APPEAL: Record<string, string> = {
  REJECT_BANK:
    "Ditolak bank. Bantu perbaiki BI checking, tawarkan bank/skema KPR alternatif, atau naikkan DP untuk perkecil plafon.",
  KOL_5: "Kolektibilitas 5. Arahkan ke cash bertahap / in-house, atau dampingi pelunasan tunggakan sebelum re-apply.",
  KOL_2: "Kolektibilitas 2. Bersihkan dulu riwayat kredit, tawarkan skema pembayaran fleksibel saat re-apply.",
  BATAL_SEPIHAK:
    "Batal dari sisi user. Follow-up personal untuk gali keberatan asli, tawarkan unit/harga/promo alternatif, jaga relasi.",
  MASALAH_KELUARGA:
    "Faktor keluarga. Beri ruang & waktu, tawarkan skema co-buyer / joint income, jaga komunikasi hangat.",
  SKEMA_BAYAR: "Skema bayar tak cocok. Tawarkan promo DP ringan, cicilan bertahap, atau bunga fix di awal.",
  OTHER: "Alasan lain. Gali detail via follow-up, kelompokkan untuk penawaran ulang tertarget.",
  MANDEK: "Lead pasif lama. Jadwalkan follow-up ulang, konfirmasi minat & kebutuhan, tawarkan unit/info/promo baru.",
};

function slices(counts: Map<string, number>, total: number, top = 0): Slice[] {
  let arr = [...counts.entries()]
    .map(([label, n]) => ({ label, n, pct: total ? Math.round((n / total) * 100) : 0 }))
    .sort((a, b) => b.n - a.n);
  if (top) arr = arr.slice(0, top);
  return arr;
}

function bump(map: Map<string, number>, key: string) {
  map.set(key, (map.get(key) ?? 0) + 1);
}

const DEAL_SELECT = {
  id: true,
  nama: true,
  stage: true,
  channel: true,
  caraBayar: true,
  catatan: true,
  batalAlasan: true,
  batalTanggal: true,
  tanggalWi: true,
  tglNup: true,
  tglUtj: true,
  tglSpr: true,
  tglAcc: true,
  tglAkad: true,
  cluster: { select: { name: true } },
  unitType: { select: { name: true } },
  agency: { select: { name: true } },
  coordinator: { select: { name: true } },
  sales: { select: { name: true } },
  demographic: {
    select: { umur: true, ageBand: true, pekerjaan: true, domisili: true, penghasilan: true },
  },
} as const;

type DealRow = {
  id: string;
  nama: string;
  stage: keyof typeof STAGE_INDEX;
  channel: keyof typeof CHANNEL_LABEL;
  caraBayar: string | null;
  catatan: string | null;
  batalAlasan: string | null;
  batalTanggal: Date | null;
  tanggalWi: Date;
  tglNup: Date | null;
  tglUtj: Date | null;
  tglSpr: Date | null;
  tglAcc: Date | null;
  tglAkad: Date | null;
  cluster: { name: string } | null;
  unitType: { name: string } | null;
  agency: { name: string };
  coordinator: { name: string } | null;
  sales: { name: string };
  demographic: {
    umur: number | null;
    ageBand: string | null;
    pekerjaan: string | null;
    domisili: string | null;
    penghasilan: string | null;
  } | null;
};

// Derive an age band from raw age when the external system didn't supply one.
function ageBandOf(umur: number | null, band: string | null): string | null {
  if (band) return band;
  if (umur == null) return null;
  if (umur < 25) return "<25";
  if (umur < 35) return "25-34";
  if (umur < 45) return "35-44";
  if (umur < 55) return "45-54";
  return "55+";
}

function lastActivity(d: DealRow): Date {
  const cands = [d.tglAkad, d.tglAcc, d.tglSpr, d.tglUtj, d.tglNup, d.batalTanggal, d.tanggalWi].filter(
    Boolean,
  ) as Date[];
  return cands.reduce((a, b) => (a.getTime() > b.getTime() ? a : b), d.tanggalWi);
}

// Load every "lost" deal (batal + mandek) as normalized LostItems.
export async function loadLostItems(): Promise<LostItem[]> {
  const today = wibToday();
  const cutoff = new Date(today.getTime() - STALE_DAYS * 86400_000);

  const [batalDeals, stuckCandidates] = await Promise.all([
    prisma.deal.findMany({ where: { batal: true }, select: DEAL_SELECT }) as Promise<DealRow[]>,
    prisma.deal.findMany({
      where: { batal: false, stage: { not: "REALISASI" } },
      select: DEAL_SELECT,
    }) as Promise<DealRow[]>,
  ]);

  const items: LostItem[] = [];
  const toItem = (d: DealRow, category: LostCategory): LostItem => {
    const la = lastActivity(d);
    const alasanKey = category === "BATAL" ? d.batalAlasan ?? "OTHER" : "MANDEK";
    const alasanLabel =
      category === "BATAL" ? BATAL_ALASAN_LABEL[alasanKey] ?? alasanKey : "Pasif (lama tak gerak)";
    return {
      id: d.id,
      nama: d.nama,
      category,
      stageLabel: STAGES[STAGE_INDEX[d.stage]].label,
      alasanKey,
      alasanLabel,
      alasanRaw: d.catatan,
      cluster: d.cluster?.name ?? "-",
      type: d.unitType?.name ?? "-",
      channel: CHANNEL_LABEL[d.channel] ?? d.channel,
      caraBayar: d.caraBayar ?? "-",
      sales: d.sales.name,
      koor: d.coordinator?.name ?? "-",
      bendera: d.agency.name,
      lastActivity: formatIdDateLong(la),
      lastActivityTs: la.getTime(),
      appeal: APPEAL[alasanKey] ?? APPEAL.OTHER,
      ageBand: ageBandOf(d.demographic?.umur ?? null, d.demographic?.ageBand ?? null),
      pekerjaan: d.demographic?.pekerjaan ?? null,
      domisili: d.demographic?.domisili ?? null,
      penghasilan: d.demographic?.penghasilan ?? null,
    };
  };

  for (const d of batalDeals) items.push(toItem(d, "BATAL"));
  for (const d of stuckCandidates) {
    if (lastActivity(d).getTime() < cutoff.getTime()) items.push(toItem(d, "MANDEK"));
  }
  return items;
}

export async function getLostAnalysis(): Promise<LostAnalysis> {
  const items = await loadLostItems();
  const total = items.length;
  const batal = items.filter((i) => i.category === "BATAL").length;

  const mAlasan = new Map<string, number>();
  const mStage = new Map<string, number>();
  const mCluster = new Map<string, number>();
  const mType = new Map<string, number>();
  const mBendera = new Map<string, number>();
  const mKoor = new Map<string, number>();
  const mChannel = new Map<string, number>();
  const mBayar = new Map<string, number>();
  const mMonth = new Map<string, number>();

  for (const it of items) {
    bump(mAlasan, it.alasanLabel);
    bump(mStage, it.stageLabel);
    bump(mCluster, it.cluster);
    bump(mType, it.type);
    bump(mBendera, it.bendera);
    bump(mKoor, it.koor);
    bump(mChannel, it.channel);
    bump(mBayar, it.caraBayar);
  }
  // month trend of cancellations only (batal items; lastActivity ≈ batalTanggal)
  for (const it of items) {
    if (it.category !== "BATAL") continue;
    const t = new Date(it.lastActivityTs);
    bump(mMonth, `${idMonthShort(t.getUTCMonth())} ${String(t.getUTCFullYear()).slice(2)}`);
  }

  const byAlasan = slices(mAlasan, total);
  const byCluster = slices(mCluster, total);
  const topReason = byAlasan[0]?.label ?? "-";
  const topCluster = byCluster[0]?.label ?? "-";
  const byType = slices(mType, total, 8);
  const byChannel = slices(mChannel, total);
  const profil = `Mayoritas kegagalan ada di ${topCluster} (${byCluster[0]?.pct ?? 0}%), channel ${
    byChannel[0]?.label ?? "-"
  }, dengan sebab terbanyak "${topReason}". Fokuskan strategi appeal ke segmen ini.`;

  return {
    total,
    batal,
    mandek: total - batal,
    topReason,
    byAlasan,
    byStage: slices(mStage, total),
    byCluster,
    byType,
    byBendera: slices(mBendera, total, 8),
    byKoor: slices(mKoor, total),
    byChannel,
    byCaraBayar: slices(mBayar, total),
    byMonth: [...mMonth.entries()].map(([label, n]) => ({ label, n, pct: 0 })),
    profil,
    items: items.sort((a, b) => b.lastActivityTs - a.lastActivityTs),
  };
}

// ---------------------------------------------------------------------------
// Per-reason anonymized aggregate context for the AI slot. Contains only
// counts by dimension — NO customer names or personal data.
// ---------------------------------------------------------------------------
export interface ReasonContext {
  alasanKey: string;
  alasanLabel: string;
  total: number;
  isAll: boolean;
  byCluster: Slice[];
  byType: Slice[];
  byChannel: Slice[];
  byCaraBayar: Slice[];
  byBendera: Slice[];
  byStage: Slice[];
  byAlasan: Slice[]; // only meaningful for the ALL segment
  // Tier-2 demographic — empty arrays until the external pathway is connected.
  demo: {
    coverage: number; // how many of `total` have ANY demographic field
    byAge: Slice[];
    byJob: Slice[];
    byDomisili: Slice[];
    byIncome: Slice[];
  };
}

export const ALL_KEY = "ALL";

export async function getReasonContext(alasanKey: string): Promise<ReasonContext | null> {
  const all = await loadLostItems();
  const isAll = alasanKey === ALL_KEY;
  const items = isAll ? all : all.filter((i) => i.alasanKey === alasanKey);
  if (items.length === 0) return null;

  const mk = (pick: (i: LostItem) => string, top = 0) => {
    const m = new Map<string, number>();
    for (const i of items) bump(m, pick(i));
    return slices(m, items.length, top);
  };
  // demographic slices count only items that actually have the field
  const mkDemo = (pick: (i: LostItem) => string | null, top = 0) => {
    const present = items.filter((i) => pick(i));
    const m = new Map<string, number>();
    for (const i of present) bump(m, pick(i)!);
    return slices(m, present.length, top);
  };
  const coverage = items.filter(
    (i) => i.ageBand || i.pekerjaan || i.domisili || i.penghasilan,
  ).length;

  return {
    alasanKey,
    alasanLabel: isAll ? "Semua alasan" : items[0].alasanLabel,
    total: items.length,
    isAll,
    byCluster: mk((i) => i.cluster),
    byType: mk((i) => i.type, 6),
    byChannel: mk((i) => i.channel),
    byCaraBayar: mk((i) => i.caraBayar),
    byBendera: mk((i) => i.bendera, 6),
    byStage: mk((i) => i.stageLabel),
    byAlasan: isAll ? mk((i) => i.alasanLabel) : [],
    demo: {
      coverage,
      byAge: mkDemo((i) => i.ageBand),
      byJob: mkDemo((i) => i.pekerjaan, 6),
      byDomisili: mkDemo((i) => i.domisili, 6),
      byIncome: mkDemo((i) => i.penghasilan),
    },
  };
}

// ---------------------------------------------------------------------------
// Per-reason profile data for the interactive demographic visualization.
// One pass over the lost items builds the "Semua alasan" profile + one per
// reason (ordered by volume). Fed to the <LostProfile> client component.
// ---------------------------------------------------------------------------
export interface ProfileData {
  key: string;
  label: string;
  n: number;
  pct: number; // share of all lost
  byCluster: Slice[];
  byType: Slice[];
  byChannel: Slice[];
  byBendera: Slice[];
  byCaraBayar: Slice[];
  demo: { coverage: number; byAge: Slice[]; byJob: Slice[]; byDomisili: Slice[]; byIncome: Slice[] };
}

export async function getLostProfiles(): Promise<ProfileData[]> {
  const all = await loadLostItems();
  const total = all.length;

  const build = (key: string, label: string, items: LostItem[]): ProfileData => {
    const mk = (pick: (i: LostItem) => string, top = 0) => {
      const m = new Map<string, number>();
      for (const i of items) bump(m, pick(i));
      return slices(m, items.length, top);
    };
    const mkDemo = (pick: (i: LostItem) => string | null, top = 0) => {
      const present = items.filter((i) => pick(i));
      const m = new Map<string, number>();
      for (const i of present) bump(m, pick(i)!);
      return slices(m, present.length, top);
    };
    const coverage = items.filter(
      (i) => i.ageBand || i.pekerjaan || i.domisili || i.penghasilan,
    ).length;
    return {
      key,
      label,
      n: items.length,
      pct: total ? Math.round((items.length / total) * 100) : 0,
      byCluster: mk((i) => i.cluster),
      byType: mk((i) => i.type, 6),
      byChannel: mk((i) => i.channel),
      byBendera: mk((i) => i.bendera, 6),
      byCaraBayar: mk((i) => i.caraBayar),
      demo: {
        coverage,
        byAge: mkDemo((i) => i.ageBand),
        byJob: mkDemo((i) => i.pekerjaan, 6),
        byDomisili: mkDemo((i) => i.domisili, 6),
        byIncome: mkDemo((i) => i.penghasilan),
      },
    };
  };

  const byKey = new Map<string, { label: string; items: LostItem[] }>();
  for (const i of all) {
    const g = byKey.get(i.alasanKey) ?? { label: i.alasanLabel, items: [] };
    g.items.push(i);
    byKey.set(i.alasanKey, g);
  }
  const sorted = [...byKey.entries()].sort((a, b) => b[1].items.length - a[1].items.length);

  return [
    build(ALL_KEY, "Semua alasan", all),
    ...sorted.map(([key, g]) => build(key, g.label, g.items)),
  ];
}

// Stored 2-slot notes, keyed by alasanKey.
export async function getAppealNotes(): Promise<
  Map<string, { teamStrategy: string | null; aiText: string | null; aiGeneratedAt: Date | null }>
> {
  const rows = await prisma.appealNote.findMany();
  const map = new Map<string, { teamStrategy: string | null; aiText: string | null; aiGeneratedAt: Date | null }>();
  for (const r of rows) {
    map.set(r.alasanKey, {
      teamStrategy: r.teamStrategy,
      aiText: r.aiText,
      aiGeneratedAt: r.aiGeneratedAt,
    });
  }
  return map;
}

// Default team-strategy text (the old hardcoded APPEAL) — used as Slot A
// placeholder until a team writes their own.
export function defaultTeamStrategy(alasanKey: string): string {
  return APPEAL[alasanKey] ?? APPEAL.OTHER;
}
