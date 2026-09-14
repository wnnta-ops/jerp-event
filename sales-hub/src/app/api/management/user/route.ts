import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireSession, requireRole, ok, handleError, ApiError } from "@/lib/api";

const schema = z.object({
  id: z.string().min(1),
  role: z.enum(["SALES", "COLLECTION", "MANAGEMENT", "KPR"]),
});

// Update a user's role (Management only).
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    requireRole(session.user.role, ["MANAGEMENT"]);
    const { id, role } = schema.parse(await req.json());
    if (id === session.user.id && role !== "MANAGEMENT") {
      throw new ApiError(400, "Tidak bisa menurunkan role akun sendiri.");
    }
    await prisma.user.update({ where: { id }, data: { role } });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
