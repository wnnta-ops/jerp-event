import Link from "next/link";
import { getSession } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dashboardKpis } from "@/lib/derivations";
import { getFormOptions } from "@/lib/options";
import { wibToday } from "@/lib/wib";
import { NewWalkInButton } from "@/components/forms/NewWalkInButton";
import { LeadBadge } from "@/components/badges";
import { can } from "@/lib/rbac";

export const dynamic = "force-dynamic";

export default async function DashboardPage() {
  const session = (await getSession())!;
  const role = session.user.role;
  const today = wibToday();

  const [kpi, options, hotLeads, warmCount, collCount] = await Promise.all([
    dashboardKpis(today),
    getFormOptions(),
    prisma.deal.findMany({
      where: { statusLead: "HOT", batal: false },
      select: { id: true, nama: true, catatan: true, cluster: { select: { name: true } } },
      take: 6,
    }),
    prisma.deal.count({ where: { statusLead: "WARM", batal: false } }),
    prisma.deal.count({ where: { stage: { in: ["ACC", "AKAD"] }, batal: false } }),
  ]);

  const sisaLabel = kpi.clusterSisa.map((c) => `${c.cluster.replace("Cluster ", "C")}:${c.sisa}`).join(" · ");
  const encouragement =
    kpi.monthPct >= 100
      ? `UTJ net bulan ini ${kpi.monthNet} dari target ${kpi.monthTarget} (${kpi.monthPct}%). Momentum bagus — jaga follow-up lead Hot & Warm biar nggak nyangkut.`
      : `UTJ net bulan ini ${kpi.monthNet} dari target ${kpi.monthTarget} (${kpi.monthPct}%). Sisa ${kpi.sisaHari} hari — dorong lead Hot & Warm untuk closing.`;

  const canInput = can.inputSalesStages(role);

  return (
    <>
      <div className="hero">
        <div>
          <h2>Halo, Tim Sales 👋</h2>
          <p>{encouragement}</p>
          <p style={{ opacity: 0.88, fontStyle: "italic", marginTop: 6, fontSize: 12.5 }}>
            &ldquo;Follow-up yang konsisten hari ini menentukan closing minggu depan.&rdquo;
          </p>
        </div>
        <div className="em">📊</div>
      </div>

      <div className="flow2">
        <div className="fzone">
          <div className="fzlbl">Diisi Sales · klik untuk mengisi</div>
          <div className="fsteps">
            <Link className="fstep sa" href="/input">
              <span className="fn">1</span>
              <span className="ft2">WI</span>
              <span className="fsub">isi →</span>
            </Link>
            <Link className="fstep sa" href="/pipeline">
              <span className="fn">2</span>
              <span className="ft2">Follow-up</span>
              <span className="fsub">isi →</span>
            </Link>
            <Link className="fstep sa" href="/pipeline">
              <span className="fn">3</span>
              <span className="ft2">NUP</span>
              <span className="fsub">isi →</span>
            </Link>
            <Link className="fstep sa" href="/stock">
              <span className="fn">4</span>
              <span className="ft2">UTJ</span>
              <span className="fsub">isi →</span>
            </Link>
            <Link className="fstep sa" href="/pipeline">
              <span className="fn">5</span>
              <span className="ft2">SPR</span>
              <span className="fsub">isi →</span>
            </Link>
          </div>
        </div>
        <div className="fdiv">»</div>
        <div className="fzone">
          <div className="fzlbl">Diisi Tim Collection</div>
          <div className="fsteps">
            <div className="fstep co">
              <span className="fn">6</span>
              <span className="ft2">ACC</span>
              <span className="fsub">tim Collection</span>
            </div>
            <div className="fstep co">
              <span className="fn">7</span>
              <span className="ft2">Akad</span>
              <span className="fsub">tim Collection</span>
            </div>
            <div className="fstep co">
              <span className="fn">8</span>
              <span className="ft2">Realisasi</span>
              <span className="fsub">tim Collection</span>
            </div>
          </div>
        </div>
      </div>

      <div className="grid g4">
        <div className="card kpi tint k-teal">
          <div className="lab">Target 2026</div>
          <div className="val">{kpi.annualTarget}</div>
          <div className="meta">
            SPR YTD {kpi.sprYtd} · {kpi.annualPct}%
          </div>
        </div>
        <div className="card kpi tint k-coral">
          <div className="lab">UTJ Net Bulan Ini</div>
          <div className="val">{kpi.monthNet}</div>
          <div className="meta">
            target {kpi.monthTarget} · {kpi.monthPct}%
          </div>
        </div>
        <div className="card kpi tint k-orange">
          <div className="lab">Run-rate perlu</div>
          <div className="val">{kpi.runRate}</div>
          <div className="meta">unit/hari (sisa {kpi.remainingAnnual})</div>
        </div>
        <div className="card kpi tint k-main">
          <div className="lab">Sisa stock</div>
          <div className="val">{kpi.sisaStockTotal}</div>
          <div className="meta">{sisaLabel}</div>
        </div>
      </div>

      <div className="grid g4" style={{ marginTop: 16 }}>
        <div className="card kpi">
          <div className="lab">Hold unit</div>
          <div className="val">{kpi.hold}</div>
        </div>
        <div className="card kpi">
          <div className="lab">Waiting list</div>
          <div className="val">{kpi.waitingList}</div>
        </div>
        <div className="card kpi">
          <div className="lab">Sales potensial</div>
          <div className="val">{kpi.salesPotensial}</div>
          <div className="meta">lead hot</div>
        </div>
        <div className="card kpi">
          <div className="lab">Sisa hari</div>
          <div className="val">{kpi.sisaHari}</div>
          <div className="meta">bulan ini</div>
        </div>
      </div>

      <div className="grid g2" style={{ marginTop: 16 }}>
        <div className="card">
          <div className="pad">
            <h3>📄 Daily report siap kirim</h3>
            <p style={{ fontSize: 12.5, color: "var(--sub)", marginBottom: 12 }}>
              Recap divisi + progress target, auto jadi gambar untuk WA.
            </p>
            <Link className="btn wa" href="/daily">
              🖼️ Buka & download gambar WA
            </Link>
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>🔥 Perlu tindakan hari ini</h3>
            {hotLeads.length === 0 && (
              <div className="rolehint">Belum ada lead Hot. Tambah Walk-In baru:</div>
            )}
            {hotLeads.map((c) => (
              <Link key={c.id} className="lcard" href="/pipeline" style={{ display: "block" }}>
                <div className="nm">
                  {c.nama} <LeadBadge t="HOT" />
                </div>
                <div className="mt">
                  {c.cluster?.name ?? "-"} · {c.catatan ?? "perlu follow-up"}
                </div>
              </Link>
            ))}
            <div className="mt" style={{ marginTop: 8 }}>
              + {warmCount} warm · {collCount} di proses collection
            </div>
            {canInput && (
              <div style={{ marginTop: 12 }}>
                <NewWalkInButton options={options} label="＋ Walk-In baru" />
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="notice info" style={{ marginTop: 16 }}>
        <span>💡</span>
        <div>
          <b>Alur:</b> buka <b>Input Harian</b> → catat WI (Site/Pameran/Janjian), NUP, UTJ, SPR.
          Otomatis masuk <b>Pipeline</b> &amp; jadi <b>Daily Report</b>. Sales mengisi s/d SPR;
          Collection melanjutkan ACC → Realisasi.
        </div>
      </div>
    </>
  );
}
