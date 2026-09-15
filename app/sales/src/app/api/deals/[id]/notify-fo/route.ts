import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { wibToday } from "@/lib/wib";

// ---------------------------------------------------------------------------
// Outbound pathway to the FO (Front Office) web app. When a lead is ready to
// move to UTJ/NUP, Sales clicks "Next" → we POST the lead to FO's webhook so FO
// can pick it up. Configure FO_WEBHOOK_URL (+ optional FO_WEBHOOK_TOKEN) in the
// server env. Unconfigured → 501 (button explains it's not connected yet).
// ---------------------------------------------------------------------------
export async function POST(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;

    const url = process.env.FO_WEBHOOK_URL;
    if (!url) {
      throw new ApiError(
        501,
        "Pathway FO belum dikonfigurasi — set FO_WEBHOOK_URL di server untuk mengaktifkan tombol Next.",
      );
    }

    const d = await prisma.deal.findUnique({
      where: { id },
      select: {
        id: true,
        nama: true,
        channel: true,
        stage: true,
        statusLead: true,
        catatan: true,
        sumberInformasi: true,
        agency: { select: { name: true } },
        sales: { select: { name: true } },
        coordinator: { select: { name: true } },
        cluster: { select: { name: true } },
        unitType: { select: { name: true } },
      },
    });
    if (!d) throw new ApiError(404, "Customer tidak ditemukan.");

    const payload = {
      source: "java-sales-hub",
      event: "lead_ready_for_fo",
      dealId: d.id,
      nama: d.nama,
      channel: d.channel,
      stage: d.stage,
      statusLead: d.statusLead,
      sumberInformasi: d.sumberInformasi,
      agent: d.agency?.name ?? null,
      sales: d.sales?.name ?? null,
      koordinator: d.coordinator?.name ?? null,
      cluster: d.cluster?.name ?? null,
      type: d.unitType?.name ?? null,
      catatan: d.catatan,
      sentAt: new Date().toISOString(),
    };

    const headers: Record<string, string> = { "Content-Type": "application/json" };
    if (process.env.FO_WEBHOOK_TOKEN) headers.Authorization = `Bearer ${process.env.FO_WEBHOOK_TOKEN}`;

    const res = await fetch(url, { method: "POST", headers, body: JSON.stringify(payload) });
    if (!res.ok) throw new ApiError(502, `FO menolak (HTTP ${res.status}).`);

    // record the hand-off in the ledger for traceability
    await prisma.pipelineEvent.create({
      data: {
        dealId: d.id,
        type: "FOLLOW_UP",
        eventDate: wibToday(),
        fromStage: d.stage,
        actorId: session.user.id,
        meta: { note: "Diteruskan ke FO (Next)" },
      },
    });

    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
