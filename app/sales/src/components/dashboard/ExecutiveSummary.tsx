"use client";

import { useEffect, useRef } from "react";
import type { ExecSummaryData } from "@/lib/derivations";

const cssVar = (n: string) =>
  typeof window === "undefined" ? "#888" : getComputedStyle(document.documentElement).getPropertyValue(n).trim() || "#888";

function Bars({ data, max, color, labels }: { data: number[]; max: number; color: string; labels: string[] }) {
  const W = 460, H = 190, pad = { l: 24, r: 8, t: 12, b: 20 };
  const ref = useRef<SVGSVGElement>(null);
  useEffect(() => {
    if (!ref.current) return;
    const grid = cssVar("--grid") || "#eee", muted = cssVar("--sub") || "#888", line = cssVar("--line") || "#ddd";
    const iw = (W - pad.l - pad.r) / data.length, ph = H - pad.t - pad.b, bw = iw * 0.5;
    let s = `<line x1="${pad.l}" y1="${H - pad.b}" x2="${W - pad.r}" y2="${H - pad.b}" stroke="${line}"/>`;
    for (let i = 0; i <= 3; i++) {
      const y = pad.t + ph * i / 3;
      s += `<line x1="${pad.l}" y1="${y}" x2="${W - pad.r}" y2="${y}" stroke="${grid}"/><text x="${pad.l - 5}" y="${y + 3}" text-anchor="end" font-size="8" fill="${muted}">${Math.round(max * (3 - i) / 3)}</text>`;
    }
    data.forEach((v, i) => {
      const h = (Math.max(v, 0) / max) * ph, x = pad.l + iw * (i + 0.5) - bw / 2, y = H - pad.b - h;
      s += `<rect x="${x}" y="${y}" width="${bw}" height="${h}" rx="2.5" fill="${color}"/>`;
      s += `<text x="${pad.l + iw * (i + 0.5)}" y="${y - 3}" text-anchor="middle" font-size="8.5" font-weight="700" fill="${cssVar("--ink")}">${v}</text>`;
      s += `<text x="${pad.l + iw * (i + 0.5)}" y="${H - pad.b + 12}" text-anchor="middle" font-size="8" fill="${muted}">${labels[i]}</text>`;
    });
    ref.current.innerHTML = s;
  }, [data, max, color, labels]);
  return <svg ref={ref} viewBox={`0 0 ${W} ${H}`} style={{ display: "block", width: "100%", height: "auto", overflow: "visible" }} />;
}

