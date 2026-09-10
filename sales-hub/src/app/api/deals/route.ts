import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { resolveCoordinatorId } from "@/lib/derivations";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  nama: z.string().min(1),
  channel: z.enum(["SITE", "PAMERAN", "JANJIAN"]),
  pameranLokasi: z.string().optional().nullable(),
  sumberInformasi: z.string().optional().nullable(),
  agencyId: z.string().min(1),
  salesId: z.string().min(1),
  clusterId: z.string().optional().nullable(),
  phaseId: z.string().optional().nullable(),
  unitTypeId: z.string().optional().nullable(),
  statusLead: z.enum(["HOT", "WARM", "COLD"]).default("COLD"),
  catatan: z.string().optional().nullable(),
});

// Create a new Walk-In (Customer/Deal). Sales-owned. Koordinator is derived.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const body = schema.parse(await req.json());
    const today = wibToday();
    const coordinatorId = await resolveCoordinatorId(body.agencyId, today);

    const deal = await prisma.$transaction(async (tx) => {
      const d = await tx.deal.create({
        data: {
          nama: body.nama,
          channel: body.channel,
          pameranLokasi: body.pameranLokasi ?? null,
          sumberInformasi: body.sumberInformasi ?? null,
          agencyId: body.agencyId,
          salesId: body.salesId,
          coordinatorId,
          clusterId: body.clusterId ?? null,
          phaseId: body.phaseId ?? null,
          unitTypeId: body.unitTypeId ?? null,
          statusLead: body.statusLead,
          stage: "WI",
          tanggalWi: today,
          catatan: body.catatan ?? null,
        },
      });
      await tx.pipelineEvent.create({
        data: { dealId: d.id, type: "WI", eventDate: today, toStage: "WI", actorId: session.user.id },
      });
      return d;
    });

    return ok({ ok: true, id: deal.id, coordinatorId });
  } catch (e) {
    return handleError(e);
  }
}
