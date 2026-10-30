// Domain types for the approver app. These mirror the SEED shape in the HTML
// prototype and define the contract the UI relies on. When the real backend
// lands, only the mock implementation behind mockService changes — not these.

import type { Tier, SlaClass } from '../theme/tokens';

export type RequestType = 'FUND' | 'DOC';
export type RoleKey = 'GM' | 'Director' | 'Owner1' | 'Owner2' | 'Reviewer';
export type Decision = 'approved' | 'rejected';
// 'review' = a reviewer who checks the uploaded documents from the sidelines
// (an observer checkpoint, not an approval gate).
export type PipeState = 'done' | 'now' | 'wait' | 'review';
export type DocType = 'pdf' | 'img' | 'xls';

// A supporting document attached to a request (uploaded by the requester on the
// permission web). The app only displays these — it never uploads.
export interface SupportingDoc {
  name: string;
  type: DocType;
  size: string;
}

// One signature box on the BPP. `sig` is an SVG path (specimen) when signed.
export interface BppSigner {
  role: string; // e.g. "General Manager"
  name: string; // signer name, empty while waiting
  via: 'web' | 'app' | 'pay' | 'receive';
  signed: boolean;
  sig?: string; // SVG path for the stamped specimen
  meta?: string; // e.g. "digital · 18/06 09:41"
}

// Mock BPP (Bukti Permohonan Pembayaran) preview payload built by the service.
export interface BppPreview {
  generatedAt: string;
  date: string;
  payee: string; // requester · dept
  uraian: string; // description
  total: string; // amount or "(non-tunai)"
  voucher: string;
  terbilang: string;
  signers: BppSigner[];
}

// One step in the approval pipeline / chain tracker.
export interface PipeStep {
  title: string; // e.g. "GM — You"
  detail: string; // e.g. "Awaiting your endorsement"
  state: PipeState;
}

// ── Javacore (MUK ERP) integration shapes ──────────────────────────────────────
// Approval documents are pulled from Javacore. These mirror what Javacore's
// "Inbox Approval" + document detail + Riwayat (activity log) actually expose,
// so the real API swap maps field-for-field.

// One line item on a document (Rincian Permintaan on a PR, etc.).
export interface JavaLineItem {
  no: number;
  barang: string; // item code, e.g. "AK01"
  uraian: string; // description, e.g. "FLOOR EMBOSSER"
  bq?: string; // List Kerja / BQ
  satuan: string; // unit, e.g. "PCS"
  qty: number;
  jadwal?: string; // Jadwal datang (arrival schedule)
}

export type RiwayatAction = 'Tambah' | 'Submit' | 'Approve' | 'Tolak' | 'Revisi';

// One row in Javacore's "Riwayat & Log Aktivitas" (the tracking/audit log).
export interface RiwayatEntry {
  waktu: string; // "29/10/2026 14:22"
  aksi: RiwayatAction;
  keterangan: string; // "Approval level tercatat — menunggu level 2/3"
  pengguna: string; // "HELMI"
}

// A single approval request. Fund and Document variants share this shape;
// the variant-specific fields are optional.
export interface RequestItem {
  id: string;
  type: RequestType;
  reqName?: string; // funding request type shown to users: Memo / Kasbon / AP
  tier: Tier;
  tag: string; // URGENT / INTERNAL / DUE SOON / BOARD ...
  slaMin: number | null; // SLA time remaining in minutes (null = in chain)
  slc: SlaClass; // sla pill color class

  requester: string;
  dept: string;
  sub: string; // one-line context under the title

  // Fund-only
  amount?: string;
  purpose?: string; // short title / subject (judul)
  desc?: string; // one-line description of the document's purpose
  budget?: string;
  need?: string;

  // Doc-only
  title?: string;
  access?: string;
  cls?: string;

  forward: string | null; // next head this forwards to on approve (L2 items)
  chain: PipeStep[];
  docs?: SupportingDoc[]; // supporting documents attached on the permission web

  // ── Javacore fields (present on documents pulled from MUK ERP) ──────────────
  docNo?: string; // Nomor dokumen / numerator, e.g. "PR-2026/10-0090"
  moduleKey?: string; // module code: PR / PO / SPK / RETENSI / OPNAME / MATERIAL
  moduleLabel?: string; // "Permintaan Pembelian", "Order Pembelian", ...
  jenis?: string; // document sub-type, e.g. "Alat Kerja" / "Material"
  project?: string; // Proyek
  branch?: string; // Cabang
  kontrak?: string; // Kontrak Proyek no.
  valueNum?: number; // Nilai (Rp) as a number (null/undefined when "—")
  overPagu?: boolean; // "Melebihi Pagu / Non-RAP" flag
  approveLevel?: number; // levels already approved
  approveTotal?: number; // total levels in the chain (shows "Approve X/N")
  submittedAgo?: string; // "1 hari lalu", "19 jam lalu"
  docDate?: string; // Tanggal dokumen, e.g. "29/10/2026" (table TANGGAL column)
  needBy?: string; // Tanggal dibutuhkan, e.g. "02/11/2026"
  lineItems?: JavaLineItem[]; // Rincian permintaan
  riwayat?: RiwayatEntry[]; // Riwayat & Log Aktivitas (tracking)
}

// A recorded decision shown in History / the Done tab. Carries a snapshot of the
// request so the entry is tappable into a read-only tracker view.
export interface HistoryEntry {
  id?: string; // source request id (for BPP lookup)
  type: RequestType;
  label: string; // amount or document title
  who: string; // requester · dept
  dec: Decision;
  decLabel?: string; // display override (e.g. "Sesuai" / "Revisi" for reviewer)
  time: string;
  reason?: string; // rejection reason, when dec === 'rejected'
  request?: RequestItem; // snapshot of the request for the tracker
}

export interface RoleProfile {
  name: string;
  role: string; // display label, e.g. "General Manager"
  init: string; // avatar initial
}

// What getQueue returns for the active session.
export interface Queue {
  needs: RequestItem[];
  inchain: RequestItem[];
  done: HistoryEntry[];
}

// Result returned by decide() so the confirmation screen can render correctly.
export interface DecideResult {
  ok: boolean;
  offline?: boolean; // true when the decision failed due to simulated offline
  decision?: Decision;
  request?: RequestItem;
}
