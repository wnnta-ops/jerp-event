import { NextResponse } from "next/server";
import type { Role } from "@prisma/client";
import { getSession } from "./auth";

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
  }
}

export async function requireSession() {
  const session = await getSession();
  if (!session?.user) throw new ApiError(401, "Tidak terautentikasi.");
  return session;
}

export function requireRole(role: Role, allowed: Role[]) {
  // SYSADMIN (IT) melewati semua gerbang role — akses & wewenang penuh.
  if (role === "SYSADMIN") return;
  if (!allowed.includes(role)) throw new ApiError(403, "Tidak punya akses untuk aksi ini.");
}

export function ok(data: unknown = { ok: true }) {
  return NextResponse.json(data);
}

export function handleError(e: unknown) {
  if (e instanceof ApiError) {
    return NextResponse.json({ error: e.message }, { status: e.status });
  }
  console.error(e);
  return NextResponse.json({ error: "Terjadi kesalahan server." }, { status: 500 });
}
