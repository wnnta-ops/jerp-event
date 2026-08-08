import { DOC_STATUS_LABELS, DOC_TYPE_SHORT, STATUS_TONE, type DocStatus, type DocType } from "@/lib/enums";

const TONE_BG: Record<string, string> = {
  neutral: "var(--color-background-secondary)",
  info: "var(--color-background-info)",
  success: "var(--color-background-success)",
  warning: "var(--color-background-warning)",
  danger: "var(--color-background-danger)",
};
const TONE_FG: Record<string, string> = {
  neutral: "var(--color-text-secondary)",
  info: "var(--color-text-info)",
  success: "var(--color-text-success)",
  warning: "var(--color-text-warning)",
  danger: "var(--color-text-danger)",
};

/** Colored status pill — "status carries the color" in the directory (spec §4). */
export function StatusBadge({ status }: { status: DocStatus }) {
  const tone = STATUS_TONE[status] ?? "neutral";
  return (
    <span
      className="inline-block rounded-md px-2 py-0.5 text-[11px] font-medium"
      style={{ background: TONE_BG[tone], color: TONE_FG[tone] }}
    >
      {DOC_STATUS_LABELS[status] ?? status}
    </span>
  );
}

/** Neutral type badge — type stays neutral in the directory (color is reserved for status). */
export function TypeBadge({ docType }: { docType: DocType }) {
  return (
    <span
      className="inline-block rounded-md px-1.5 py-px text-[10px] font-medium"
      style={{
        background: "var(--color-background-secondary)",
        color: "var(--color-text-secondary)",
        border: "0.5px solid var(--color-border-tertiary)",
      }}
    >
      {DOC_TYPE_SHORT[docType] ?? docType}
    </span>
  );
}

export function Avatar({ initials, size = 24 }: { initials: string; size?: number }) {
  return (
    <span
      className="inline-flex items-center justify-center rounded-full font-medium"
      style={{
        width: size,
        height: size,
        fontSize: size <= 24 ? 10 : 11,
        background: "var(--color-background-info)",
        color: "var(--color-text-info)",
      }}
    >
      {initials}
    </span>
  );
}
