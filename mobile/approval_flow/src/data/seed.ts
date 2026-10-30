// Seed data transcribed 1:1 from the SEED structure in approver_app_prototype.html.
// This is mock-only and lives behind mockService — the UI never imports it directly.

import type { RequestItem, RoleProfile, RoleKey, HistoryEntry, PipeStep, SupportingDoc, BppSigner } from './types';

// Helper to build a pipeline step from the prototype's [title, detail, state] tuples.
const step = (title: string, detail: string, state: PipeStep['state']): PipeStep => ({
  title,
  detail,
  state,
});

interface RoleSeed extends RoleProfile {
  needs: RequestItem[];
  inchain: RequestItem[];
}

// ── Funding flows ──────────────────────────────────────────────────────────────
// Three funding request types we want to test, each routed through a chain:
//   Memo   (> 1jt):  Requester → Reviewer → GM → Director → Owner
//   Kasbon (< 1jt):  Requester → Reviewer → GM → Director
//   AP/BPP (< 1jt):  Requester → Reviewer → GM → Director
// Each role sees the request when it's their turn; the description is
// "<name> · <current step (you)> · → <next in line>".

type FlowRole = 'Reviewer' | 'GM' | 'Director' | 'Owner1' | 'Owner2';
const ROLE_KEY: Record<FlowRole, RoleKey> = { Reviewer: 'Reviewer', GM: 'GM', Director: 'Director', Owner1: 'Owner1', Owner2: 'Owner2' };
// Display label per role (chain titles, descriptions). Owners show their names.
const LABEL: Record<FlowRole, string> = { Reviewer: 'Reviewer', GM: 'GM', Director: 'Director', Owner1: 'M. Winarto', Owner2: 'Boy Arifin' };

interface FlowSpec {
  id: string;
  name: string; // Memo / Kasbon / AP
  amount: string;
  tier: RequestItem['tier'];
  slaMin: number;
  slc: RequestItem['slc'];
  requester: string;
  dept: string;
  purpose: string;
  desc: string;
  budget: string;
  need: string;
  docs: SupportingDoc[];
  approvers: FlowRole[]; // sequential approvers after Submitted
  owners?: FlowRole[]; // parallel final owners (sign independently, no waiting)
}

const FLOWS: FlowSpec[] = [
  {
    id: 'memo', name: 'Memo', amount: 'Rp 12,000,000', tier: 't3', slaMin: 102, slc: 'red',
    requester: 'Andi Pratama', dept: 'Marketing', purpose: 'Pembayaran vendor — expo booth',
    desc: 'Uang muka ke vendor untuk pembuatan booth pameran produk bulan Juni.',
    budget: 'Marketing / Events', need: 'Fri, 19 Jun',
    docs: [
      { name: 'Penawaran_vendor.pdf', type: 'pdf', size: '248 KB' },
      { name: 'Invoice_DP.pdf', type: 'pdf', size: '112 KB' },
      { name: 'Desain_booth.jpg', type: 'img', size: '1.4 MB' },
    ],
    approvers: ['Reviewer', 'GM', 'Director'],
    owners: ['Owner1', 'Owner2'],
  },
  {
    id: 'kasbon', name: 'Kasbon', amount: 'Rp 750,000', tier: 't2', slaMin: 1500, slc: 'amb',
    requester: 'Dewi Lestari', dept: 'Operations', purpose: 'Kasbon operasional harian',
    desc: 'Dana talangan operasional harian tim yang akan dipertanggungjawabkan.',
    budget: 'Ops / Petty cash', need: 'Besok',
    docs: [{ name: 'Form_kasbon.pdf', type: 'pdf', size: '92 KB' }],
    approvers: ['Reviewer', 'GM', 'Director'],
  },
  {
    id: 'bpp', name: 'BPP', amount: 'Rp 950,000', tier: 't1', slaMin: 1320, slc: 'norm',
    requester: 'Rina Sari', dept: 'Creative', purpose: 'Reimburse materi cetak',
    desc: 'Penggantian biaya cetak materi promosi yang sudah dikeluarkan tim.',
    budget: 'Creative / Supplies', need: 'Minggu ini',
    docs: [{ name: 'Invoice_BPP.pdf', type: 'pdf', size: '120 KB' }],
    approvers: ['Reviewer', 'GM', 'Director'],
  },
];

