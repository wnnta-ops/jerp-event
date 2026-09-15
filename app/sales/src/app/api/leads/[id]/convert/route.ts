import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { getMyCoordinatorId, writeBackToMarcomm } from "@/lib/leads";

const schema = z.object({ dealId: z.string().min(1) });

// Tandai lead sudah jadi Walk-In: tautkan ke Deal yang baru dibuat + status WALK_IN.
// Ownership sama seperti PATCH — koor hanya boleh atas lead miliknya.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    const role = session.user.role;
    requireRole(role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const { dealId } = schema.parse(await req.json());

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
      data: { convertedDealId: dealId, status: "WALK_IN" },
    });

    void writeBackToMarcomm({ externalId: lead.externalId, leadId: id, status: "WALK_IN", dealId });

    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
