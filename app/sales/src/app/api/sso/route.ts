import { NextRequest, NextResponse } from "next/server";
import { createHmac, timingSafeEqual } from "crypto";
import { encode } from "next-auth/jwt";
import { prisma } from "@/lib/prisma";

export const dynamic = "force-dynamic";

// Nonce sekali-pakai (best-effort, in-memory): token exp 60 dtk, ini lapisan kedua.
const usedJti = new Map<string, number>();
function jtiSeen(jti: string): boolean {
  const now = Date.now();
  for (const [k, t] of usedJti) if (t < now) usedJti.delete(k);
  if (usedJti.has(jti)) return true;
  usedJti.set(jti, now + 120_000);
  return false;
}

/**
 * SSO dari portal J-ERP: /sso/to-sales menandatangani token HMAC (shared secret
 * JERP_SSO_SECRET) berisi email+name+jti+exp → verifikasi di sini, cocokkan user
 * by EMAIL (harus terdaftar di Sales Hub), lalu terbitkan session JWT NextAuth
 * (cookie yang sama dengan login credentials biasa).
 */
export async function GET(req: NextRequest) {
  // Origin PUBLIK dari header forwarded (di balik jerp-nginx req.url = localhost:3000).
  const proto = req.headers.get("x-forwarded-proto") ?? "http";
  const host = req.headers.get("x-forwarded-host") ?? req.headers.get("host") ?? "localhost:3000";
  const origin = `${proto}://${host}`;
  const fail = (reason: string) =>
    NextResponse.redirect(`${origin}/sales/login?sso=${reason}`);

  const token = req.nextUrl.searchParams.get("token") ?? "";
  const secret = process.env.JERP_SSO_SECRET ?? "";
  const dot = token.lastIndexOf(".");
  if (!secret || dot < 1) return fail("invalid");

  const payload = token.slice(0, dot);
  const sig = token.slice(dot + 1);
  const expect = createHmac("sha256", secret).update(payload).digest("hex");
  const a = Buffer.from(sig, "utf8");
  const b = Buffer.from(expect, "utf8");
  if (a.length !== b.length || !timingSafeEqual(a, b)) return fail("invalid");

  let data: { email?: string; name?: string; jti?: string; exp?: number };
  try {
    data = JSON.parse(Buffer.from(payload, "base64").toString("utf8"));
  } catch {
    return fail("invalid");
  }
  if (!data.email || !data.exp || data.exp < Math.floor(Date.now() / 1000)) return fail("expired");
  if (!data.jti || jtiSeen(data.jti)) return fail("replay");

  const user = await prisma.user.findUnique({
    where: { email: data.email.toLowerCase() },
  });
  if (!user) return fail("unknown"); // akun belum terdaftar di Sales Hub

  // Bentuk JWT persis klaim callback jwt() di lib/auth.ts.
  const nextAuthSecret = process.env.NEXTAUTH_SECRET ?? "";
  const session = await encode({
    token: {
      name: user.name,
      email: user.email,
      sub: user.id,
      uid: user.id,
      role: user.role,
      salesId: user.salesId ?? null,
    },
    secret: nextAuthSecret,
    maxAge: 60 * 60 * 12, // 12 jam
  });

  const secure = (process.env.NEXTAUTH_URL ?? "").startsWith("https://");
  const cookieName = secure ? "__Secure-next-auth.session-token" : "next-auth.session-token";

  const res = NextResponse.redirect(`${origin}/sales`);
  res.cookies.set(cookieName, session, {
    httpOnly: true,
    sameSite: "lax",
    secure,
    path: "/",
    maxAge: 60 * 60 * 12,
  });
  return res;
}
