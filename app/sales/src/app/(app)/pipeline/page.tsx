import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { getFormOptions } from "@/lib/options";
import { getMyCoordinatorId } from "@/lib/leads";
import { NewWalkInButton } from "@/components/forms/NewWalkInButton";
import { PipelineClient, type PipelineDeal } from "@/components/pipeline/PipelineClient";
import { can } from "@/lib/rbac";
import type { Prisma } from "@prisma/client";

export const dynamic = "force-dynamic";

export default async function PipelinePage() {
  const session = (await getSession())!;
  const role = session.user.role;

  // Scoping: Sales Koor biasa (role SALES) hanya lihat customer koornya sendiri.
  // Supervisor (MANAGEMENT), SYSADMIN, & Collection lihat semua.
  let scopeWhere: Prisma.DealWhereInput = {};
  if (role === "SALES") {
    const coordId = await getMyCoordinatorId(session.user.id, session.user.name ?? "");
    scopeWhere = coordId
      ? { coordinatorId: coordId }
      : session.user.salesId
        ? { salesId: session.user.salesId }
        : { id: "__none__" }; // tidak tertaut → tidak ada yang ditampilkan
  }

  const deadCutoff = new Date(Date.now() - 30 * 86400_000);
  const select = {
    id: true,
    nama: true,
    channel: true,
    stage: true,
    statusLead: true,
    catatan: true,
    agency: { select: { name: true } },
    sales: { select: { name: true } },
    coordinator: { select: { name: true } },
    cluster: { select: { name: true } },
    unitType: { select: { name: true } },
    unit: { select: { code: true } },
    bank: { select: { name: true } },
  } as const;

  const [rows, deadRows, options] = await Promise.all([
    prisma.deal.findMany({
      where: { batal: false, ...scopeWhere },
      orderBy: { updatedAt: "desc" },
      take: 2000,
      select,
    }),
    // recent not-interested → shown as red cards in their last stage column
    prisma.deal.findMany({
      where: { batal: true, batalAlasan: "TIDAK_BERMINAT", batalTanggal: { gte: deadCutoff }, ...scopeWhere },
      orderBy: { batalTanggal: "desc" },
      take: 300,
      select,
    }),
    getFormOptions(),
  ]);

  const toDeal = (d: (typeof rows)[number], dead = false): PipelineDeal => ({
    id: d.id,
    nama: d.nama,
    channel: d.channel,
    stage: d.stage,
    statusLead: d.statusLead,
    agency: d.agency.name,
    sales: d.sales.name,
    koor: d.coordinator?.name ?? "-",
    cluster: d.cluster?.name ?? "-",
    type: d.unitType?.name ?? "-",
    unit: d.unit?.code ?? null,
    bank: d.bank?.name ?? null,
    catatan: d.catatan,
    dead,
  });
  const deals: PipelineDeal[] = [...rows.map((d) => toDeal(d)), ...deadRows.map((d) => toDeal(d, true))];

  return (
    <>
      <div className="h2">Customer Pipeline</div>
      <div className="lead">
        1 customer = 1 record, jalan 8 tahap. Kolom bertanda <b>Sales</b> (0–4, s/d SPR) &amp;{" "}
        <b>Collection</b> (5–6: ACC → Akad) &amp; <b>Legal</b> (7: AJB). Semua tahap ditampilkan — pakai filter
        Tahap / Sales / Koor untuk menyaring.
      </div>
      <div className="actions">
        {can.inputSalesStages(role) ? (
          <NewWalkInButton options={options} label="＋ Customer baru (Walk-In)" />
        ) : (
          <button className="btn" disabled>
            ＋ Customer baru (Walk-In)
          </button>
        )}
      </div>
      <PipelineClient deals={deals} role={role} />
    </>
  );
}
