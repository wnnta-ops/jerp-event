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
  MANDEK: "Lead mandek lama. Jadwalkan follow-up ulang, konfirmasi minat & kebutuhan, tawarkan unit/info/promo baru.",
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
};

function lastActivity(d: DealRow): Date {
  const cands = [d.tglAkad, d.tglAcc, d.tglSpr, d.tglUtj, d.tglNup, d.batalTanggal, d.tanggalWi].filter(
    Boolean,
  ) as Date[];
  return cands.reduce((a, b) => (a.getTime() > b.getTime() ? a : b), d.tanggalWi);
}

export async function getLostAnalysis(): Promise<LostAnalysis> {
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
    const alasanLabel = category === "BATAL" ? BATAL_ALASAN_LABEL[alasanKey] ?? alasanKey : "Mandek (lama tak gerak)";
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
    };
  };

  for (const d of batalDeals) items.push(toItem(d, "BATAL"));
  for (const d of stuckCandidates) {
    if (lastActivity(d).getTime() < cutoff.getTime()) items.push(toItem(d, "MANDEK"));
  }

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
  // month trend of cancellations only
  for (const d of batalDeals) {
    const t = d.batalTanggal ?? d.tanggalWi;
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
