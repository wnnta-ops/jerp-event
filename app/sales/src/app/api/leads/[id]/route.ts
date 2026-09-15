import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { getMyCoordinatorId, writeBackToMarcomm } from "@/lib/leads";

const schema = z.object({
  minat: z.string().optional().nullable(), // free text
  status: z.enum(["HOT", "WARM", "COLD"]).optional().nullable(),
  followUp: z.enum(["WI", "RESPON", "NO_RESPON"]).optional().nullable(),
  note: z.string().optional().nullable(), // free text
});

// Sales Koor meng-update kondisi lead miliknya. Koor HANYA boleh mengubah lead
// yang coordinatorId-nya = miliknya; Management/IT bebas.
export async function PATCH(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    const role = session.user.role;
    requireRole(role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());

    const lead = await prisma.lead.findUnique({
      where: { id },
      select: { id: true, coordinatorId: true, externalId: true },
    });
    if (!lead) throw new ApiError(404, "Lead tidak ditemukan.");

    const isManager = role === "MANAGEMENT" || role === "SYSADMIN";
    if (!isManager) {
      const myCoord = await getMyCoordinatorId(session.user.id, session.user.name ?? "");
      if (!myCoord || lead.coordinatorId !== myCoord) {
        throw new ApiError(403, "Lead ini bukan milik kamu.");
      }
    }

    await prisma.lead.update({
      where: { id },
      data: {
        minat: body.minat ?? null,
        status: body.status ?? null,
        followUp: body.followUp ?? null,
        note: body.note ?? null,
      },
    });

    // write-back opsional ke sheet MarComm (aktif bila LEADS_WRITEBACK_URL diset).
    void writeBackToMarcomm({
      externalId: lead.externalId,
      leadId: id,
      minat: body.minat ?? null,
      status: body.status ?? null,
      followUp: body.followUp ?? null,
      note: body.note ?? null,
    });

    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
