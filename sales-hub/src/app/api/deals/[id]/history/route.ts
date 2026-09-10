import { prisma } from "@/lib/prisma";
import { requireSession, handleError, ApiError, ok } from "@/lib/api";
import { resolveActors, actorName } from "@/lib/actors";
import { STAGES, STAGE_INDEX, BATAL_ALASAN_LABEL, STATUS_LEAD_LABEL } from "@/lib/constants";
import { formatIdDateLong } from "@/lib/wib";
import type { Stage } from "@prisma/client";

// Full per-customer history: every stage change (with from→to), follow-up, and
// cancellation — with dates and who did it. Drives the "Riwayat" timeline.
export async function GET(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    await requireSession();
    const { id } = await params;
    const deal = await prisma.deal.findUnique({
      where: { id },
      select: {
        tanggalWi: true,
        tglNup: true,
        tglUtj: true,
        tglSpr: true,
        tglAcc: true,
        tglAkad: true,
        tglRealisasi: true,
        events: {
          orderBy: [{ eventDate: "asc" }, { createdAt: "asc" }],
          select: {
            type: true,
            fromStage: true,
            toStage: true,
            eventDate: true,
            actorId: true,
            meta: true,
          },
        },
        followUps: {
          orderBy: { createdAt: "asc" },
          select: { date: true, note: true, statusLead: true, actorId: true },
        },
      },
    });
    if (!deal) throw new ApiError(404, "Customer tidak ditemukan.");

    const actors = await resolveActors([
      ...deal.events.map((e) => e.actorId),
      ...deal.followUps.map((f) => f.actorId),
    ]);

    const stageLabel = (s: Stage) => STAGES[STAGE_INDEX[s]].label;

    type Entry = { icon: string; title: string; detail: string; dateLabel: string; actor: string; ts: number };
    const timeline: Entry[] = [];

    for (const e of deal.events) {
      let icon = "→";
      let title = "";
      let detail = "";
      const meta = (e.meta ?? {}) as Record<string, unknown>;
      if (e.type === "WI") {
        icon = "🚶";
        title = "Walk-In tercatat";
      } else if (e.type === "FOLLOW_UP") {
        icon = "📞";
        title = "Follow-up";
        if (meta.statusLead) detail = `Status Lead: ${STATUS_LEAD_LABEL[String(meta.statusLead)] ?? meta.statusLead}`;
      } else if (e.type === "BATAL") {
        icon = "✖";
        title = "Dibatalkan";
        const al = meta.alasan ? BATAL_ALASAN_LABEL[String(meta.alasan)] ?? String(meta.alasan) : "";
        detail = [meta.kategori ? `Batal ${meta.kategori}` : "", al].filter(Boolean).join(" · ");
      } else {
        icon = "✓";
        title =
          e.fromStage && e.toStage
            ? `${stageLabel(e.fromStage)} → ${stageLabel(e.toStage)}`
            : `Masuk tahap ${e.toStage ? stageLabel(e.toStage) : e.type}`;
      }
      timeline.push({
        icon,
        title,
        detail,
        dateLabel: formatIdDateLong(e.eventDate),
        actor: actorName(e.actorId, actors),
        ts: e.eventDate.getTime(),
      });
    }

    for (const f of deal.followUps) {
      timeline.push({
        icon: "📝",
        title: "Catatan follow-up",
        detail: `${f.note}${f.statusLead ? ` · ${STATUS_LEAD_LABEL[f.statusLead]}` : ""}`,
        dateLabel: formatIdDateLong(f.date),
        actor: actorName(f.actorId, actors),
        ts: f.date.getTime(),
      });
    }

    timeline.sort((a, b) => a.ts - b.ts);

    const stageDates: Record<string, string | null> = {
      WI: deal.tanggalWi ? formatIdDateLong(deal.tanggalWi) : null,
      FOLLOW_UP: deal.followUps.length ? formatIdDateLong(deal.followUps[deal.followUps.length - 1].date) : null,
      NUP: deal.tglNup ? formatIdDateLong(deal.tglNup) : null,
      UTJ: deal.tglUtj ? formatIdDateLong(deal.tglUtj) : null,
      SPR: deal.tglSpr ? formatIdDateLong(deal.tglSpr) : null,
      ACC: deal.tglAcc ? formatIdDateLong(deal.tglAcc) : null,
      AKAD: deal.tglAkad ? formatIdDateLong(deal.tglAkad) : null,
      REALISASI: deal.tglRealisasi ? formatIdDateLong(deal.tglRealisasi) : null,
    };

    return ok({ timeline, stageDates });
  } catch (e) {
    return handleError(e);
  }
}
