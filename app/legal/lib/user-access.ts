/**
 * Siapa yang boleh membuka User Management — SATU tempat untuk diperluas nanti
 * bila akses mau diberikan ke role lain. Sekarang: hanya SYSADMIN (IT).
 *
 * Modul biasa (BUKAN "use server") supaya bisa dipakai server component & action.
 */
export function canManageUsers(role: string): boolean {
  return role === "SYSADMIN";
}
