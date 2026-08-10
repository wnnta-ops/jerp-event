import { z } from "zod";
import bcrypt from "bcryptjs";
import type { Role } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireSession, ApiError, ok, handleError } from "@/lib/api";
import { canManageUsers } from "@/lib/user-access";

const schema = z.object({ password: z.string().min(8) });

// POST /sales/api/users/[id]/reset-password — set password baru.
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const { id } = await params;
    const session = await requireSession();
    const actorRole = session.user.role as Role;
    if (!canManageUsers(actorRole)) throw new ApiError(403, "Tidak punya akses ke User Management.");

    const { password } = schema.parse(await req.json());
    const target = await prisma.user.findUnique({ where: { id } });
    if (!target) throw new ApiError(404, "User tidak ditemukan.");
    // Non-sysadmin: user SYSADMIN seolah tak ada.
    if (actorRole !== "SYSADMIN" && target.role === "SYSADMIN") throw new ApiError(404, "User tidak ditemukan.");

    await prisma.user.update({ where: { id }, data: { passwordHash: bcrypt.hashSync(password, 10) } });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
