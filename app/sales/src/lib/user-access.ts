import type { Role } from "@prisma/client";

/**
 * Siapa yang boleh membuka User Management — SATU tempat untuk diperluas nanti.
 * Sekarang: hanya SYSADMIN (IT). Non-sysadmin: user & role SYSADMIN disembunyikan.
 */
export function canManageUsers(role: Role): boolean {
  return role === "SYSADMIN";
}

/** Peran yang boleh ditetapkan oleh aktor (SYSADMIN hanya utk sysadmin). */
export const ALL_ROLES: Role[] = ["SALES", "COLLECTION", "MANAGEMENT", "SYSADMIN"];
export function assignableRoles(actorRole: Role): Role[] {
  return actorRole === "SYSADMIN" ? ALL_ROLES : ALL_ROLES.filter((r) => r !== "SYSADMIN");
}
