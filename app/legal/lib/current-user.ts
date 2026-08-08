import { cookies } from "next/headers";
import { prisma } from "./prisma";
import { SESSION_COOKIE, verifySession } from "./session";

/**
 * The acting user comes from a signed session cookie (email+password login).
 * Everything reads the acting user through here. Returns null if not logged in
 * (middleware redirects unauthenticated requests to /login).
 */
export async function getActingUser() {
  const token = cookies().get(SESSION_COOKIE)?.value;
  if (!token) return null;
  const uid = await verifySession(token);
  if (!uid) return null;
  const user = await prisma.user.findUnique({ where: { id: uid } });
  return user && user.isActive ? user : null;
}
