import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { RotasiClient, type CoordGroup } from "@/components/rotasi/RotasiClient";
import { can } from "@/lib/rbac";
import { ROLE_LABEL } from "@/lib/constants";

export const dynamic = "force-dynamic";

export default async function RotasiPage() {
  const session = (await getSession())!;
  const role = session.user.role;
  const canEdit = can.editRotation(role);

  const [coordinators, agencies, current] = await Promise.all([
    prisma.coordinator.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.agency.findMany({ orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.rotationAssignment.findMany({
      where: { effectiveTo: null },
      select: {
        coordinatorId: true,
        coordinator: { select: { name: true } },
        agency: { select: { name: true } },
      },
    }),
  ]);

  const map = new Map<string, CoordGroup>();
  for (const c of coordinators) map.set(c.id, { id: c.id, name: c.name, agencies: [] });
  for (const r of current) {
    const g = map.get(r.coordinatorId);
    if (g) g.agencies.push(r.agency.name);
  }
  for (const g of map.values()) g.agencies.sort();

  return (
    <>
      <div className="h2">
        Rotasi Koordinator &amp; Bendera <span className="ft">Fitur 6</span>
      </div>
      <div className="lead">
        Koordinator ↔ bendera agen. Perubahan tersimpan dengan histori tanggal (report lama tetap
        akurat).
      </div>

      <div className={`notice ${canEdit ? "info" : ""}`}>
        <span>{canEdit ? "✏️" : "🔒"}</span>
        <div>
          {canEdit ? (
            <>
              <b>Sales / Koordinator:</b> bisa <b>edit</b> bendera.
            </>
          ) : (
            <>
              <b>{ROLE_LABEL[role]}:</b> <b>view-only</b>.
            </>
          )}
        </div>
      </div>

      <RotasiClient
        groups={[...map.values()]}
        coordinators={coordinators}
        agencies={agencies}
        canEdit={canEdit}
      />
    </>
  );
}
