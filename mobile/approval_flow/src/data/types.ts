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