function WiUtj({ wi, utj, labels }: { wi: number[]; utj: number[]; labels: string[] }) {
  const W = 460, H = 190, pad = { l: 26, r: 8, t: 12, b: 20 };
  const ref = useRef<SVGSVGElement>(null);
  useEffect(() => {
    if (!ref.current) return;
    const grid = cssVar("--grid"), muted = cssVar("--sub"), line = cssVar("--line");
    const max = Math.max(...wi, ...utj, 1) * 1.1;
    const iw = (W - pad.l - pad.r) / wi.length, ph = H - pad.t - pad.b, bw = iw * 0.5;
    let s = `<line x1="${pad.l}" y1="${H - pad.b}" x2="${W - pad.r}" y2="${H - pad.b}" stroke="${line}"/>`;
    for (let i = 0; i <= 3; i++) {
      const y = pad.t + ph * i / 3;
      s += `<line x1="${pad.l}" y1="${y}" x2="${W - pad.r}" y2="${y}" stroke="${grid}"/><text x="${pad.l - 5}" y="${y + 3}" text-anchor="end" font-size="8" fill="${muted}">${Math.round(max * (3 - i) / 3)}</text>`;
    }
    utj.forEach((v, i) => {
      const h = (v / max) * ph, x = pad.l + iw * (i + 0.5) - bw / 2;
      s += `<rect x="${x}" y="${H - pad.b - h}" width="${bw}" height="${h}" rx="2.5" fill="${cssVar("--teal")}"/>`;
      s += `<text x="${pad.l + iw * (i + 0.5)}" y="${H - pad.b + 12}" text-anchor="middle" font-size="8" fill="${muted}">${labels[i]}</text>`;
    });
    const pts = wi.map((v, i) => [pad.l + iw * (i + 0.5), H - pad.b - (v / max) * ph]);
    s += `<path d="${pts.map((p, i) => (i ? "L" : "M") + p[0].toFixed(1) + " " + p[1].toFixed(1)).join(" ")}" fill="none" stroke="${cssVar("--blue")}" stroke-width="2"/>`;
    s += pts.map((p) => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.4" fill="${cssVar("--blue")}"/>`).join("");
    ref.current.innerHTML = s;
  }, [wi, utj, labels]);
  return <svg ref={ref} viewBox={`0 0 ${W} ${H}`} style={{ display: "block", width: "100%", height: "auto", overflow: "visible" }} />;
}

function HBars({ data, color }: { data: { label: string; n: number }[]; color: string }) {
  const max = Math.max(...data.map((d) => d.n), 1);
  if (!data.length) return <div className="rolehint">Belum ada data bulan ini.</div>;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
      {data.map((d) => (
        <div key={d.label} style={{ display: "grid", gridTemplateColumns: "110px 1fr 34px", alignItems: "center", gap: 10 }}>
          <span style={{ fontSize: 12.5, fontWeight: 600, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{d.label}</span>
          <div style={{ height: 17, background: "var(--grid)", borderRadius: 4, overflow: "hidden" }}>
            <div style={{ height: "100%", width: `${(d.n / max) * 100}%`, background: color, borderRadius: 4 }} />
          </div>
          <span style={{ fontSize: 12.5, fontWeight: 700, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>{d.n}</span>
        </div>
      ))}
    </div>
  );
}

const chip = (kind: "ok" | "warn" | "off") =>
  kind === "ok" ? "🟢 Tersambung" : kind === "warn" ? "🟡 Sebagian" : "⚪ Belum tersambung";

export function ExecutiveSummary({ data: d }: { data: ExecSummaryData }) {
  const nettSpr = d.series.map((s) => s.sprNett);
  const wi = d.series.map((s) => s.wi);
  const utj = d.series.map((s) => s.utjNett);
  const sprMax = Math.max(...nettSpr, 1) * 1.15;
  const koorMax = Math.max(...d.perKoor.map((k) => Math.max(k.nett, k.target ?? 0)), 1);

  return (
    <div className="exs">
      <div className="grid g4" style={{ gridTemplateColumns: "repeat(auto-fit,minmax(150px,1fr))" }}>
        <div className="card kpi tint k-teal"><div className="lab">Nett SPR YTD</div><div className="val">{d.nettSprYtd}</div><div className="meta">target {d.annualTarget || "—"} · {d.annualPct}%</div></div>
        <div className="card kpi"><div className="lab">Sisa target</div><div className="val">{d.annualTarget ? d.remaining : "—"}</div><div className="meta">{d.annualTarget ? `${100 - d.annualPct}%` : "target belum diisi"}</div></div>
        <div className="card kpi"><div className="lab">SPR Gross YTD</div><div className="val">{d.sprGrossYtd}</div><div className="meta">sebelum batal</div></div>
        <div className="card kpi"><div className="lab">Batal YTD</div><div className="val">{d.batalYtd}</div><div className="meta">rate {d.batalRate}%</div></div>
      </div>

      <div className="exs-grid">
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>Nett SPR bulanan {new Date().getFullYear()}</h3><span className="pill">{chip("ok")}</span></div>
            <Bars data={nettSpr} max={sprMax} color={cssVar("--teal")} labels={d.monthLabels} />
            <div className="rolehint" style={{ marginTop: 6 }}>Pembanding 2025 belum tersambung (perlu import data historis).</div>
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>WI vs UTJ Nett bulanan</h3><span className="pill">{chip("ok")}</span></div>
            <div className="exs-leg"><span><i style={{ background: "var(--blue)" }} />WI</span><span><i style={{ background: "var(--teal)" }} />UTJ Nett</span></div>
            <WiUtj wi={wi} utj={utj} labels={d.monthLabels} />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>UTJ {d.monthLabel} per Koordinator</h3><span className="pill">{chip("warn")}</span></div>
            {d.perKoor.length === 0 ? (
              <div className="rolehint">Belum ada UTJ bulan ini.</div>
            ) : (
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {d.perKoor.map((k) => (
                  <div key={k.name} style={{ display: "grid", gridTemplateColumns: "84px 1fr 60px", alignItems: "center", gap: 10 }}>
                    <span style={{ fontSize: 12.5, fontWeight: 600 }}>{k.name}</span>
                    <div style={{ height: 17, background: "var(--grid)", borderRadius: 4, overflow: "hidden" }}>
                      <div style={{ height: "100%", width: `${(k.nett / koorMax) * 100}%`, background: "var(--teal)", borderRadius: 4 }} />
                    </div>
                    <span style={{ fontSize: 12, fontWeight: 700, textAlign: "right", fontVariantNumeric: "tabular-nums" }}>
                      {k.nett}
                      <em style={{ fontStyle: "normal", color: "var(--sub)", fontWeight: 500 }}> /{k.target ?? "—"}</em>
                    </span>
                  </div>
                ))}
              </div>
            )}
            <div className="rolehint" style={{ marginTop: 6 }}>Target per koordinator belum diisi.</div>
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>Penjualan per Tipe — {d.monthLabel}</h3><span className="pill">{chip("ok")}</span></div>
            <HBars data={d.perTipe.map((t) => ({ label: t.type, n: t.n }))} color="var(--main)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>Top Agent / Bendera — {d.monthLabel}</h3><span className="pill">{chip("ok")}</span></div>
            <HBars data={d.topAgent.map((a) => ({ label: a.name, n: a.n }))} color="var(--purple)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <div className="exs-ph"><h3>Snapshot {d.monthLabel}</h3><span className="pill">{chip("warn")}</span></div>
            <div className="grid" style={{ gridTemplateColumns: "1fr 1fr", gap: 12 }}>
              <div className="card kpi"><div className="lab">UTJ Nett</div><div className="val">{d.monthUtjNett}<span style={{ fontSize: 13, color: "var(--sub)" }}> / {d.monthTarget || "—"}</span></div><div className="meta">{d.monthTarget ? `${d.monthPct}% target` : "target belum diisi"}</div></div>
              <div className="card kpi"><div className="lab">WI {d.monthLabel}</div><div className="val">{d.monthWi}</div></div>
              <div className="card kpi"><div className="lab">Konversi WI→UTJ</div><div className="val">{d.convWiUtj}%</div></div>
              <div className="card kpi"><div className="lab">UTJ Gross</div><div className="val">{d.monthUtjGross}</div></div>
            </div>
          </div>
        </div>
      </div>

      <div className="card" style={{ marginTop: 14 }}>
        <div className="pad">
          <div className="exs-ph"><h3>Realisasi Biaya vs Budget</h3><span className="pill">{chip("off")}</span></div>
          <div className="notice" style={{ marginTop: 4 }}>
            <span>🔌</span>
            <div>Belum ada modul keuangan di Sales Hub. Perlu sumber data pengeluaran &amp; anggaran (software finance) — via pathway sync.</div>
          </div>
        </div>
      </div>

      <div className="notice info" style={{ marginTop: 14 }}>
        <span>📋</span>
        <div>
          <b>Perlu disambungkan:</b> data historis 2025 (YoY), target tahunan/bulanan/per-koordinator, dan modul biaya/budget. Angka lain sudah otomatis dari event ledger.
        </div>
      </div>
    </div>
  );
}
