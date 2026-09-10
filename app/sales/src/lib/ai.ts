import Anthropic from "@anthropic-ai/sdk";
import type { ReasonContext, Slice } from "./lost";

// Model is configurable; defaults to the most capable Opus. Set ANTHROPIC_MODEL
// to e.g. claude-haiku-4-5 to lower cost for this lightweight analytics task.
const MODEL = process.env.ANTHROPIC_MODEL ?? "claude-opus-4-8";

export function hasAnthropicKey(): boolean {
  return !!process.env.ANTHROPIC_API_KEY;
}

// Structured AI output rendered as 3 blocks in the UI.
export interface AppealAnalysis {
  pola: string; // Diagnosa — pola utama
  strategi: string[]; // Strategi appeal konkret
  prioritas: { label: string; alasan: string }; // Prioritas segmen
}

const SYSTEM = `Kamu analis penjualan properti residensial di Indonesia. Dari data AGREGAT & ANONIM (tanpa nama/identitas customer) tentang deal yang GAGAL, kamu menghasilkan 3 hal: DIAGNOSA pola, STRATEGI appeal, dan penilaian PRIORITAS.

Prinsip:
- Jangan mengarang angka di luar yang diberikan. Kalau data demografik belum tersedia, analisa dari proksi internal (cluster/tipe/channel/bank/cara bayar/tahap) dan boleh sebut keterbatasannya singkat.
- Bahasa Indonesia, ringkas, spesifik ke pola yang terlihat (bukan template umum).

Untuk PRIORITAS pilih salah satu label:
- "Recoverable" = worth dikejar (masih bisa di-appeal).
- "Lost cause" = kecil peluang (faktor eksternal), simpan buat referensi.
- "Campuran" = sebagian recoverable, sebagian tidak.
Untuk segmen "Semua alasan", label PRIORITAS = "Ranking" dan isi 'alasan' dengan urutan segmen yang harus digarap duluan beserta alasan singkat.

Balas HANYA JSON valid (tanpa markdown, tanpa teks lain) dengan bentuk:
{"pola": string, "strategi": [string, ...3-5], "prioritas": {"label": string, "alasan": string}}`;

function slicesLine(label: string, s: Slice[]): string {
  if (!s.length) return `${label}: -`;
  return `${label}: ${s.map((x) => `${x.label} ${x.n} (${x.pct}%)`).join(", ")}`;
}

function buildDataBlock(ctx: ReasonContext): string {
  const lines = [
    ctx.isAll ? `Segmen: SEMUA alasan gabungan` : `Alasan gagal: ${ctx.alasanLabel}`,
    `Total kasus: ${ctx.total}`,
  ];
  if (ctx.isAll) lines.push(slicesLine("Per alasan", ctx.byAlasan));
  lines.push(
    slicesLine("Per cluster", ctx.byCluster),
    slicesLine("Per tipe rumah", ctx.byType),
    slicesLine("Per channel akuisisi", ctx.byChannel),
    slicesLine("Per cara bayar", ctx.byCaraBayar),
    slicesLine("Per bank/bendera", ctx.byBendera),
    slicesLine("Tahap saat gagal", ctx.byStage),
  );
  // Tier-2 demographic — only include when the external pathway has data.
  if (ctx.demo.coverage > 0) {
    lines.push(
      `\nDemografik (terisi ${ctx.demo.coverage}/${ctx.total} customer):`,
      slicesLine("Umur", ctx.demo.byAge),
      slicesLine("Pekerjaan", ctx.demo.byJob),
      slicesLine("Domisili", ctx.demo.byDomisili),
      slicesLine("Penghasilan", ctx.demo.byIncome),
    );
  } else {
    lines.push(`\nDemografik (umur/pekerjaan/domisili/penghasilan): belum tersambung.`);
  }
  return lines.join("\n");
}

function coerce(raw: string): AppealAnalysis {
  let txt = raw.trim();
  // strip accidental ```json fences
  const fence = txt.match(/```(?:json)?\s*([\s\S]*?)```/i);
  if (fence) txt = fence[1].trim();
  try {
    const j = JSON.parse(txt);
    const strategi = Array.isArray(j.strategi)
      ? j.strategi.map((x: unknown) => String(x)).filter(Boolean)
      : [];
    return {
      pola: String(j.pola ?? "").trim(),
      strategi,
      prioritas: {
        label: String(j.prioritas?.label ?? "—").trim(),
        alasan: String(j.prioritas?.alasan ?? "").trim(),
      },
    };
  } catch {
    // Fallback: keep the raw text as the diagnosa so nothing is lost.
    return { pola: raw.trim(), strategi: [], prioritas: { label: "—", alasan: "" } };
  }
}

export async function generateAppealStrategy(ctx: ReasonContext): Promise<AppealAnalysis> {
  const client = new Anthropic();
  const res = await client.messages.create({
    model: MODEL,
    max_tokens: 1024,
    system: SYSTEM,
    messages: [
      {
        role: "user",
        content: `Data agregat (anonim):\n\n${buildDataBlock(ctx)}\n\nHasilkan diagnosa, strategi, dan prioritas dalam JSON sesuai format.`,
      },
    ],
  });

  const text = res.content
    .filter((b): b is Anthropic.TextBlock => b.type === "text")
    .map((b) => b.text)
    .join("\n");
  return coerce(text);
}
