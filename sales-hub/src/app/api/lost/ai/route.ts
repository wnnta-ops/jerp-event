import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { getReasonContext } from "@/lib/lost";
import { generateAppealStrategy, hasAnthropicKey } from "@/lib/ai";

const schema = z.object({ alasanKey: z.string().min(1) });

// Generate (or refresh) the AI appeal analysis for a lost-reason segment.
// Sales + Management can trigger it; result is cached in AppealNote.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    if (!hasAnthropicKey()) {
      throw new ApiError(
        400,
        "AI belum aktif — set ANTHROPIC_API_KEY di server (.env) lalu restart. Slot Strategi Tim tetap bisa dipakai.",
      );
    }
    const { alasanKey } = schema.parse(await req.json());
    const ctx = await getReasonContext(alasanKey);
    if (!ctx) throw new ApiError(404, "Tidak ada data untuk alasan ini.");

    const analysis = await generateAppealStrategy(ctx);
    const aiText = JSON.stringify(analysis); // structured: {pola, strategi[], prioritas}
    const now = new Date();
    await prisma.appealNote.upsert({
      where: { alasanKey },
      create: { alasanKey, aiText, aiGeneratedAt: now },
      update: { aiText, aiGeneratedAt: now },
    });

    return ok({ ok: true, aiText, aiGeneratedAt: now.toISOString() });
  } catch (e) {
    return handleError(e);
  }
}
