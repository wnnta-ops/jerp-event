import { redirect } from "next/navigation";
import type { Role } from "@prisma/client";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { ROLE_LABEL } from "@/lib/constants";
import { canManageUsers, assignableRoles } from "@/lib/user-access";
import { UsersAdmin } from "@/components/users/UsersAdmin";

export const dynamic = "force-dynamic";

export default async function UsersPage() {
  const session = (await getSession())!;
  const role = session.user.role as Role;
  if (!canManageUsers(role)) redirect("/");

  const isSys = role === "SYSADMIN";
  const users = await prisma.user.findMany({
    where: isSys ? {} : { role: { not: "SYSADMIN" } },
    orderBy: { name: "asc" },
    select: { id: true, name: true, email: true, role: true },
  });

  const roleOpts = assignableRoles(role).map((r) => ({ value: r, label: ROLE_LABEL[r] }));

  return (
    <UsersAdmin
      initialUsers={users}
      roles={roleOpts}
      actingId={session.user.id}
      canSeeSysadmin={isSys}
    />
  );
}
