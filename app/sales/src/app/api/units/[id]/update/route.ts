import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";
import { resolveCoordinatorId } from "@/lib/derivations";
import { wibToday } from "@/lib/wib";

const schema = z.object({
  // "batal" = cancel the current booking; unit returns to Open Stock.
  batal: z.boolean().optional(),
  status: z.enum(["OPEN_STOCK", "HOLD", "PROSES", "REALISASI", "CASH_LUNAS"]).optional(),
  nama: z.string().optional().nullable(),
  salesId: z.string().optional().nullable(),
  agencyId: z.string().optional().nullable(),
  caraBayar: z.enum(["KPR", "CASH"]).optional().nullable(),
});

// Full edit of a house/unit + its current booking. Every change is logged to
// AuditLog (the "log rumah"). Batal flips the unit straight to Open Stock while
// preserving the previous booking in history.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["SALES", "MANAGEMENT"]);
    const { id } = await params;
    const body = schema.parse(await req.json());

    const unit = await prisma.unit.findUnique({
      where: { id },
      include: { deals: true },
    });
    if (!unit) throw new ApiError(404, "Unit tidak ditemukan.");
    const current = unit.deals.find((d) => d.id === unit.currentDealId) ?? null;
    const today = wibToday();

    // ---- Batal: return to Open Stock, keep booking history ----
    if (body.batal) {
      await prisma.$transaction(async (tx) => {
        if (current) {
          await tx.deal.update({
            where: { id: current.id },
            data: {
              batal: true,
              batalKategori: current.tglSpr ? "SPR" : "UTJ",
              batalAlasan: "OTHER",
              batalTanggal: today,
            },
          });
          await tx.pipelineEvent.create({
            data: {
              dealId: current.id,
              unitId: unit.id,
              type: "BATAL",
              eventDate: today,
              fromStage: current.stage,
              actorId: session.user.id,
              meta: { source: "stock", kategori: current.tglSpr ? "SPR" : "UTJ" },
            },
          });
        }
        await tx.unit.update({
          where: { id },
          data: { status: "OPEN_STOCK", currentDealId: null, statusOverride: false },
        });
        await tx.auditLog.create({
          data: {
            actorId: session.user.id,
            entity: "Unit",
            entityId: id,
            action: "batal",
            before: { status: unit.status, user: current?.nama ?? null } as object,
            after: { status: "OPEN_STOCK" } as object,
          },
        });
      });
      return ok({ ok: true, batal: true });
    }

    // ---- Edit fields (status + current booking details) ----
    await prisma.$transaction(async (tx) => {
      const before: Record<string, string | null> = { status: unit.status };
      const after: Record<string, string | null> = {};

      if (body.status && body.status !== unit.status) {
        await tx.unit.update({
          where: { id },
          data: { status: body.status, statusOverride: true },
        });
        after.status = body.status;
      }

      if (current) {
        const dealData: Record<string, unknown> = {};
        if (body.nama != null && body.nama !== current.nama) {
          before.user = current.nama;
          after.user = body.nama;
          dealData.nama = body.nama;
        }
        if (body.salesId && body.salesId !== current.salesId) {
          dealData.salesId = body.salesId;
          after.salesId = body.salesId;
        }
        if (body.agencyId && body.agencyId !== current.agencyId) {
          dealData.agencyId = body.agencyId;
          dealData.coordinatorId = await resolveCoordinatorId(body.agencyId, current.tanggalWi);
          after.agencyId = body.agencyId;
        }
        if (body.caraBayar && body.caraBayar !== current.caraBayar) {
          dealData.caraBayar = body.caraBayar;
          after.caraBayar = body.caraBayar;
        }
        if (Object.keys(dealData).length) {
          await tx.deal.update({ where: { id: current.id }, data: dealData });
        }
      }

      await tx.auditLog.create({
        data: {
          actorId: session.user.id,
          entity: "Unit",
          entityId: id,
          action: "edit",
          before: before as object,
          after: after as object,
        },
      });
    });

    return ok();
  } catch (e) {
    return handleError(e);
  }
}
