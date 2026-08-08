import { cookies } from "next/headers";
import bcrypt from "bcryptjs";
import { prisma } from "./prisma";
import { signSession, SESSION_COOKIE } from "./session";

export async function hashPassword(plain: string): Promise<string> {
  return bcrypt.hash(plain, 10);
}

function setSessionCookie(token: string) {
  cookies().set(SESSION_COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge: 60 * 60 * 24 * 7, // 7 days
  });
}

export async function login(email: string, password: string): Promise<{ ok: boolean; error?: string }> {
  const user = await prisma.user.findUnique({ where: { email: email.toLowerCase().trim() } });
  if (!user || !user.passwordHash) return { ok: false, error: "Email atau kata sandi salah." };
  if (!user.isActive) return { ok: false, error: "Akun nonaktif. Hubungi admin." };
  const ok = await bcrypt.compare(password, user.passwordHash);
  if (!ok) return { ok: false, error: "Email atau kata sandi salah." };
  setSessionCookie(await signSession(user.id));
  return { ok: true };
}

/** Self-service registration — new accounts get the least-privilege REQUESTER role. */
export async function register(name: string, email: string, password: string, department?: string): Promise<{ ok: boolean; error?: string }> {
  const e = email.toLowerCase().trim();
  if (!name.trim()) return { ok: false, error: "Nama wajib diisi." };
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)) return { ok: false, error: "Email tidak valid." };
  if (password.length < 8) return { ok: false, error: "Kata sandi minimal 8 karakter." };
  const existing = await prisma.user.findUnique({ where: { email: e } });
  if (existing) return { ok: false, error: "Email sudah terdaftar." };
  const initials = name.trim().split(/\s+/).map((w) => w[0]).join("").slice(0, 2).toUpperCase();
  const user = await prisma.user.create({
    data: { name: name.trim(), email: e, role: "REQUESTER", department: department?.trim() || null, initials, passwordHash: await hashPassword(password) },
  });
  setSessionCookie(await signSession(user.id));
  return { ok: true };
}

export async function logout() {
  cookies().delete(SESSION_COOKIE);
}
