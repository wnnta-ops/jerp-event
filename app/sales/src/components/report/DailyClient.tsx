"use client";

import { useState } from "react";
import { toast } from "@/components/Toast";
import { DatePicker } from "@/components/DatePicker";

export function DailyClient({ dates, initial }: { dates: { key: string; label: string }[]; initial: string }) {
  const [date, setDate] = useState(initial);
  const src = `/sales/api/report/daily?date=${date}`;

  return (
    <>
      <div className="actions">
        <DatePicker
          value={date}
          onChange={setDate}
          markedKeys={dates.map((d) => d.key)}
          todayKey={dates[0]?.key}
          maxKey={dates[0]?.key}
        />
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
