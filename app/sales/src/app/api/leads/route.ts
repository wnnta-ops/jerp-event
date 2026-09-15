import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError } from "@/lib/api";
import { parseDayKey, wibToday } from "@/lib/wib";

const schema = z.object({
  nama: z.string().min(1),
  phone: z.string().optional().nullable(),
  source: z.string().optional().nullable(),
  campaign: z.string().optional().nullable(),
  tanggal: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
  coordinatorId: z.string().optional().nullable(),
});

// Tambah lead manual — untuk uji coba / input sementara sebelum integrasi
// MarComm aktif. Khusus Management (SYSADMIN lolos di requireRole).
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["MANAGEMENT"]);
    const body = schema.parse(await req.json());
    const lead = await prisma.lead.create({
      data: {
        nama: body.nama,
        phone: body.phone ?? null,
        source: body.source ?? null,
        campaign: body.campaign ?? null,
        tanggal: body.tanggal ? parseDayKey(body.tanggal) : wibToday(),
        coordinatorId: body.coordinatorId || null,
      },
    });
    return ok({ ok: true, id: lead.id });
  } catch (e) {
    return handleError(e);
  }
}
