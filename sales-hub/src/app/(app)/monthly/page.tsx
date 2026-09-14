import { dashboardKpis, phaseSummary, teamNet } from "@/lib/derivations";
import { MonthlyChart } from "@/components/report/MonthlyChart";
import { wibToday } from "@/lib/wib";

export const dynamic = "force-dynamic";

export default async function MonthlyPage() {
  const today = wibToday();
  const [kpi, teams, phases] = await Promise.all([
    dashboardKpis(today),
    teamNet(today.getUTCFullYear(), today.getUTCMonth()),
    phaseSummary(),
  ]);

  const totalTarget = teams.reduce((s, t) => s + t.target, 0);
  const totalUtj = teams.reduce((s, t) => s + t.utj, 0);
  const totalNet = teams.reduce((s, t) => s + t.net, 0);

  return (
    <>
      <div className="h2">
        Monthly / Yearly Report <span className="ft">Fitur 2</span>
      </div>
      <div className="lead">Rekap otomatis + progress target tahunan {kpi.annualTarget} unit.</div>

      <div className="grid g2" style={{ marginBottom: 16 }}>
        <div className="card">
          <div className="pad">
            <h3>Grafik UTJ Net</h3>
            <MonthlyChart />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Progress Target 2026</h3>
            <div style={{ textAlign: "center", padding: "6px 0" }}>
              <div style={{ fontSize: 44, fontWeight: 800, color: "var(--teal)" }}>{kpi.annualPct}%</div>
              <div style={{ color: "var(--sub)", fontSize: 13, marginBottom: 12 }}>
                {kpi.sprYtd} / {kpi.annualTarget} unit (SPR YTD)
              </div>
              <div className="bar-track" style={{ height: 12 }}>
                <div className="bar-fill" style={{ width: `${kpi.annualPct}%`, background: "var(--teal)" }} />
              </div>
              <div style={{ display: "flex", justifyContent: "space-between", marginTop: 14, fontSize: 12.5, color: "var(--sub)" }}>
                <span>
                  Sisa: <b>{kpi.remainingAnnual}</b> unit
                </span>
                <span>
                  Run-rate: <b>{kpi.runRate}</b>/hari
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="grid g2">
        <div className="card">
          <div className="pad">
            <h3>Rekap per tim (bulan ini)</h3>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>Tim</th>
                    <th>Target</th>
                    <th>UTJ</th>
                    <th>Net</th>
                    <th>%</th>
                  </tr>
                </thead>
                <tbody>
                  {teams.map((t) => (
                    <tr key={t.tim}>
                      <td>
                        <b>{t.tim}</b>
                      </td>
                      <td>{t.target}</td>
                      <td>{t.utj}</td>
                      <td>{t.net}</td>
                      <td>
                        <span className={t.target && t.net >= t.target ? "up" : "down"}>
                          {t.target ? Math.round((t.net / t.target) * 100) : 0}%
                        </span>
                      </td>
                    </tr>
                  ))}
                  <tr>
                    <td>
                      <b>Total</b>
                    </td>
                    <td>
                      <b>{totalTarget}</b>
                    </td>
                    <td>
                      <b>{totalUtj}</b>
                    </td>
                    <td>
                      <b>{totalNet}</b>
                    </td>
                    <td>
                      <b className="up">{totalTarget ? Math.round((totalNet / totalTarget) * 100) : 0}%</b>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p className="rolehint" style={{ marginTop: 8 }}>
              Pembagian tim mengikuti master data sales (kelola di admin). Sales tanpa tim masuk
              &ldquo;Tanpa Tim&rdquo;.
            </p>
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Stock per cluster × phase</h3>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>Cluster</th>
                    <th>Phase</th>
                    <th>Total</th>
                    <th>Proses</th>
                    <th>Sisa</th>
                  </tr>
                </thead>
                <tbody>
                  {phases.map((s) => (
                    <tr key={`${s.cluster}-${s.phase}`}>
                      <td>{s.cluster}</td>
                      <td>{s.phase}</td>
                      <td>{s.total}</td>
                      <td>{s.proses || "-"}</td>
                      <td>
                        <b>{s.sisa}</b>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
