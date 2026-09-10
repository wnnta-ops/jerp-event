import type { Slice } from "@/lib/lost";

// Simple horizontal bar breakdown — server-safe (no client JS).
export function BarList({
  data,
  color = "var(--main)",
  showPct = true,
}: {
  data: Slice[];
  color?: string;
  showPct?: boolean;
}) {
  const max = Math.max(...data.map((d) => d.n), 1);
  if (data.length === 0) return <div className="rolehint">Tidak ada data.</div>;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 9 }}>
      {data.map((d) => (
        <div key={d.label}>
          <div style={{ display: "flex", justifyContent: "space-between", fontSize: 12, marginBottom: 3 }}>
            <span style={{ color: "var(--ink)" }}>{d.label}</span>
            <span style={{ color: "var(--sub)", fontWeight: 700 }}>
              {d.n}
              {showPct && d.pct ? ` · ${d.pct}%` : ""}
            </span>
          </div>
          <div className="bar-track">
            <div className="bar-fill" style={{ width: `${(d.n / max) * 100}%`, background: color }} />
          </div>
        </div>
      ))}
    </div>
  );
}
