"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "@/components/Toast";
import { MatrixGrid } from "@/components/matrix/MatrixGrid";

type ColMap = Record<string, number>;

export function MatrixClient({
  dayKey,
  dateLabel,
  derived,
  initialManual,
  dates,
  canEdit,
  editable = true,
  dateBase = "/matrix?date=",
}: {
  dayKey: string;
  dateLabel: string;
  derived: Record<string, ColMap>;
  initialManual: Record<string, number>;
  dates: { key: string; label: string }[];
  canEdit: boolean;
  editable?: boolean;
  dateBase?: string;
}) {
  const router = useRouter();
  const [manual, setManual] = useState<Record<string, number | null>>(initialManual);
  const canWrite = canEdit && editable;

  const key = (row: string, col: string) => `${row}::${col}`;

  async function save(rowKey: string, colKey: string, raw: string) {
    const trimmed = raw.trim();
    const value = trimmed === "" ? null : Math.round(Number(trimmed));
    if (value !== null && Number.isNaN(value)) return;
    setManual((m) => ({ ...m, [key(rowKey, colKey)]: value }));
    const res = await fetch("/sales/api/matrix", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ dayKey, rowKey, colKey, value }),
    });
    if (!res.ok) {
      const j = await res.json().catch(() => ({}));
      toast(j.error ?? "Gagal simpan");
    }
  }

  return (
    <>
      <div className="actions no-print">
        <label className="pill" style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
          Tanggal:
          <select
            value={dayKey}
            onChange={(e) => router.push(`${dateBase}${e.target.value}`)}
            style={{ border: "none", background: "transparent", fontWeight: 700, padding: 0, color: "var(--ink)" }}
          >
            {dates.map((d) => (
              <option key={d.key} value={d.key}>
                {d.label}
              </option>
            ))}
          </select>
        </label>
        <a className="btn wa" href={`/api/matrix/export?date=${dayKey}`}>
          ⬇ Export Excel
        </a>
        <button className="btn ghost" onClick={() => window.print()}>
          🖨️ Print / PDF
        </button>
        {canWrite ? (
          <span className="rolehint">Sel angka putih bisa diisi (Cluster 1, Target, Proses) — auto-save.</span>
        ) : (
          <span className="rolehint">Mode lihat — isi angka manual di modul Collection → tab Input Matrix.</span>
        )}
      </div>

      <div id="matrix-print" className="card">
        <div className="pad">
          <div className="mtx-title">MATRIX DAILY SALES REPORT</div>
          <div className="mtx-sub">
            Report per Tanggal : <b>{dateLabel}</b>
          </div>
          <MatrixGrid derived={derived} manual={manual} editable={canWrite} onSave={save} />
        </div>
      </div>
    </>
  );
}