// Urgency label for the chip slot (kept separate from the request-type name).
function urgencyTag(tier: RequestItem['tier']): string {
  return tier === 't3' ? 'URGENT' : tier === 't2' ? 'DUE SOON' : 'REGULER';
}

// Build the request as a given role sees it (chain advanced to that role's step).
// Owners (when present) approve in PARALLEL after the Director — each sees the
// request at the same time and signs independently.
function buildFlowInstance(spec: FlowSpec, viewer: FlowRole): RequestItem {
  const seq = spec.approvers;
  const owners = spec.owners ?? [];
  const isOwnerViewer = owners.includes(viewer);
  const vSeqIdx = seq.indexOf(viewer);

  const chain: PipeStep[] = [step('Submitted', `${spec.requester} · 08:55`, 'done')];

  // Sequential approvers (Reviewer → GM → Director).
  seq.forEach((a, i) => {
    const passed = isOwnerViewer || i < vSeqIdx;
    if (passed) {
      chain.push(
        a === 'Reviewer'
          ? step('Dokumen direview', 'Reviewer · dokumen sesuai standar', 'done')
          : step(LABEL[a], 'Sudah setuju', 'done'),
      );
    } else if (i === vSeqIdx) {
      chain.push(
        a === 'Reviewer'
          ? step('Reviewer — You', 'Cek dokumen sebelum lanjut', 'review')
          : step(`${LABEL[a]} — You`, 'Giliran anda', 'now'),
      );
    } else {
      chain.push(step(LABEL[a], a === 'Reviewer' ? 'Review dokumen' : 'Approver', 'wait'));
    }
  });

  // Parallel owners.
  owners.forEach((o) => {
    if (isOwnerViewer) {
      chain.push(
        o === viewer
          ? step(`${LABEL[o]} — You`, 'Giliran anda · paralel', 'now')
          : step(LABEL[o], 'Paralel · menunggu ttd', 'now'),
      );
    } else {
      chain.push(step(LABEL[o], 'Owner · paralel', 'wait'));
    }
  });

  chain.push(step('Dana cair', 'Sinkron ke sistem internal', 'wait'));

  // Description + next-in-line.
  let stepLabel: string;
  let nextLabel: string;
  let forward: string | null;
  if (isOwnerViewer) {
    stepLabel = 'Owner';
    nextLabel = 'final';
    forward = null;
  } else {
    stepLabel = LABEL[viewer];
    const nextSeq = seq[vSeqIdx + 1] as FlowRole | undefined;
    if (nextSeq) {
      nextLabel = LABEL[nextSeq];
      forward = LABEL[nextSeq];
    } else if (owners.length) {
      nextLabel = 'Owners (paralel)';
      forward = 'Owners';
    } else {
      nextLabel = 'final';
      forward = null;
    }
  }

  return {
    id: `${spec.id}-${ROLE_KEY[viewer]}`,
    type: 'FUND',
    reqName: spec.name,
    tier: spec.tier,
    tag: urgencyTag(spec.tier),
    amount: spec.amount,
    slaMin: spec.slaMin,
    slc: spec.slc,
    requester: spec.requester,
    dept: spec.dept,
    sub: `${spec.name} · ${stepLabel} (anda) · → ${nextLabel}`,
    purpose: spec.purpose,
    desc: spec.desc,
    budget: spec.budget,
    need: spec.need,
    forward,
    chain,
    docs: spec.docs.map((d) => ({ ...d })),
  };
}

// All flow instances awaiting a given role (sequential approver or parallel owner).
function flowsFor(role: FlowRole): RequestItem[] {
  return FLOWS.filter((f) => f.approvers.includes(role) || (f.owners ?? []).includes(role)).map((f) =>
    buildFlowInstance(f, role),
  );
}

