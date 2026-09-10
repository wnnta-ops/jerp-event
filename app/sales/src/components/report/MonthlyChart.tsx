"use client";

import { useState } from "react";

export function MonthlyChart() {
  const [view, setView] = useState<"bulan" | "tahun">("bulan");
  const src = `/api/report/monthly?view=${view}`;

  return (
    <>
      <div className="actions">
        <select value={view} onChange={(e) => setView(e.target.value as "bulan" | "tahun")}>
          <option value="bulan">Bulanan (tahun ini)</option>
          <option value="tahun">Tahunan</option>
        </select>
        <a className="btn wa sm" href={`${src}&download=1`}>
          ⬇ Download gambar
        </a>
      </div>
      <div className="previewwrap" style={{ padding: 12 }}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt="Grafik UTJ Net" width={760} />
      </div>
    </>
  );
}
