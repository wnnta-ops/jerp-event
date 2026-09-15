import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { resolveCoordinatorId } from "@/lib/derivations";
import { parseDayKey } from "@/lib/wib";

const schema = z.object({
  nama: z.string().min(1),
  tanggalWi: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
  channel: z.enum(["SITE", "PAMERAN", "JANJIAN", "LEADS"]),
  agencyId: z.string().min(1),
  salesId: z.string().min(1),
  coordinatorId: z.string().optional().nullable(),
  clusterId: z.string().optional().nullable(),
  phaseId: z.string().optional().nullable(),
  unitTypeId: z.string().optional().nullable(),
  statusLead: z.enum(["HOT", "WARM", "COLD", "DONE"]),
  sumberInformasi: z.string().optional().nullable(),
  catatan: z.string().optional().nullable(),
});

// Edit a Walk-In. Koordinator re-derived from bendera at the deal's WI date.
export async function PATCH(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());

    const existing = await prisma.deal.findUnique({ where: { id }, select: { tanggalWi: true } });
    if (!existing) throw new ApiError(404, "Data tidak ditemukan.");
    const wiDate = body.tanggalWi ? parseDayKey(body.tanggalWi) : existing.tanggalWi;
    const coordinatorId =
      body.coordinatorId || (await resolveCoordinatorId(body.agencyId, wiDate));

    await prisma.deal.update({
      where: { id },
      data: {
        nama: body.nama,
        tanggalWi: wiDate,
        channel: body.channel,
        agencyId: body.agencyId,
        salesId: body.salesId,
        coordinatorId,
        clusterId: body.clusterId ?? null,
        phaseId: body.phaseId ?? null,
        unitTypeId: body.unitTypeId ?? null,
        statusLead: body.statusLead,
        sumberInformasi: body.sumberInformasi ?? null,
        catatan: body.catatan ?? null,
      },
    });
    // keep the WI ledger event's date in sync so reports match
    if (body.tanggalWi) {
      const wi = await prisma.pipelineEvent.findFirst({
        where: { dealId: id, type: "WI" },
        orderBy: { eventDate: "asc" },
        select: { id: true },
      });
      if (wi) await prisma.pipelineEvent.update({ where: { id: wi.id }, data: { eventDate: wiDate } });
    }
    return ok({ ok: true, id });
  } catch (e) {
    return handleError(e);
  }
}

// Delete a Walk-In and all its ledger rows (events, follow-ups, demographic).
export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const existing = await prisma.deal.findUnique({ where: { id }, select: { id: true } });
    if (!existing) throw new ApiError(404, "Data tidak ditemukan.");

    await prisma.$transaction([
      prisma.pipelineEvent.deleteMany({ where: { dealId: id } }),
      prisma.followUp.deleteMany({ where: { dealId: id } }),
      prisma.customerDemographic.deleteMany({ where: { dealId: id } }),
      prisma.deal.delete({ where: { id } }),
    ]);
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
