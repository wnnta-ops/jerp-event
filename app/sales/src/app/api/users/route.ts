import { z } from "zod";
import bcrypt from "bcryptjs";
import type { Role } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireSession, ApiError, ok, handleError } from "@/lib/api";
import { canManageUsers, assignableRoles } from "@/lib/user-access";

// GET /sales/api/users — daftar user (non-sysadmin: user SYSADMIN disembunyikan).
export async function GET() {
  try {
    const session = await requireSession();
    const role = session.user.role as Role;
    if (!canManageUsers(role)) throw new ApiError(403, "Tidak punya akses ke User Management.");
    const isSys = role === "SYSADMIN";
    const users = await prisma.user.findMany({
      where: isSys ? {} : { role: { not: "SYSADMIN" } },
      orderBy: [{ name: "asc" }],
      select: { id: true, name: true, email: true, role: true },
    });
    return ok({ users });
  } catch (e) {
    return handleError(e);
  }
}

const createSchema = z.object({
  name: z.string().min(1),
  email: z.string().email(),
  role: z.enum(["SALES", "COLLECTION", "MANAGEMENT", "SYSADMIN"]),
  password: z.string().min(8),
});

// POST /sales/api/users — buat user baru.
export async function POST(req: Request) {
  try {
    const session = await requireSession();
    const actorRole = session.user.role as Role;
    if (!canManageUsers(actorRole)) throw new ApiError(403, "Tidak punya akses ke User Management.");

    const b = createSchema.parse(await req.json());
    if (!assignableRoles(actorRole).includes(b.role)) throw new ApiError(403, "Peran tidak valid.");

    const email = b.email.toLowerCase().trim();
    if (await prisma.user.findUnique({ where: { email } })) throw new ApiError(409, "Email sudah terdaftar.");

    await prisma.user.create({
      data: { name: b.name.trim(), email, role: b.role, passwordHash: bcrypt.hashSync(b.password, 10) },
    });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
