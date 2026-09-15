"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "@/components/Toast";
import { DatePicker } from "@/components/DatePicker";

type Fmt = "1" | "2";

// Editable manual fields for the Sales Dashboard (Format 2).
const FIELDS: { k: string; label: string }[] = [
  { k: "target2026", label: "Target 2026" },
  { k: "sprToday", label: "SPR Today (YTD)" },
  { k: "targetUtj", label: "Target UTJ bulan ini" },
  { k: "utjNett", label: "UTJ Nett bulan ini" },
  { k: "nupNett", label: "NUP Nett bulan ini" },
  { k: "remainingDays", label: "Sisa hari" },
  { k: "holdUnit", label: "UTJ (Hold Unit)" },
  { k: "waitingList", label: "Waiting List" },
  { k: "salesPotensial", label: "Sales Potensial" },
  { k: "wiHot", label: "WI Hot Prospect" },
];

export function DailyClient({
  dates,
  initial,
  canEdit,
}: {
  dates: { key: string; label: string }[];
  initial: string;
  canEdit: boolean;
}) {
  const [date, setDate] = useState(initial);
  const [fmt, setFmt] = useState<Fmt>("1");
  const [ver, setVer] = useState(0); // cache-bust for the image after save
  const [showInput, setShowInput] = useState(false);
  const [form, setForm] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const src =
    fmt === "1"
      ? `/sales/api/report/daily?date=${date}`
      : `/sales/api/report/dashboard?date=${date}&v=${ver}`;

  const loadForm = useCallback(async () => {
    const res = await fetch(`/sales/api/report/dashboard/input?date=${date}`);
    if (!res.ok) return;
    const d = await res.json();
    const f: Record<string, string> = {};
    for (const { k } of FIELDS) f[k] = d[k] != null ? String(d[k]) : "";
    setForm(f);
  }, [date]);

  useEffect(() => {
    if (canEdit) loadForm();
  }, [date, canEdit, loadForm]);

  async function save() {
    setBusy(true);
    const body: Record<string, unknown> = { dayKey: date };
    for (const { k } of FIELDS) body[k] = form[k]?.trim() === "" ? null : Number(form[k]);
    const res = await fetch("/sales/api/report/dashboard/input", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    setBusy(false);
    if (res.ok) {
      toast("✓ Data Sales Dashboard disimpan");
      setVer((v) => v + 1);
    } else {
      toast((await res.json().catch(() => ({}))).error ?? "Gagal simpan");
    }
  }

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
        <div className="seg">
          <button className={`seg-btn ${fmt === "1" ? "on" : ""}`} onClick={() => setFmt("1")}>
            Format 1 · Report
          </button>
          <button className={`seg-btn ${fmt === "2" ? "on" : ""}`} onClick={() => setFmt("2")}>
            Format 2 · Sales Dashboard
          </button>
        </div>
        <a className="btn wa" href={`${src}${src.includes("?") ? "&" : "?"}download=1`}>
          ⬇ Download PNG
        </a>
        {canEdit && (
          <button className="btn" onClick={() => setShowInput((s) => !s)}>
            {showInput ? "Tutup input" : "✏️ Isi Data Sales Dashboard"}
          </button>
        )}
      </div>

      {showInput && canEdit && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="pad">
            <h3>Isi Data Sales Dashboard</h3>
            <div className="notice" style={{ marginBottom: 12, background: "#fbedd8", borderColor: "#e9b877" }}>
              <span>✋</span>
              <div>
                <b>Sementara diisi manual.</b> Data belum ke-link otomatis — semua angka di sini
                diketik dulu (satu funnel, isi semuanya). Nanti kalau sudah tersambung, field yang
                bisa otomatis keisi sendiri.
              </div>
            </div>
            <p className="rolehint" style={{ marginBottom: 12 }}>
              Angka per tanggal. Kosongkan untuk pakai nilai otomatis dari sistem. Field turunan
              (remaining, %, min/hari) dihitung sendiri.
            </p>
            <div className="frow" style={{ flexWrap: "wrap" }}>
              {FIELDS.map((f) => (
                <div className="field" key={f.k} style={{ minWidth: 150, flex: "1 1 150px" }}>
                  <label>{f.label}</label>
                  <input
                    value={form[f.k] ?? ""}
                    onChange={(e) => setForm((m) => ({ ...m, [f.k]: e.target.value }))}
                    inputMode="numeric"
                    placeholder="auto"
                  />
                </div>
              ))}
            </div>
            <div style={{ marginTop: 12 }}>
              <button className="btn" onClick={save} disabled={busy}>
                {busy ? "Menyimpan…" : "Simpan"}
              </button>
            </div>
          </div>
        </div>
      )}

      <div className="notice ok">
        <span>🖼️</span>
        <div>
          {fmt === "1"
            ? "Format 1 — report harian (WI per cluster, UTJ/SPR/NUP, trend). Otomatis dari event ledger."
            : "Format 2 — Sales Dashboard (template disetujui). ⚠️ Sementara diisi MANUAL (data belum ke-link) — isi semua lewat “Isi Data”."}
        </div>
      </div>

      <div className="previewwrap">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt={fmt === "1" ? "Daily Report" : "Sales Dashboard"} width={760} />
      </div>
    </>
  );
}
