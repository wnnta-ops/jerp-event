import { z } from "zod";
import bcrypt from "bcryptjs";
import type { Role } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireSession, ApiError, ok, handleError } from "@/lib/api";
import { canManageUsers, assignableRoles } from "@/lib/user-access";

const patchSchema = z.object({
  name: z.string().min(1),
  email: z.string().email(),
  role: z.enum(["SALES", "COLLECTION", "MANAGEMENT", "SYSADMIN"]),
  password: z.string().min(8).optional().or(z.literal("")),
});

// PATCH /sales/api/users/[id] — ubah user.
export async function PATCH(req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const { id } = await params;
    const session = await requireSession();
    const actorRole = session.user.role as Role;
    if (!canManageUsers(actorRole)) throw new ApiError(403, "Tidak punya akses ke User Management.");

    const b = patchSchema.parse(await req.json());
    const target = await prisma.user.findUnique({ where: { id } });
    if (!target) throw new ApiError(404, "User tidak ditemukan.");

    // Non-sysadmin: user & role SYSADMIN seolah tak ada.
    if (actorRole !== "SYSADMIN" && (target.role === "SYSADMIN" || b.role === "SYSADMIN")) {
      throw new ApiError(404, "User tidak ditemukan.");
    }
    if (!assignableRoles(actorRole).includes(b.role)) throw new ApiError(403, "Peran tidak valid.");

    // Pengaman sysadmin terakhir.
    if (target.role === "SYSADMIN" && b.role !== "SYSADMIN") {
      const n = await prisma.user.count({ where: { role: "SYSADMIN" } });
      if (n <= 1) throw new ApiError(409, "Tidak bisa menurunkan sysadmin terakhir.");
      if (session.user.id === id) throw new ApiError(409, "Tidak bisa mencabut peran sysadmin diri sendiri.");
    }

    const email = b.email.toLowerCase().trim();
    const owner = await prisma.user.findUnique({ where: { email } });
    if (owner && owner.id !== id) throw new ApiError(409, "Email sudah dipakai user lain.");

    await prisma.user.update({
      where: { id },
      data: {
        name: b.name.trim(), email, role: b.role,
        ...(b.password ? { passwordHash: bcrypt.hashSync(b.password, 10) } : {}),
      },
    });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}

// DELETE /sales/api/users/[id] — hapus user.
export async function DELETE(_req: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const { id } = await params;
    const session = await requireSession();
    const actorRole = session.user.role as Role;
    if (!canManageUsers(actorRole)) throw new ApiError(403, "Tidak punya akses ke User Management.");
    if (session.user.id === id) throw new ApiError(409, "Tidak bisa menghapus akun sendiri.");

    const target = await prisma.user.findUnique({ where: { id } });
    if (!target) throw new ApiError(404, "User tidak ditemukan.");
    if (actorRole !== "SYSADMIN" && target.role === "SYSADMIN") throw new ApiError(404, "User tidak ditemukan.");
    if (target.role === "SYSADMIN") {
      const n = await prisma.user.count({ where: { role: "SYSADMIN" } });
      if (n <= 1) throw new ApiError(409, "Tidak bisa menghapus sysadmin terakhir.");
    }

    // Punya deal/event (actor) → jaga jejak: tolak hard-delete, minta nonaktif via edit.
    const linked = await prisma.pipelineEvent.count({ where: { actorId: id } }).catch(() => 0);
    if (linked > 0) throw new ApiError(409, "User punya riwayat aktivitas — tidak bisa dihapus (ganti perannya saja).");

    await prisma.user.delete({ where: { id } });
    return ok({ ok: true });
  } catch (e) {
    return handleError(e);
  }
}
