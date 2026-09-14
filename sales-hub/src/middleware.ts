export { default } from "next-auth/middleware";

// Protect all app routes; auth/login, static assets, and the machine-to-machine
// demographic sync (guarded by its own bearer token) are excluded.
export const config = {
  matcher: [
    "/((?!api/auth|api/demographic|login|_next/static|_next/image|favicon.ico).*)",
  ],
};
