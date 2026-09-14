"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "@/components/Toast";
import { DatePicker } from "@/components/DatePicker";
import { MatrixGrid } from "@/components/matrix/MatrixGrid";

type ColMap = Record<string, number>;

export function MatrixInputGrid({
  dayKey,
  dateLabel,
  derived,
  initialManual,
  dates,
  canEdit,
  dateBase = "/collection?tab=input&date=",
}: {
  dayKey: string;
  dateLabel: string;
  derived: Record<string, ColMap>;
  initialManual: Record<string, number>;
  dates: { key: string; label: string }[];
  canEdit: boolean;
  dateBase?: string;
}) {
  const router = useRouter();
  const [manual, setManual] = useState<Record<string, number | null>>(initialManual);
  const [mode, setMode] = useState<"fill" | "preview">("fill");

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

  const editing = mode === "fill" && canEdit;

  return (
    <>
      <div className="actions no-print">
        <DatePicker
          value={dayKey}
          onChange={(k) => router.push(`${dateBase}${k}`)}
          markedKeys={dates.map((d) => d.key)}
          todayKey={dates[0]?.key}
          maxKey={dates[0]?.key}
        />

        <div className="seg">
          <button className={`seg-btn ${mode === "fill" ? "on" : ""}`} onClick={() => setMode("fill")}>
            ✏️ Isi Data
          </button>
          <button className={`seg-btn ${mode === "preview" ? "on" : ""}`} onClick={() => setMode("preview")}>
            👁 Preview
          </button>
        </div>

        <a className="btn wa" href={`/sales/api/matrix/export?date=${dayKey}`}>
          ⬇ Export Excel
        </a>
        {mode === "preview" && (
          <button className="btn ghost" onClick={() => window.print()}>
            🖨️ Print / PDF
          </button>
        )}
      </div>

      <div id="matrix-print" className="card">
        <div className="pad">
          {mode === "fill" ? (
            <div className="mtx-legend no-print">
              <span><i className="fill" /> Chip oranye = isi di sini (auto-save)</span>
              <span><i className="lock" /> Chip abu = otomatis dari data web</span>
            </div>
          ) : (
            <div className="preview-banner no-print">
              <b>PREVIEW</b> — tampilan Matrix Report dengan data yang kamu isi. Data sudah tersimpan otomatis.
            </div>
          )}
          <div className="mtx-title">MATRIX DAILY SALES REPORT</div>
          <div className="mtx-sub">
            Report per Tanggal : <b>{dateLabel}</b>
          </div>
          <MatrixGrid derived={derived} manual={manual} editable={editing} accordion={editing} onSave={save} />
        </div>
      </div>
    </>
  );
}
