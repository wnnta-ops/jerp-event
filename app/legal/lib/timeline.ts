// Shared timeline (Gantt) constants & helpers.

export const MO12 = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];

export const CHECKPOINT_STEPS = [
  { key: "tanda_terima", label: "Tanda Terima" },
  { key: "survey_lokasi", label: "Survey Lokasi" },
  { key: "rapat", label: "Rapat" },
  { key: "product", label: "Product" },
] as const;
export type CheckpointKey = (typeof CHECKPOINT_STEPS)[number]["key"];
export const TOTAL_STEPS = CHECKPOINT_STEPS.length;

/** Label checkpoint untuk ditampilkan. Item baru menyimpan label proses langsung di
 *  `step`; item lama menyimpan key 4-langkah (tanda_terima…) — diterjemahkan di sini. */
export function checkpointLabel(step: string, order: number): string {
  const legacy = CHECKPOINT_STEPS.find((s) => s.key === step);
  if (legacy) return legacy.label;
  return step.trim() || `Langkah ${order + 1}`;
}

export const STATUS_OPTIONS = ["—", "Express", "On Target", "Proses", "Delay", "Eksternal", "Done"] as const;

export function parseMonths(json: string): string[] {
  try {
    const a = JSON.parse(json);
    return Array.isArray(a) && a.length ? a.map(String) : MO12;
  } catch {
    return MO12;
  }
}

export type Tone = "neutral" | "info" | "success" | "warning" | "danger";

export function statusTone(status: string): Tone {
  const k = status.toLowerCase().replace(/[^a-z]/g, "");
  if (k.startsWith("done")) return "success";
  if (k === "express") return "info";
  if (k === "ontarget") return "success";
  if (k === "proses") return "warning";
  if (k === "delay") return "danger";
  return "neutral"; // eksternal / —
}

export const TONE_BG: Record<Tone, string> = {
  neutral: "var(--color-background-secondary)",
  info: "var(--color-background-info)",
  success: "var(--color-background-success)",
  warning: "var(--color-background-warning)",
  danger: "var(--color-background-danger)",
};
export const TONE_FG: Record<Tone, string> = {
  neutral: "var(--color-text-secondary)",
  info: "var(--color-text-info)",
  success: "var(--color-text-success)",
  warning: "var(--color-text-warning)",
  danger: "var(--color-text-danger)",
};
