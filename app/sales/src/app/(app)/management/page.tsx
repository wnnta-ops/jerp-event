import { getSession } from "@/lib/auth";
import { getReminderData, getTargetData } from "@/lib/management";
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
  const [targets] = isMgmt
    ? await Promise.all([getTargetData()])
    : [null, null];

  return (
    <>
      <div className="h2">
        Management <span className="ft">Sales Supervisor</span>
      </div>
      <div className="lead">
        Pusat pengaturan: reminder follow-up, target, master data, regulasi akses. (Akun &amp; role diatur di menu Manajemen User.)
      </div>
      <ManagementClient
        role={role}
        selfId={session.user.id}
        reminder={reminder}
        targets={targets}
      />
    </>
  );
}
