import { withAuth } from "next-auth/middleware";

// Halaman login pakai path LENGKAP /sales/login — middleware membangun URL
// redirect dari origin TANPA basePath, jadi default /api/auth/signin akan
// 404 di root domain.
export default withAuth({ pages: { signIn: "/sales/login" } });

// Protect all app routes; auth/login, static assets, and the machine-to-machine
// demographic sync (guarded by its own bearer token) are excluded.
export const config = {
  matcher: [
    "/((?!api/auth|api/sso|api/demographic|login|_next/static|_next/image|favicon.ico).*)",
  ],
};
