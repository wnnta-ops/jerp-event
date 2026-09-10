"use client";

import { useState } from "react";
import type { ProfileData } from "@/lib/lost";

type Slice = { label: string; n: number; pct: number };

function Bars({ data, color }: { data: Slice[]; color: string }) {
  const max = Math.max(1, ...data.map((d) => d.n));
  if (!data.length) return <div className="lp-empty">Tidak ada data</div>;
  return (
    <div className="lp-bars">
      {data.map((d) => (
        <div className="lp-row" key={d.label}>
          <span className="lp-lab" title={d.label}>
            {d.label}
          </span>
          <div className="lp-track">
            <div className="lp-fill" style={{ width: `${(d.n / max) * 100}%`, background: color }} />
          </div>
          <span className="lp-val">
            {d.n}
            <em>{d.pct}%</em>
          </span>
        </div>
      ))}
    </div>
  );
}

function Donut({ data }: { data: Slice[] }) {
  const cols = ["var(--accent, #d9822b)", "var(--teal, #1baf7a)", "var(--sub, #8f8b81)", "var(--blue,#378add)"];
  const shown = data.filter((d) => d.n > 0);
  const tot = shown.reduce((s, d) => s + d.n, 0);
  if (!tot) return <div className="lp-empty">Tidak ada data</div>;
  let acc = 0;
  const segs = shown.map((d, i) => {
    const from = (acc / tot) * 360;
    acc += d.n;
    const to = (acc / tot) * 360;
    return `${cols[i % cols.length]} ${from}deg ${to}deg`;
  });
  const top = shown[0];
  return (
    <div className="lp-donut-wrap">
      <div className="lp-donut" style={{ background: `conic-gradient(${segs.join(",")})` }}>
        <div className="lp-donut-c">
          <b>{Math.round((top.n / tot) * 100)}%</b>
          <span>{top.label}</span>
        </div>
      </div>
      <div className="lp-leg">
        {shown.map((d, i) => (
          <div className="lp-leg-i" key={d.label}>
            <i style={{ background: cols[i % cols.length] }} />
            {d.label}
            <span className="lv">
              {d.n} · {Math.round((d.n / tot) * 100)}%
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function LostProfile({ profiles }: { profiles: ProfileData[] }) {
  const [key, setKey] = useState(profiles[0]?.key ?? "ALL");
  const p = profiles.find((x) => x.key === key) ?? profiles[0];
  if (!p) return null;

  const topCluster = p.byCluster[0];
  const topChannel = p.byChannel[0];

  // heatmap: reason (cols) × cluster (rows)
  const reasons = profiles.filter((x) => x.key !== "ALL");
  const clusters = [...new Set(reasons.flatMap((r) => r.byCluster.map((c) => c.label)))].sort();
  const cellVal = (r: ProfileData, cl: string) => r.byCluster.find((c) => c.label === cl)?.n ?? 0;
  const heatMax = Math.max(1, ...reasons.flatMap((r) => r.byCluster.map((c) => c.n)));

  return (
    <div className="lp">
      <div className="lp-pills">
        {profiles.map((x) => (
          <button
            key={x.key}
            className={`lp-pill ${x.key === key ? "on" : ""} ${x.key === "ALL" ? "all" : ""}`}
            onClick={() => setKey(x.key)}
          >
            {x.key === "ALL" ? "📊 " : ""}
            {x.label}
            <span className="n">{x.n}</span>
          </button>
        ))}
      </div>

      <div className="lp-kpis">
        <div className="lp-kpi lp-hero">
          <div className="k">Jumlah customer</div>
          <div className="v">{p.n}</div>
        </div>
        <div className="lp-kpi">
          <div className="k">% dari total gagal</div>
          <div className="v">{p.pct}%</div>
        </div>
        <div className="lp-kpi">
          <div className="k">Cluster dominan</div>
          <div className="v sm">
            {topCluster ? `${topCluster.label} · ${topCluster.pct}%` : "-"}
          </div>
        </div>
        <div className="lp-kpi">
          <div className="k">Channel dominan</div>
          <div className="v sm">{topChannel ? topChannel.label : "-"}</div>
        </div>
      </div>

      <div className="lp-grid">
        <div className="lp-panel">
          <h4>Cluster</h4>
          <Bars data={p.byCluster} color="var(--teal, #1baf7a)" />
        </div>
        <div className="lp-panel">
          <h4>Tipe Unit</h4>
          <Bars data={p.byType} color="var(--accent, #d9822b)" />
        </div>
        <div className="lp-panel">
          <h4>Channel Akuisisi</h4>
          <Bars data={p.byChannel} color="var(--blue, #378add)" />
        </div>
        <div className="lp-panel">
          <h4>Bank / Bendera</h4>
          <Bars data={p.byBendera} color="var(--purple, #6250d6)" />
        </div>
        <div className="lp-panel lp-full">
          <h4>Cara Bayar</h4>
          <Donut data={p.byCaraBayar} />
        </div>
      </div>

      <div className="lp-panel lp-demo">
        <div className="lp-demo-h">
          <h4>Demografik {p.key === "ALL" ? "(semua)" : `· ${p.label}`}</h4>
          <span className={`badge ${p.demo.coverage > 0 ? "b-green" : "b-amber"}`}>
            {p.demo.coverage > 0 ? `${p.demo.coverage}/${p.n} terisi` : "belum tersambung"}
          </span>
        </div>
        {p.demo.coverage > 0 ? (
          <div className="lp-grid">
            <div className="lp-sub">
              <h5>Umur</h5>
              <Bars data={p.demo.byAge} color="var(--accent, #d9822b)" />
            </div>
            <div className="lp-sub">
              <h5>Pekerjaan</h5>
              <Bars data={p.demo.byJob} color="var(--teal, #1baf7a)" />
            </div>
            <div className="lp-sub">
              <h5>Domisili</h5>
              <Bars data={p.demo.byDomisili} color="var(--blue, #378add)" />
            </div>
            <div className="lp-sub">
              <h5>Penghasilan</h5>
              <Bars data={p.demo.byIncome} color="var(--purple, #6250d6)" />
            </div>
          </div>
        ) : (
          <p className="lp-demo-empty">
            Umur / pekerjaan / domisili / penghasilan belum masuk. Begitu software eksternal push ke{" "}
            <code>/api/demographic/sync</code>, chart ini otomatis keisi.
          </p>
        )}
      </div>

      {clusters.length > 0 && (
        <div className="lp-panel">
          <div className="lp-demo-h">
            <h4>Peta panas: alasan × cluster</h4>
            <span className="lp-hint">makin pekat = makin banyak kasus</span>
          </div>
          <div className="lp-heatwrap">
            <table className="lp-heat">
              <thead>
                <tr>
                  <th className="rl">Cluster</th>
                  {reasons.map((r) => (
                    <th key={r.key}>{r.label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {clusters.map((cl) => (
                  <tr key={cl}>
                    <td className="rl">{cl}</td>
                    {reasons.map((r) => {
                      const v = cellVal(r, cl);
                      const a = v === 0 ? 0 : 0.14 + (v / heatMax) * 0.86;
                      return (
                        <td
                          key={r.key}
                          style={{
                            background:
                              v === 0
                                ? "transparent"
                                : `color-mix(in srgb, var(--red, #b8442f) ${Math.round(a * 100)}%, transparent)`,
                            color: a > 0.55 ? "#fff" : "var(--ink)",
                          }}
                        >
                          {v || "·"}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
