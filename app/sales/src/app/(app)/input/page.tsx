import Link from "next/link";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dailyTally } from "@/lib/derivations";
import { getFormOptions } from "@/lib/options";
import { dayBounds, dayKey, wibToday } from "@/lib/wib";
import { NewWalkInButton } from "@/components/forms/NewWalkInButton";
import { WalkInRowActions } from "@/components/forms/WalkInRowActions";
import { ChannelBadge, LeadBadge, StageBadge } from "@/components/badges";
import { can } from "@/lib/rbac";

export const dynamic = "force-dynamic";

export default async function InputPage() {
  const session = (await getSession())!;
  const role = session.user.role;
  const canInput = can.inputSalesStages(role);
  const today = wibToday();
  const { gte, lt } = dayBounds(today);

  const [tally, options, todays] = await Promise.all([
    dailyTally(today),
    getFormOptions(),
    prisma.deal.findMany({
      where: { tanggalWi: { gte, lt } },
      orderBy: { createdAt: "desc" },
      select: {
        id: true,
        nama: true,
        tanggalWi: true,
        channel: true,
        stage: true,
        statusLead: true,
        catatan: true,
        sumberInformasi: true,
        agencyId: true,
        salesId: true,
        coordinatorId: true,
        clusterId: true,
        phaseId: true,
        unitTypeId: true,
        agency: { select: { name: true } },
        sales: { select: { name: true } },
        coordinator: { select: { name: true } },
        cluster: { select: { name: true } },
        unitType: { select: { name: true } },
      },
    }),
  ]);

  const chWi = { SITE: 0, PAMERAN: 0, JANJIAN: 0 } as Record<string, number>;
  for (const d of todays) chWi[d.channel]++;

  return (
    <>
      <div className="h2">Input Harian</div>
      <div className="lead">
        Catat aktivitas hari ini seperti di Excel. Sekali input → mengalir ke Pipeline, Daily
        Report, Summary, Stock.
      </div>

      <div className={`notice ${canInput ? "info" : ""}`}>
        <span>{canInput ? "✏️" : "🔒"}</span>
        <div>
          {canInput ? (
            <>
              <b>Sales / Koordinator:</b> di hub ini kamu cukup input <b>Walk-In</b>. NUP, UTJ, SPR,
              dan Batal <b>masuk otomatis dari sistem lain</b> (integrasi) — bukan diketik manual di
              sini — dan langsung dihitung ke angka di bawah.
            </>
          ) : (
            <>Mode lihat — input harian khusus Sales / Koordinator.</>
          )}
        </div>
      </div>

      <div className="actions">
        {canInput ? (
          <NewWalkInButton options={options} label="＋ Walk-In" />
        ) : (
          <button className="btn" disabled>
            ＋ Walk-In
          </button>
        )}
        <span className="pill" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
          🔌 NUP · UTJ · SPR · Batal — dari sistem lain (integrasi)
        </span>
        <span style={{ flex: 1 }} />
        <Link className="btn wa" href="/daily">
          Lihat Daily Report →
        </Link>
      </div>

      <div className="grid g5" style={{ marginBottom: 16 }}>
        <div className="card kpi">
          <div className="lab">WI</div>
          <div className="val">{tally.wi}</div>
          <div className="meta">
            {chWi.SITE} site · {chWi.PAMERAN} pam · {chWi.JANJIAN} janji
          </div>
        </div>
        <div className="card kpi">
          <div className="lab">NUP</div>
          <div className="val">{tally.nup}</div>
        </div>
        <div className="card kpi">
          <div className="lab">UTJ</div>
          <div className="val">{tally.utj}</div>
        </div>
        <div className="card kpi">
          <div className="lab">SPR</div>
          <div className="val">{tally.spr}</div>
        </div>
        <div className="card kpi">
          <div className="lab">Batal</div>
          <div className="val">{tally.batal}</div>
        </div>
      </div>

      <div className="card">
        <div className="pad">
          <h3>Walk-In tercatat hari ini</h3>
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Channel</th>
                  <th>Agent</th>
                  <th>Sales</th>
                  <th>Koor</th>
                  <th>Cluster</th>
                  <th>Type</th>
                  <th>Status</th>
                  {canInput && <th style={{ textAlign: "right" }}>Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {todays.length === 0 && (
                  <tr>
                    <td colSpan={canInput ? 9 : 8} style={{ color: "var(--sub)" }}>
                      Belum ada Walk-In hari ini.
                    </td>
                  </tr>
                )}
                {todays.map((c) => (
                  <tr key={c.id}>
                    <td>
                      <b>{c.nama}</b>
                    </td>
                    <td>
                      <ChannelBadge c={c.channel} />
                    </td>
                    <td>{c.agency.name}</td>
                    <td>{c.sales.name}</td>
                    <td>{c.coordinator?.name ?? "-"}</td>
                    <td>{c.cluster?.name ?? "-"}</td>
                    <td>{c.unitType?.name ?? "-"}</td>
                    <td>
                      {c.statusLead ? <LeadBadge t={c.statusLead} /> : <StageBadge stage={c.stage} />}
                    </td>
                    {canInput && (
                      <td style={{ textAlign: "right" }}>
                        <WalkInRowActions
                          deal={{
                            id: c.id,
                            nama: c.nama,
                            tanggalWi: dayKey(c.tanggalWi),
                            channel: c.channel,
                            agencyId: c.agencyId,
                            salesId: c.salesId,
                            coordinatorId: c.coordinatorId,
                            clusterId: c.clusterId,
                            phaseId: c.phaseId,
                            unitTypeId: c.unitTypeId,
                            statusLead: c.statusLead,
                            sumberInformasi: c.sumberInformasi,
                            catatan: c.catatan,
                          }}
                          options={options}
                        />
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </>
  );
}
