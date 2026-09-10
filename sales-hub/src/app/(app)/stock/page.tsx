import type { Prisma, UnitStatus } from "@prisma/client";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { statusCounts } from "@/lib/derivations";
import { getFormOptions } from "@/lib/options";
import { StockClient, type StockRow } from "@/components/stock/StockClient";
import { UNIT_STATUS_LABEL } from "@/lib/constants";
import { can } from "@/lib/rbac";

export const dynamic = "force-dynamic";

export default async function StockPage({
  searchParams,
}: {
  searchParams: Promise<{ cluster?: string; phase?: string; status?: string; q?: string }>;
}) {
  const session = (await getSession())!;
  const role = session.user.role;
  const sp = await searchParams;
  const cluster = sp.cluster ?? "all";
  const phase = sp.phase ?? "all";
  const status = sp.status ?? "all";
  const q = sp.q ?? "";

  const where: Prisma.UnitWhereInput = {};
  if (cluster !== "all") where.phase = { cluster: { name: cluster } };
  if (phase !== "all") where.phase = { ...(where.phase as object), name: phase };
  if (status !== "all") where.status = status as UnitStatus;
  if (q) {
    where.OR = [
      { code: { contains: q, mode: "insensitive" } },
      { unitType: { name: { contains: q, mode: "insensitive" } } },
    ];
  }

  const [units, counts, options, clusters] = await Promise.all([
    prisma.unit.findMany({
      where,
      take: 250,
      orderBy: { code: "asc" },
      select: {
        id: true,
        code: true,
        status: true,
        currentDealId: true,
        harga: true,
        phase: { select: { name: true, cluster: { select: { name: true } } } },
        unitType: { select: { name: true } },
        deals: {
          select: {
            id: true,
            nama: true,
            caraBayar: true,
            sales: { select: { name: true } },
            agency: { select: { name: true } },
          },
        },
      },
    }),
    statusCounts(),
    getFormOptions(),
    prisma.cluster.findMany({
      orderBy: { name: "asc" },
      select: { name: true, phases: { select: { name: true } } },
    }),
  ]);

  const rows: StockRow[] = units.map((u) => {
    const cur = u.deals.find((d) => d.id === u.currentDealId) ?? null;
    return {
      id: u.id,
      code: u.code,
      cluster: u.phase.cluster.name,
      phase: u.phase.name,
      type: u.unitType.name,
      status: u.status,
      user: cur?.nama ?? null,
      sales: cur?.sales.name ?? null,
      agent: cur?.agency.name ?? null,
      bayar: cur?.caraBayar ?? null,
      harga: u.harga ? u.harga.toString() : null,
      hasBooking: !!u.currentDealId,
    };
  });

  const kpi = [
    ["Open stock", counts["OPEN_STOCK"] ?? 0],
    ["Proses", counts["PROSES"] ?? 0],
    ["Hold", counts["HOLD"] ?? 0],
    ["Realisasi/Lunas", (counts["REALISASI"] ?? 0) + (counts["CASH_LUNAS"] ?? 0)],
  ] as const;

  return (
    <>
      <div className="h2">
        Stock Unit Rumah <span className="ft">Fitur 3</span>
      </div>
      <div className="lead">
        Per cluster · phase · type · block. UTJ mengunci unit; batal membukanya lagi.
      </div>

      <div className="grid g4" style={{ marginBottom: 16 }}>
        {kpi.map(([lab, val]) => (
          <div className="card kpi" key={lab}>
            <div className="lab">{lab}</div>
            <div className="val">{val}</div>
          </div>
        ))}
      </div>

      <StockClient
        rows={rows}
        options={options}
        role={role}
        canEdit={can.editStock(role)}
        filters={{ cluster, phase, status, q }}
        clusters={clusters.map((c) => ({ name: c.name, phases: c.phases.map((p) => p.name) }))}
      />

      <p className="rolehint" style={{ marginTop: 10 }}>
        Menampilkan maks. 250 unit per filter. Status label: {Object.values(UNIT_STATUS_LABEL).join(" · ")}.
      </p>
    </>
  );
}
