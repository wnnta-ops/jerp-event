import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { getActingUser } from "@/lib/current-user";
import { canManageUsers } from "@/lib/user-access";
import { ROLES, ROLE_LABELS, type Role } from "@/lib/enums";
import { UsersAdmin } from "@/components/users-admin";

export const dynamic = "force-dynamic";

export default async function UsersPage() {
  const acting = await getActingUser();
  if (!acting || !canManageUsers(acting.role)) redirect("/");

  const isSysadmin = acting.role === "SYSADMIN";

  // Non-sysadmin: user & role SYSADMIN disembunyikan sepenuhnya.
  const users = await prisma.user.findMany({
    where: isSysadmin ? {} : { role: { not: "SYSADMIN" } },
    orderBy: [{ isActive: "desc" }, { name: "asc" }],
    select: { id: true, name: true, email: true, role: true, department: true, isActive: true },
  });

  // Peran yang boleh ditetapkan — SYSADMIN hanya untuk sysadmin.
  const assignableRoles = (ROLES as readonly Role[])
    .filter((r) => isSysadmin || r !== "SYSADMIN")
    .map((r) => ({ value: r, label: ROLE_LABELS[r] }));

  return (
    <main className="mx-auto w-full max-w-[1040px] px-5 py-6 sm:px-8 lg:px-10">
      <UsersAdmin
        users={users}
        roles={assignableRoles}
        actingId={acting.id}
        canSeeSysadmin={isSysadmin}
      />
    </main>
  );
}