// ── Demo volume: ~40 mixed requests to test the list, type color codes, and
// deadline coloring. All GM-pending. (Descriptions get richer in the next step.)
const REQ_TYPES = ['Memo', 'BPP', 'Kasbon', 'Kebutuhan Divisi', 'Others'];
const PEOPLE: [string, string][] = [
  ['Andi Pratama', 'Marketing'], ['Dewi Lestari', 'Operations'], ['Rina Sari', 'Creative'],
  ['Sari Wijaya', 'Finance'], ['Budi Santoso', 'IT'], ['Maya Putri', 'Sales'],
  ['Rian Hakim', 'HR'], ['Tono Wibowo', 'Logistics'],
];
const DAY_OPTS = [1, 2, 3, 5, 6, 7, 10, 14, 4, 20]; // mix of red (<=3) / amber (4-7) / white (>7)
const AMOUNTS = [12_000_000, 750_000, 3_200_000, 9_500_000, 1_500_000, 4_800_000, 650_000, 2_750_000, 18_000_000, 920_000];

// Purpose (tujuan dokumen) per request type — what the document is for.
const PURPOSES: Record<string, string[]> = {
  Memo: [
    'Pembayaran DP vendor booth expo',
    'Pelunasan jasa desain kampanye',
    'Pembayaran sewa venue seminar',
    'DP produksi merchandise event',
  ],
  BPP: [
    'Reimburse biaya cetak materi promosi',
    'Penggantian biaya operasional lapangan',
    'Pembayaran tagihan utilitas kantor',
    'Reimburse transport dinas',
  ],
  Kasbon: [
    'Kasbon operasional harian tim',
    'Kasbon perjalanan dinas luar kota',
    'Kasbon konsumsi rapat klien',
    'Kasbon dana talangan vendor',
  ],
  'Kebutuhan Divisi': [
    'Pengadaan ATK & supplies divisi',
    'Pembelian lisensi software tahunan',
    'Maintenance perangkat kerja',
    'Pengadaan seragam tim',
  ],
  Others: [
    'Pembayaran lain-lain operasional',
    'Permohonan dana kegiatan internal',
    'Penggantian biaya tak terduga',
    'Pembayaran iuran keanggotaan',
  ],
};

// One-line description (penjelasan tujuan dokumen) parallel to each purpose.
const DESCRIPTIONS: Record<string, string[]> = {
  Memo: [
    'Uang muka ke vendor untuk pembuatan booth pameran produk.',
    'Pelunasan biaya jasa desain materi kampanye digital kuartal ini.',
    'Pembayaran sewa venue untuk pelaksanaan seminar tahunan.',
    'Uang muka produksi merchandise sebagai gimmick acara.',
  ],
  BPP: [
    'Penggantian biaya cetak materi promosi yang sudah dikeluarkan tim.',
    'Penggantian biaya operasional lapangan selama kegiatan berlangsung.',
    'Pembayaran tagihan listrik & internet kantor bulan berjalan.',
    'Reimburse biaya transport dinas karyawan ke lokasi klien.',
  ],
  Kasbon: [
    'Dana talangan operasional harian tim yang akan dipertanggungjawabkan.',
    'Kasbon biaya perjalanan dinas luar kota termasuk akomodasi.',
    'Kasbon untuk konsumsi rapat dengan klien.',
    'Dana talangan pembayaran vendor yang jatuh tempo.',
  ],
  'Kebutuhan Divisi': [
    'Pengadaan alat tulis & perlengkapan kerja divisi untuk satu kuartal.',
    'Pembelian lisensi software tahunan yang dipakai tim.',
    'Biaya maintenance perangkat kerja yang bermasalah.',
    'Pengadaan seragam kerja baru untuk anggota tim.',
  ],
  Others: [
    'Pembayaran kebutuhan operasional lain di luar kategori standar.',
    'Permohonan dana untuk kegiatan internal perusahaan.',
    'Penggantian biaya tak terduga yang sudah dikeluarkan.',
    'Pembayaran iuran keanggotaan asosiasi industri.',
  ],
};

