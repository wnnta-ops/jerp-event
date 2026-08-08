"use client";

import { useState } from "react";
import { toast } from "@/components/Toast";

export function DailyClient({ dates, initial }: { dates: { key: string; label: string }[]; initial: string }) {
  const [date, setDate] = useState(initial);
  const src = `/sales/api/report/daily?date=${date}`;

  return (
    <>
      <div className="actions">
        <label className="pill" style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
          Periode:
          <select
            value={date}
            onChange={(e) => setDate(e.target.value)}
            style={{ border: "none", background: "transparent", fontWeight: 700, padding: 0, color: "var(--ink)" }}
          >
            {dates.map((d) => (
              <option key={d.key} value={d.key}>
                {d.label}
              </option>
            ))}
          </select>
        </label>
        <a className="btn wa" href={`${src}&download=1`}>
          ⬇ Download gambar (PNG)
        </a>
        <button className="btn ghost sm" onClick={() => toast("Export PDF (fase 2)")}>
          ⤓ PDF
        </button>
        <button className="btn ghost sm" onClick={() => toast("Kirim WA otomatis: WA Cloud API (fase 2)")}>
          ✈ Kirim WA
        </button>
      </div>

      <div className="notice ok">
        <span>🖼️</span>
        <div>
          Pratinjau gambar yang akan terkirim. Ubah data di <b>Input Harian / Pipeline / Stock</b> →
          gambar berubah otomatis (semua angka diturunkan dari event, bukan diketik ulang).
        </div>
      </div>

      <div className="previewwrap">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt="Daily Report" width={760} />
      </div>
    </>
  );
}
