"use server";

import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { hashPassword } from "@/lib/auth";
import { ROLES, type Role } from "@/lib/enums";
import { initialsOf } from "@/lib/utils";
import { canManageUsers } from "@/lib/user-access";

export type AdminResult = { ok: true } | { ok: false; error: string };

/** Aksi user-management. Selain SYSADMIN, user & role SYSADMIN TAK TERLIHAT/tak boleh disentuh. */
async function requireUserAdmin() {
  const acting = await getActingUser();
  if (!acting) return { acting: null, err: "Sesi berakhir. Silakan login ulang." };
  if (!canManageUsers(acting.role)) return { acting, err: "Anda tidak punya akses ke User Management." };
  return { acting, err: null };
}

const isValidRole = (r: string): r is Role => (ROLES as readonly string[]).includes(r);

export async function createUser(formData: FormData): Promise<AdminResult> {
  const { acting, err } = await requireUserAdmin();
  if (err || !acting) return { ok: false, error: err ?? "Ditolak." };

  const name = String(formData.get("name") ?? "").trim();
  const email = String(formData.get("email") ?? "").toLowerCase().trim();
  const role = String(formData.get("role") ?? "");
  const department = String(formData.get("department") ?? "").trim();
  const password = String(formData.get("password") ?? "");

  if (!name) return { ok: false, error: "Nama wajib diisi." };
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return { ok: false, error: "Email tidak valid." };
  if (!isValidRole(role)) return { ok: false, error: "Peran tidak valid." };
  // Non-sysadmin tidak boleh membuat/menetapkan peran SYSADMIN.
  if (role === "SYSADMIN" && acting.role !== "SYSADMIN") return { ok: false, error: "Peran tidak valid." };
  if (password.length < 8) return { ok: false, error: "Kata sandi minimal 8 karakter." };
  if (await prisma.user.findUnique({ where: { email } })) return { ok: false, error: "Email sudah terdaftar." };

  await prisma.user.create({
    data: {
      name, email, role, department: department || null,
      initials: initialsOf(name), isActive: true,
      passwordHash: await hashPassword(password),
    },
  });
  revalidatePath("/users");
  return { ok: true };
}

export async function updateUser(formData: FormData): Promise<AdminResult> {
  const { acting, err } = await requireUserAdmin();
  if (err || !acting) return { ok: false, error: err ?? "Ditolak." };

  const id = String(formData.get("id") ?? "");
  const name = String(formData.get("name") ?? "").trim();
  const email = String(formData.get("email") ?? "").toLowerCase().trim();
  const role = String(formData.get("role") ?? "");
  const department = String(formData.get("department") ?? "").trim();
  const isActive = String(formData.get("isActive") ?? "") === "1";
  const password = String(formData.get("password") ?? ""); // opsional — kosong = tak diubah

  const target = await prisma.user.findUnique({ where: { id } });
  if (!target) return { ok: false, error: "User tidak ditemukan." };
  // Non-sysadmin TAK BOLEH melihat/menyentuh user SYSADMIN, atau menetapkan peran SYSADMIN.
  if (acting.role !== "SYSADMIN" && (target.role === "SYSADMIN" || role === "SYSADMIN")) {
    return { ok: false, error: "User tidak ditemukan." };
  }
  if (!name) return { ok: false, error: "Nama wajib diisi." };
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return { ok: false, error: "Email tidak valid." };
  if (!isValidRole(role)) return { ok: false, error: "Peran tidak valid." };
  if (password && password.length < 8) return { ok: false, error: "Kata sandi minimal 8 karakter." };

  const emailOwner = await prisma.user.findUnique({ where: { email } });
  if (emailOwner && emailOwner.id !== id) return { ok: false, error: "Email sudah dipakai user lain." };

  // Pengaman: jangan sampai sysadmin terakhir kehilangan aksesnya sendiri.
  if (target.role === "SYSADMIN" && (role !== "SYSADMIN" || !isActive)) {
    const sysAktif = await prisma.user.count({ where: { role: "SYSADMIN", isActive: true } });
    if (sysAktif <= 1) return { ok: false, error: "Tidak bisa menurunkan/menonaktifkan sysadmin terakhir." };
    if (acting.id === id && role !== "SYSADMIN") {
      return { ok: false, error: "Anda tidak bisa mencabut peran sysadmin diri sendiri." };
    }
  }

  await prisma.user.update({
    where: { id },
    data: {
      name, email, role, department: department || null, isActive,
      initials: initialsOf(name),
      ...(password ? { passwordHash: await hashPassword(password) } : {}),
    },
  });
  revalidatePath("/users");
  return { ok: true };
}

/** Reset kata sandi user (set password baru). Aturan visibilitas sama: non-sysadmin
 *  tak boleh menyentuh akun SYSADMIN. */
export async function resetPassword(formData: FormData): Promise<AdminResult> {
  const { acting, err } = await requireUserAdmin();
  if (err || !acting) return { ok: false, error: err ?? "Ditolak." };

  const id = String(formData.get("id") ?? "");
  const password = String(formData.get("password") ?? "");

  const target = await prisma.user.findUnique({ where: { id } });
  if (!target) return { ok: false, error: "User tidak ditemukan." };
  if (acting.role !== "SYSADMIN" && target.role === "SYSADMIN") return { ok: false, error: "User tidak ditemukan." };
  if (password.length < 8) return { ok: false, error: "Kata sandi minimal 8 karakter." };

  await prisma.user.update({ where: { id }, data: { passwordHash: await hashPassword(password) } });
  revalidatePath("/users");
  return { ok: true };
}

export async function deleteUser(id: string): Promise<AdminResult> {
  const { acting, err } = await requireUserAdmin();
  if (err || !acting) return { ok: false, error: err ?? "Ditolak." };
  if (acting.id === id) return { ok: false, error: "Tidak bisa menghapus akun sendiri." };

  const target = await prisma.user.findUnique({ where: { id } });
  if (!target) return { ok: false, error: "User tidak ditemukan." };
  // Non-sysadmin tak boleh menyentuh user SYSADMIN (seolah tak ada).
  if (acting.role !== "SYSADMIN" && target.role === "SYSADMIN") return { ok: false, error: "User tidak ditemukan." };
  if (target.role === "SYSADMIN") {
    const sysAktif = await prisma.user.count({ where: { role: "SYSADMIN", isActive: true } });
    if (sysAktif <= 1) return { ok: false, error: "Tidak bisa menghapus sysadmin terakhir." };
  }

  // Ada relasi (dokumen/PIC/dll)? Hard delete bisa melanggar FK → nonaktifkan saja.
  const linked = await prisma.document.count({
    where: { OR: [{ picId: id }, { uploadedById: id }] },
  }).catch(() => 0);
  if (linked > 0) {
    await prisma.user.update({ where: { id }, data: { isActive: false } });
    revalidatePath("/users");
    return { ok: false, error: "User punya riwayat dokumen — akun DINONAKTIFKAN (bukan dihapus) demi jejak audit." };
  }

  await prisma.user.delete({ where: { id } });
  revalidatePath("/users");
  return { ok: true };
}
