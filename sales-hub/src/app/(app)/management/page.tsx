import { getSession } from "@/lib/auth";
import { getReminderData, getTargetData, getUsers } from "@/lib/management";
import { ManagementClient } from "@/components/management/ManagementClient";

export const dynamic = "force-dynamic";

export default async function ManagementPage() {
  const session = (await getSession())!;
  const role = session.user.role;
  const isMgmt = role === "MANAGEMENT";
  const canReminder = role === "MANAGEMENT" || role === "SALES";

  if (!canReminder) {
    return (
      <>
        <div className="h2">Management</div>
        <div className="notice">
          <span>🔒</span>
          <div>Modul ini khusus <b>Sales Supervisor (Management)</b>.</div>
        </div>
      </>
    );
  }

  const reminder = await getReminderData();
  const [targets, users] = isMgmt
    ? await Promise.all([getTargetData(), getUsers()])
    : [null, null];

  return (
    <>
      <div className="h2">
        Management <span className="ft">Sales Supervisor</span>
      </div>
      <div className="lead">
        Pusat pengaturan: reminder follow-up, target, akun &amp; role, master data, regulasi akses.
      </div>
      <ManagementClient
        role={role}
        selfId={session.user.id}
        reminder={reminder}
        targets={targets}
        users={users}
      />
    </>
  );
}
