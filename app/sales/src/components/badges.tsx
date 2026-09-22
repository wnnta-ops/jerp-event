import type { Channel, LeadFollowUp, LeadStatus, Stage, StatusLead, UnitStatus } from "@prisma/client";
import {
  CHANNEL_LABEL,
  LEAD_FOLLOWUP_LABEL,
  LEAD_STATUS_LABEL,
  STAGES,
  STAGE_INDEX,
  STATUS_LEAD_LABEL,
  UNIT_STATUS_LABEL,
} from "@/lib/constants";

// Pure presentational helpers — safe to use in server components.

export function LeadBadge({ t }: { t: StatusLead | null | undefined }) {
  if (!t) return null;
  const cls: Record<string, string> = { HOT: "b-red", WARM: "b-amber", COLD: "b-blue", DONE: "b-green" };
  return <span className={`badge ${cls[t] ?? ""}`}>{STATUS_LEAD_LABEL[t]}</span>;
}

export function LeadStatusBadge({ s }: { s: LeadStatus | null | undefined }) {
  if (!s) return <span style={{ color: "var(--sub)" }}>–</span>;
  const cls: Record<string, string> = { HOT: "b-red", WARM: "b-amber", COLD: "b-blue" };
  return <span className={`badge ${cls[s] ?? "b-gray"}`}>{LEAD_STATUS_LABEL[s]}</span>;
}

export function LeadFollowUpBadge({ f }: { f: LeadFollowUp | null | undefined }) {
  if (!f) return <span style={{ color: "var(--sub)" }}>–</span>;
  const cls: Record<string, string> = { WI: "b-green", RESPON: "b-blue", NO_RESPON: "b-gray" };
  return <span className={`badge ${cls[f] ?? "b-gray"}`}>{LEAD_FOLLOWUP_LABEL[f]}</span>;
}

export function ChannelBadge({ c }: { c: Channel }) {
  const cls: Record<string, string> = { SITE: "b-teal", PAMERAN: "b-purple", JANJIAN: "b-blue", LEADS: "b-green" };
  return <span className={`badge ${cls[c] ?? "b-gray"}`}>{CHANNEL_LABEL[c]}</span>;
}

export function StageBadge({ stage }: { stage: Stage }) {
  // idx 5-6 = Collection (purple), idx 7 = AJB/Legal (pastel green)
  const cls = ["b-blue", "b-amber", "b-teal", "b-green", "b-teal", "b-purple", "b-purple", "b-legal"];
  const idx = STAGE_INDEX[stage];
  return <span className={`badge ${cls[idx]}`}>{STAGES[idx].label}</span>;
}

export function UnitStatusBadge({ s }: { s: UnitStatus }) {
  const cls: Record<string, string> = {
    REALISASI: "b-green",
    CASH_LUNAS: "b-teal",
    PROSES: "b-amber",
    OPEN_STOCK: "b-gray",
    HOLD: "b-purple",
    BATAL: "b-red",
  };
  return <span className={`badge ${cls[s] ?? "b-gray"}`}>{UNIT_STATUS_LABEL[s]}</span>;
}
