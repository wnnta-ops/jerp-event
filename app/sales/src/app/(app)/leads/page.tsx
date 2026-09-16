import { getSession } from "@/lib/auth";
import { getFormOptions } from "@/lib/options";
import { getLeads, getLeadSummary, getMyCoordinatorId } from "@/lib/leads";
import { LeadsClient } from "@/components/leads/LeadsClient";

export const dynamic = "force-dynamic";

export default async function LeadsPage() {
  const session = (await getSession())!;
  const role = session.user.role;
  const isManager = role === "MANAGEMENT" || role === "SYSADMIN";

  // Scoping privasi: Management/IT lihat semua; Sales Koor hanya lead miliknya.
  let scopeCoordId: string | null = null; // null = semua (khusus manager)
  let myKoor: string | null = null;
  if (!isManager) {
    const cid = await getMyCoordinatorId(session.user.id, session.user.name ?? "");
    if (!cid) {
      // Akun bukan Sales Koor (atau belum ditautkan) → tidak boleh lihat lead siapa pun.
      return (
        <>
          <div className="h2">Leads</div>
          <div className="notice">
            <span>🔒</span>
            <div>
              Modul <b>Leads</b> ditujukan untuk <b>Sales Koordinator</b>. Akun kamu belum
              tertaut ke data koordinator, jadi tidak ada lead yang bisa ditampilkan. Hubungi
              Management untuk menautkan akun ke Sales Koor kamu.
            </div>
          </div>
        </>
      );
    }
    scopeCoordId = cid;
    myKoor = session.user.name ?? null;
  }

  const [leads, summary, options] = await Promise.all([
    getLeads(scopeCoordId),
    getLeadSummary(scopeCoordId),
    getFormOptions(),
  ]);

  return (
    <LeadsClient
      leads={leads}
      summary={summary}
      options={options}
      isManager={isManager}
      myKoor={myKoor}
    />
  );
}
