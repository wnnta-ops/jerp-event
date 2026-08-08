import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { CollectionClient, type CollDeal } from "@/components/collection/CollectionClient";
import { ROLE_LABEL } from "@/lib/constants";

export const dynamic = "force-dynamic";

export default async function CollectionPage() {
  const session = (await getSession())!;
  const role = session.user.role;

  const rows = await prisma.deal.findMany({
    where: { batal: false, stage: { in: ["UTJ", "SPR", "ACC", "AKAD"] } },
    orderBy: { updatedAt: "desc" },
    take: 400,
    select: {
      id: true,
      nama: true,
      stage: true,
      catatan: true,
      cluster: { select: { name: true } },
      unitType: { select: { name: true } },
      sales: { select: { name: true } },
      bank: { select: { name: true } },
      unit: { select: { code: true } },
    },
  });

  const deals: CollDeal[] = rows.map((d) => ({
    id: d.id,
    nama: d.nama,
    stage: d.stage,
    unit: d.unit?.code ?? null,
    cluster: d.cluster?.name ?? "-",
    type: d.unitType?.name ?? "-",
    sales: d.sales.name,
    bank: d.bank?.name ?? null,
    catatan: d.catatan,
  }));

  const isColl = role === "COLLECTION";

  return (
    <>
      <div className="h2">
        Collection — Progress Akad <span className="ft">Fitur 7</span>
      </div>
      <div className="lead">
        Antrian dari UTJ/SPR. Collection update: ACC bank → Akad → Realisasi (atau Batal + refund).
      </div>

      <div className={`notice ${isColl ? "info" : ""}`}>
        <span>{isColl ? "✏️" : "🔒"}</span>
        <div>
          {isColl ? (
            <>
              <b>Collection:</b> update ACC → Akad → Realisasi.
            </>
          ) : (
            <>
              <b>{ROLE_LABEL[role]}:</b> mode <b>lihat</b>
              {role === "MANAGEMENT" ? " (bisa update juga)." : "."}
            </>
          )}
        </div>
      </div>

      <CollectionClient deals={deals} role={role} />
    </>
  );
}
