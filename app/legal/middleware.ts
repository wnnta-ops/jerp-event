import { NextRequest, NextResponse } from "next/server";
import { SESSION_COOKIE, verifySession } from "@/lib/session";

// Public (no auth) paths. The external-approval callback is machine-to-machine
// (the separate GM → Direktur → Owner app) — it is protected by a shared secret,
// not a session, so it must skip the login gate.
const PUBLIC = ["/login", "/register", "/api/external-approval", "/api/sso"];

export async function middleware(req: NextRequest) {
  const { pathname } = req.nextUrl;
  if (PUBLIC.some((p) => pathname === p || pathname.startsWith(p + "/"))) {
    return NextResponse.next();
  }
  const token = req.cookies.get(SESSION_COOKIE)?.value;
  const uid = token ? await verifySession(token) : null;
  if (!uid) {
    const url = req.nextUrl.clone();
    url.pathname = "/login";
    url.search = "";
    return NextResponse.redirect(url);
  }
  return NextResponse.next();
}

// Protect everything except Next internals + favicon. This also gates the
// /sample placeholder PDFs and /api routes (those still do their own checks).
export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