function rupiah(n: number): string {
  return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function demoRequests(n: number): RequestItem[] {
  const out: RequestItem[] = [];
  for (let i = 0; i < n; i++) {
    const name = REQ_TYPES[i % REQ_TYPES.length];
    const [requester, dept] = PEOPLE[i % PEOPLE.length];
    const days = DAY_OPTS[i % DAY_OPTS.length];
    const pool = PURPOSES[name];
    const purpose = pool[i % pool.length];
    const desc = DESCRIPTIONS[name][i % DESCRIPTIONS[name].length];
    out.push({
      id: `demo-${i}`,
      type: 'FUND',
      reqName: name,
      tier: 't1',
      tag: 'REGULER',
      amount: rupiah(AMOUNTS[i % AMOUNTS.length] + i * 25_000),
      slaMin: days * 1440,
      slc: 'norm',
      requester,
      dept,
      sub: 'GM (anda) · → Director',
      purpose,
      desc,
      budget: `${dept} / Umum`,
      need: `H-${days}`,
      forward: 'Director',
      chain: [
        step('Submitted', `${requester} · 08:55`, 'done'),
        step('Dokumen direview', 'Reviewer · dokumen sesuai standar', 'done'),
        step('GM — You', 'Giliran anda', 'now'),
        step('Director', 'Approver', 'wait'),
        step('Dana cair', 'Sinkron ke sistem internal', 'wait'),
      ],
      docs:
        i % 3 === 0
          ? [{ name: 'Lampiran.pdf', type: 'pdf', size: '120 KB' }]
          : [
              { name: 'Penawaran.pdf', type: 'pdf', size: '248 KB' },
              { name: 'Invoice.pdf', type: 'pdf', size: '96 KB' },
            ],
    });
  }
  return out;
}

// ── Javacore (MUK ERP) approval inbox ──────────────────────────────────────────
// Documents pulled from Javacore across its modules (Permintaan Pembelian, Order
// Pembelian, SPK, Pencairan Retensi, Opname, Material). Shapes mirror what the
// real Inbox Approval / document detail / Riwayat expose, so the API swap is 1:1.

import type { JavaLineItem, RiwayatEntry } from './types';
import { modules as MODULE_META } from '../theme/tokens';

const JC_PROJECTS = [
  'INFRASTRUKTUR CLUSTER 5 TAHAP 1',
  'PROYEK HOUSING CLUSTER 3 TAHAP 4 BATCH 2',
  'PROYEK HOUSING CLUSTER 5 TAHAP 2',
  'PROYEK HOUSING CLUSTER 3 TAHAP 3 BATCH I',
  'SITE INSTRUCTION CLUSTER 5 NEW',
  'PERSIAPAN INFRA CLUSTER 5 EXTN',
  'MUSHOLLA CLUSTER 3',
  'SI BALLROOM GUDANG CENTRAL',
];
const JC_REQUESTERS = ['ANAM', 'ACHMAD FAUZI', 'INDRA', 'DARYANTO', 'RANGGA P.', 'YUSUF', 'FIRMAN', 'BAGUS'];
const JC_APPROVERS = ['HELMI', 'DARYANTO', 'BUDIONO']; // names recorded on already-approved levels
const JC_AGO = ['1 hari lalu', '19 jam lalu', '18 jam lalu', '16 jam lalu', '2 minggu lalu', '3 minggu lalu', '4 minggu lalu'];

// Per-module definition: numerator prefix, typical sub-type, and sample items.
interface ModuleDef {
  key: string;
  prefix: string; // numerator prefix
  jenis: string[];
  items: [string, string, string, string][]; // [barang, uraian, bq, satuan]
}
const JC_MODULES: ModuleDef[] = [
  {
    key: 'PR', prefix: 'PR', jenis: ['Material', 'Alat Kerja', 'Aset', 'Biaya Proyek'],
    items: [
      ['AK01', 'FLOOR EMBOSSER', 'PEKERJAAN BETONISASI & ASPAL ENTRANCE', 'PCS'],
      ['MT12', 'BESI BETON D10 ULIR', 'PEKERJAAN STRUKTUR KOLOM', 'BTG'],
      ['MT08', 'SEMEN PCC 50KG', 'PEKERJAAN PASANGAN DINDING', 'SAK'],
    ],
  },
  {
    key: 'PO', prefix: 'PO', jenis: ['Material'],
    items: [
      ['MT21', 'KERAMIK 60x60 GRANIT', 'PEKERJAAN FINISHING LANTAI', 'DUS'],
      ['MT05', 'CAT WEATHERSHIELD 20L', 'PEKERJAAN PENGECATAN EKSTERIOR', 'PAIL'],
    ],
  },
  {
    key: 'SPK', prefix: 'SPK', jenis: ['Jasa Subkon'],
    items: [
      ['JS01', 'PEKERJAAN PEMBESIAN & BEKISTING', 'STRUKTUR LANTAI 2', 'LS'],
      ['JS04', 'PEKERJAAN WATERPROOFING', 'AREA TOILET & BALKON', 'M2'],
    ],
  },
  {
    key: 'RETENSI', prefix: 'RET', jenis: ['Retensi Subkon'],
    items: [['RT01', 'PENCAIRAN RETENSI TERMIN AKHIR', 'KONTRAK SUBKON STRUKTUR', 'LS']],
  },
  {
    key: 'OPNAME', prefix: 'OP', jenis: ['Opname Prestasi'],
    items: [['OP01', 'OPNAME PRESTASI SUBKON TERMIN 3', 'PROGRES 72% STRUKTUR', 'LS']],
  },
  {
    key: 'MATERIAL', prefix: 'PM', jenis: ['Material'],
    items: [
      ['MT33', 'PIPA PVC 4" AW', 'INSTALASI PLUMBING', 'BTG'],
      ['MT41', 'KABEL NYM 3x2.5', 'INSTALASI ELEKTRIKAL', 'ROLL'],
    ],
  },
];

const JC_DAYS = [1, 2, 3, 5, 6, 7, 10, 14, 2, 4]; // deadline spread for color coding
const JC_VALUES = [8_650_000, 12_400_000, 3_250_000, 46_800_000, 9_575_000, null, 1_600_000, null, 22_300_000, 4_925_000];

function pad4(n: number): string {
  return n.toString().padStart(4, '0');
}

// Build the Riwayat activity log + matching pipeline chain for a document sitting
// at the GM's turn: `level` levels already approved, GM pending, then the rest.
function buildRiwayatChain(docNo: string, moduleLabel: string, requester: string, level: number, total: number, overPagu: boolean, date: string) {
  const riwayat: RiwayatEntry[] = [];
  // Newest first (Javacore shows latest activity at the top).
  for (let i = level; i >= 1; i--) {
    const hh = String(7 + i).padStart(2, '0');
    const mm = String(10 + i * 3).padStart(2, '0');
    riwayat.push({
      waktu: `${date} ${hh}:${mm}`,
      aksi: 'Approve',
      keterangan: `Approval level tercatat ${docNo} — menunggu level ${i + 1}/${total}`,
      pengguna: JC_APPROVERS[(i - 1) % JC_APPROVERS.length],
    });
  }
  riwayat.push({ waktu: `${date} 01:11`, aksi: 'Submit', keterangan: `Ajukan ${moduleLabel} ${docNo} — Status: draft → submitted`, pengguna: requester });
  riwayat.push({ waktu: `${date} 01:11`, aksi: 'Tambah', keterangan: `Tambah ${moduleLabel} ${docNo}${overPagu ? ' [MELEBIHI PAGU/NON-RAP]' : ''}`, pengguna: requester });

  const chain: PipeStep[] = [step('Submitted', `${requester} · 01:11`, 'done')];
  for (let i = 1; i <= level; i++) chain.push(step(`Approval L${i}`, `${JC_APPROVERS[(i - 1) % JC_APPROVERS.length]} · sudah setuju`, 'done'));
  chain.push(step(`Approval L${level + 1} — You`, 'Giliran anda', 'now'));
  for (let i = level + 2; i <= total; i++) chain.push(step(`Approval L${i}`, 'Menunggu', 'wait'));
  chain.push(step('Sinkron Javacore', 'Diproses di sistem internal', 'wait'));
  return { riwayat, chain };
}

// Generate ~N Javacore documents cycling across modules, all pending at GM level.
function javacoreRequests(n: number): RequestItem[] {
  const out: RequestItem[] = [];
  for (let i = 0; i < n; i++) {
    const mod = JC_MODULES[i % JC_MODULES.length];
    const meta = MODULE_META[mod.key];
    const requester = JC_REQUESTERS[i % JC_REQUESTERS.length];
    const project = JC_PROJECTS[i % JC_PROJECTS.length];
    const days = JC_DAYS[i % JC_DAYS.length];
    const value = JC_VALUES[i % JC_VALUES.length];
    const overPagu = i % 3 === 0;
    const seq = 90 + i;
    const docNo = `${mod.prefix}-2026/10-${pad4(seq)}`;
    const total = 3;
    const level = i % 3; // 0..2 levels already approved
    const jenis = mod.jenis[i % mod.jenis.length];
    const date = '29/10/2026';

    // Line items (1–2 per doc).
    const nItems = (i % 2) + 1;
    const lineItems: JavaLineItem[] = [];
    for (let k = 0; k < nItems; k++) {
      const [barang, uraian, bq, satuan] = mod.items[(i + k) % mod.items.length];
      lineItems.push({ no: k + 1, barang, uraian, bq, satuan, qty: (i % 4) + 1, jadwal: '05/11/2026' });
    }

    const { riwayat, chain } = buildRiwayatChain(docNo, meta.label, requester, level, total, overPagu, date);
    const bucket = days <= 3 ? 'red' : days <= 7 ? 'amb' : 'norm';

    out.push({
      id: `jc-${i}`,
      type: 'FUND',
      reqName: meta.label,
      tier: 't1',
      tag: bucket === 'red' ? 'URGENT' : bucket === 'amb' ? 'DUE SOON' : 'REGULER',
      amount: value != null ? rupiah(value) : docNo, // label fallback for mockService
      slaMin: days * 1440,
      slc: bucket,
      requester,
      dept: project,
      sub: `${meta.label} · GM (anda) · → Direksi`,
      purpose: project,
      desc: lineItems.map((it) => it.uraian).join(', '),
      forward: 'Direksi',
      chain,
      docs: i % 2 === 0
        ? [{ name: `${docNo.replace(/[\/]/g, '_')}.pdf`, type: 'pdf', size: '180 KB' }]
        : [
            { name: 'Penawaran_vendor.pdf', type: 'pdf', size: '248 KB' },
            { name: 'Spesifikasi_teknis.pdf', type: 'pdf', size: '96 KB' },
          ],
      // Javacore fields
      docNo,
      moduleKey: mod.key,
      moduleLabel: meta.label,
      jenis,
      project,
      branch: 'Cabang 01',
      kontrak: `NK20250300${pad4(seq).slice(1)}`,
      valueNum: value ?? undefined,
      overPagu,
      approveLevel: level,
      approveTotal: total,
      submittedAgo: JC_AGO[i % JC_AGO.length],
      docDate: date,
      needBy: '02/11/2026',
      lineItems,
      riwayat,
    });
  }
  return out;
}

// A document-access request (separate from the funding flows) per approver role.
const docAccessDirector: RequestItem = {
  id: 'd2', type: 'DOC', tier: 't2', tag: 'CONFIDENTIAL', title: 'Q2 Financials.xlsx', slaMin: 1080, slc: 'amb',
  requester: 'Sari Wijaya', dept: 'Finance', sub: 'Document access · view · you = final',
  access: 'View', cls: 'Confidential', forward: null,
  chain: [
    step('Submitted', 'Sari', 'done'),
    step('Dept Head endorsed', 'Finance', 'done'),
    step('Director — You', 'Final approver', 'now'),
    step('Access granted', 'Synced to website', 'wait'),
  ],
};
const docAccessReviewer: RequestItem = {
  id: 'rv-doc', type: 'DOC', tier: 't1', tag: 'AKSES DOKUMEN', title: 'Kontrak vendor 2026.pdf', slaMin: 1320, slc: 'norm',
  reqName: 'Akses Dokumen', requester: 'Sari Wijaya', dept: 'Finance', sub: 'Akses Dokumen · Reviewer (anda) · → Director',
  access: 'View', cls: 'Confidential', forward: null,
  chain: [
    step('Submitted', 'Sari · Yesterday', 'done'),
    step('Reviewer — You', 'Cek kelengkapan berkas', 'review'),
    step('Dept Head endorsed', 'Finance', 'wait'),
    step('Director', 'Approver', 'wait'),
    step('Access granted', 'Synced to website', 'wait'),
  ],
};

export const SEED: Record<RoleKey, RoleSeed> = {
  GM: { name: 'Rizki Setyawardana', role: 'General Manager', init: 'R', needs: javacoreRequests(24), inchain: [] },
  Director: { name: 'Rachmat Suhendra', role: 'Director', init: 'R', needs: [...flowsFor('Director'), docAccessDirector], inchain: [] },
  Owner1: { name: 'M. Winarto', role: 'Owner', init: 'W', needs: [...flowsFor('Owner1')], inchain: [] },
  Owner2: { name: 'Boy Arifin', role: 'Owner', init: 'B', needs: [...flowsFor('Owner2')], inchain: [] },
  Reviewer: { name: 'Yoga Pratama', role: 'Reviewer', init: 'Y', needs: [...flowsFor('Reviewer'), docAccessReviewer], inchain: [] },
};

// Seeded past decisions shown at the bottom of History. Each carries a request
// snapshot so the row opens into the read-only tracker, just like fresh decisions.
export const HISTORY: HistoryEntry[] = [
  {
    type: 'FUND', label: 'Rp 1,500,000', who: 'Marketing', dec: 'approved', time: 'Today 08:30',
    request: {
      id: 'h1', type: 'FUND', tier: 't1', tag: 'INTERNAL', amount: 'Rp 1,500,000', slaMin: null, slc: 'norm',
      requester: 'Rian Hakim', dept: 'Marketing', sub: 'Fund · regular · you = final',
      purpose: 'Team lunch — campaign wrap', budget: 'Marketing / Team', need: 'Today', forward: null,
      chain: [
        step('Submitted', 'Rian · 08:05', 'done'),
        step('Dept Head endorsed', 'Marketing · 08:20', 'done'),
        step('GM — You', 'Approved · 08:30', 'done'),
        step('Fund released', 'Synced to website', 'done'),
      ],
    },
  },
  {
    type: 'FUND', label: 'Rp 9,000,000', who: 'Sales', dec: 'rejected', time: 'Today 08:05',
    reason: 'Over budget',
    request: {
      id: 'h2', type: 'FUND', tier: 't2', tag: 'DUE SOON', amount: 'Rp 9,000,000', slaMin: null, slc: 'norm',
      requester: 'Maya Putri', dept: 'Sales', sub: 'Fund · regular · you = final',
      purpose: 'Client entertainment — Q2 push', budget: 'Sales / Programs', need: 'This week', forward: null,
      chain: [
        step('Submitted', 'Maya · 07:50', 'done'),
        step('Dept Head endorsed', 'Sales · 08:00', 'done'),
        step('GM — You', 'Rejected · 08:05', 'now'),
        step('Fund released', 'Not released', 'wait'),
      ],
    },
  },
];

// Preset rejection reasons (reason chips).
export const REASONS = ['Over budget', 'Need more detail', 'Wrong budget line', 'Not this quarter'];

// Preset revision reasons for the reviewer (why documents need fixing).
export const REVISION_REASONS = ['Dokumen kurang lengkap', 'Format/scan tidak jelas', 'Butuh tanda tangan', 'Nominal tidak sesuai'];

// Files the reviewer can browse and attach as supporting documents (mock).
export const BROWSABLE_DOCS: SupportingDoc[] = [
  { name: 'Catatan_review.pdf', type: 'pdf', size: '64 KB' },
  { name: 'Checklist_kelengkapan.pdf', type: 'pdf', size: '88 KB' },
  { name: 'Hasil_verifikasi.xlsx', type: 'xls', size: '120 KB' },
  { name: 'Scan_tambahan.jpg', type: 'img', size: '540 KB' },
  { name: 'Memo_internal.pdf', type: 'pdf', size: '96 KB' },
];

// ── Supporting documents (uploaded by the requester on the permission web) ─────
// Funding-flow items already carry their docs from the generator; these cover the
// document-access requests.
export const DOCS: Record<string, SupportingDoc[]> = {
  g2: [
    { name: 'Form_akses_dokumen.pdf', type: 'pdf', size: '88 KB' },
    { name: 'Persetujuan_atasan.png', type: 'img', size: '320 KB' },
  ],
  d2: [{ name: 'Justifikasi_akses.pdf', type: 'pdf', size: '96 KB' }],
  'rv-doc': [
    { name: 'Kontrak_vendor_2026.pdf', type: 'pdf', size: '1.1 MB' },
    { name: 'Lampiran_SLA.pdf', type: 'pdf', size: '210 KB' },
  ],
};

// Attach docs to requests that don't already have them (flow items keep theirs).
(Object.keys(SEED) as RoleKey[]).forEach((rk) => {
  (['needs', 'inchain'] as const).forEach((g) => {
    SEED[rk][g].forEach((it) => {
      it.docs = it.docs ?? DOCS[it.id] ?? [];
    });
  });
});

// ── Signature specimens (mock SVG paths, viewBox "0 0 64 30") ──────────────────
// The real authorization is the in-app biometric/PIN; this stamped specimen is
// only the human-readable proof printed on the BPP.
export const SIGNATURE_PATHS = {
  dimas: 'M3 20 C 9 6,14 26,19 12 S 29 5,33 19 C 37 27,44 9,52 17 C 56 21,60 13,61 16',
  putri: 'M4 16 C 12 4,16 24,24 12 C 30 4,36 22,44 14 C 50 8,55 22,60 14',
  rian: 'M3 14 C 10 24,16 4,22 16 S 33 26,40 14 C 46 6,53 20,61 12',
  budi: 'M3 22 C 12 4,18 28,26 12 C 32 2,40 22,46 12 C 50 6,56 20,61 10',
  sinta: 'M4 12 C 14 22,18 4,28 14 S 40 24,48 12 C 53 6,58 18,61 13',
  hadi: 'M3 18 C 11 6,17 22,25 12 C 31 5,38 20,45 13 C 51 8,57 19,61 12',
} as const;

// Each role's own registered specimen (shown in Profile → "Tanda tangan saya").
export const ROLE_SIGNATURE: Record<RoleKey, string> = {
  GM: SIGNATURE_PATHS.budi,
  Director: SIGNATURE_PATHS.sinta,
  Owner1: SIGNATURE_PATHS.hadi,
  Owner2: SIGNATURE_PATHS.dimas,
  Reviewer: SIGNATURE_PATHS.rian,
};

// BPP signature boxes (mock). Lower three sign on the web; GM/Director/Owners in
// the app; payment/receipt boxes are filled later (kept "menunggu").
export const BPP_SIGNERS: BppSigner[] = [
  { role: 'SPV/Mgr Dept', name: 'Dimas R.', via: 'web', signed: true, sig: SIGNATURE_PATHS.dimas, meta: 'digital · 18/06 09:05' },
  { role: 'Mgr Accounting', name: 'Putri A.', via: 'web', signed: true, sig: SIGNATURE_PATHS.putri, meta: 'digital · 18/06 09:12' },
  { role: 'Mgr Finance', name: 'Rian S.', via: 'web', signed: true, sig: SIGNATURE_PATHS.rian, meta: 'digital · 18/06 09:15' },
  { role: 'General Manager', name: 'Rizki Setyawardana', via: 'app', signed: true, sig: SIGNATURE_PATHS.budi, meta: 'digital · 18/06 09:41' },
  { role: 'Direktur', name: 'Rachmat Suhendra', via: 'app', signed: true, sig: SIGNATURE_PATHS.sinta, meta: 'digital · 18/06 09:48' },
  { role: 'General Cashier', name: '', via: 'pay', signed: false },
  { role: 'Penerima', name: '', via: 'receive', signed: false },
];

// ── Reviewer checkpoint ────────────────────────────────────────────────────────
// A reviewer checks the uploaded documents from the sidelines (observer, not an
// approver). Insert a 'review' step right after "Submitted" in every chain.
function insertReviewStep(chain: PipeStep[]): PipeStep[] {
  // Skip if the chain already involves the reviewer (flow chains + reviewer queue).
  if (chain.some((s) => s.state === 'review' || /review/i.test(s.title))) return chain;
  const idx = chain.findIndex((s) => s.title === 'Submitted');
  if (idx === -1) return chain;
  const review = step('Dokumen direview', 'Reviewer · dokumen sesuai standar', 'review');
  chain.splice(idx + 1, 0, review);
  return chain;
}

(Object.keys(SEED) as RoleKey[]).forEach((rk) => {
  (['needs', 'inchain'] as const).forEach((g) => {
    SEED[rk][g].forEach((it) => insertReviewStep(it.chain));
  });
});
HISTORY.forEach((h) => {
  if (h.request) {
    if (!h.id) h.id = h.request.id; // so the tracker can resolve docs/BPP
    insertReviewStep(h.request.chain);
  }
});
