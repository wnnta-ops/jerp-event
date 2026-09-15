"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "@/components/Toast";
import { DatePicker } from "@/components/DatePicker";

type Fmt = "1" | "2";
type Tab = "inti" | "daily" | "dash";
type WiRow = { cluster: string; murni: number; janjian: number; total?: number };
type Blok = { code: string; n: number };

// Semua field skalar bisa diedit; auto-fill dari web, disimpan hanya bila diubah.
const SCALARS = [
  "holdUnit", "waitingList",
  "utjGrossDay", "utjGagalDay", "sprGrossDay", "sprGagalDay", "nupDay",
  "target2026", "targetUtj", "sprToday", "utjNett", "nupNett",
  "salesPotensial", "wiHot", "remainingDays",
] as const;
type Key = (typeof SCALARS)[number];

// label + sumber (untuk hint "dari web"). null = murni manual.
const SRC: Record<Key, string | null> = {
  holdUnit: "Stock", waitingList: "Deal",
  utjGrossDay: null, utjGagalDay: "Collection", sprGrossDay: null, sprGagalDay: "Collection", nupDay: null,
  target2026: "Management", targetUtj: "Management", sprToday: null, utjNett: null, nupNett: null,
  salesPotensial: "Walk-In", wiHot: "Walk-In", remainingDays: "kalender",
};

export function DailyClient({
  dates, initial, canEdit,
}: {
  dates: { key: string; label: string }[];
  initial: string;
  canEdit: boolean;
}) {
  const [date, setDate] = useState(initial);
  const [fmt, setFmt] = useState<Fmt>("1");
  const [ver, setVer] = useState(0);
  const [showInput, setShowInput] = useState(false);
  const [tab, setTab] = useState<Tab>("inti");
  const [busy, setBusy] = useState(false);

  const [def, setDef] = useState<Record<Key, number>>({} as Record<Key, number>);
  const [form, setForm] = useState<Record<Key, string>>({} as Record<Key, string>);
  const [wi, setWi] = useState<WiRow[]>([]);
  const [wiDef, setWiDef] = useState<WiRow[]>([]);
  const [blok, setBlok] = useState<Blok[]>([]);
  const [blokDef, setBlokDef] = useState<Blok[]>([]);

  const src =
    fmt === "1"
      ? `/sales/api/report/daily?date=${date}&v=${ver}`
      : `/sales/api/report/dashboard?date=${date}&v=${ver}`;

  const loadForm = useCallback(async () => {
    const res = await fetch(`/sales/api/report/dashboard/input?date=${date}`);
    if (!res.ok) return;
    const d = await res.json();
    const e = d.entry ?? {};
    const dd = d.derivedDaily ?? {};
    const dh = d.derivedDash ?? {};
    const defaults: Record<Key, number> = {
      holdUnit: dd.holdUnit ?? 0, waitingList: dd.waitingList ?? 0,
      utjGrossDay: dd.utjGross ?? 0, utjGagalDay: dd.utjGagal ?? 0,
      sprGrossDay: dd.sprGross ?? 0, sprGagalDay: dd.sprGagal ?? 0, nupDay: dd.nup ?? 0,
      target2026: dh.target2026 ?? 0, targetUtj: dh.targetUtj ?? 0, sprToday: dh.sprToday ?? 0,
      utjNett: dh.utjNett ?? 0, nupNett: dh.nupNett ?? 0,
      salesPotensial: dh.salesPotensial ?? 0, wiHot: dh.wiHot ?? 0, remainingDays: dh.remainingDays ?? 0,
    };
    setDef(defaults);
    const f = {} as Record<Key, string>;
    for (const k of SCALARS) f[k] = String(e[k] != null ? e[k] : defaults[k]);
    setForm(f);
    const wd: WiRow[] = Array.isArray(dd.wiByCluster) ? dd.wiByCluster : [];
    setWiDef(wd);
    setWi(Array.isArray(e.wiByCluster) ? e.wiByCluster : wd);
    const bd: Blok[] = Array.isArray(dd.utjToday) ? dd.utjToday : [];
    setBlokDef(bd);
    setBlok(Array.isArray(e.utjPerBlok) ? e.utjPerBlok : bd);
  }, [date]);

  useEffect(() => {
    if (canEdit) loadForm();
  }, [date, canEdit, loadForm]);

  const n = (k: Key) => (form[k]?.trim() === "" ? 0 : Number(form[k]) || 0);
  const setK = (k: Key, v: string) => setForm((m) => ({ ...m, [k]: v }));

  async function save() {
    setBusy(true);
    const body: Record<string, unknown> = { dayKey: date };
    // dirty-tracking: kirim null bila = nilai web (tetap linked), else angkanya.
    for (const k of SCALARS) {
      const s = form[k]?.trim() ?? "";
      const v = s === "" ? null : Number(s);
      body[k] = v === null || v === def[k] ? null : v;
    }
    const wiClean = wi.filter((r) => r.cluster.trim() !== "").map((r) => ({ cluster: r.cluster, murni: r.murni, janjian: r.janjian, total: r.murni + r.janjian }));
    body.wiByCluster = JSON.stringify(wiClean) === JSON.stringify(wiDef.map((r) => ({ cluster: r.cluster, murni: r.murni, janjian: r.janjian, total: r.murni + r.janjian }))) ? null : wiClean;
    const blokClean = blok.filter((b) => b.code.trim() !== "");
    body.utjPerBlok = JSON.stringify(blokClean) === JSON.stringify(blokDef) ? null : blokClean;

    const res = await fetch("/sales/api/report/dashboard/input", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body),
    });
    setBusy(false);
    if (res.ok) {
      toast("✓ Data tersimpan — kedua format ikut update");
      setVer((v) => v + 1);
      loadForm();
    } else {
      toast((await res.json().catch(() => ({}))).error ?? "Gagal simpan");
    }
  }

  const utjNettDay = n("utjGrossDay") - n("utjGagalDay");
  const sprNettDay = n("sprGrossDay") - n("sprGagalDay");
  const remaining = n("target2026") - n("sprToday");
  const sprPct = n("target2026") ? Math.round((n("sprToday") / n("target2026")) * 100) : 0;
  const utjNupNett = n("utjNett") + n("nupNett");
  const utjNupPct = n("targetUtj") ? Math.round((utjNupNett / n("targetUtj")) * 100) : 0;
  const minSales = n("remainingDays") > 0 ? Math.ceil((n("targetUtj") - utjNupNett) / n("remainingDays")) : 0;

  const F = (k: Key, label: string) => (
    <Field label={label} value={form[k] ?? ""} onChange={(v) => setK(k, v)} src={SRC[k]} overridden={n(k) !== def[k]} />
  );

  return (
    <>
      <div className="actions">
        <DatePicker value={date} onChange={setDate} markedKeys={dates.map((d) => d.key)} todayKey={dates[0]?.key} maxKey={dates[0]?.key} />
        <div className="seg">
          <button className={`seg-btn ${fmt === "1" ? "on" : ""}`} onClick={() => setFmt("1")}>Format 1 · Report</button>
          <button className={`seg-btn ${fmt === "2" ? "on" : ""}`} onClick={() => setFmt("2")}>Format 2 · Sales Dashboard</button>
        </div>
        <a className="btn wa" href={`${src}&download=1`}>⬇ Download PNG</a>
        {canEdit && (
          <button className="btn" onClick={() => setShowInput((s) => !s)}>
            {showInput ? "Tutup input" : "📝 Isi Data"}
          </button>
        )}
      </div>

      {showInput && canEdit && (
        <div className="card" style={{ marginBottom: 14 }}>
          <div className="pad">
            <h3>Isi Data</h3>
            <div className="notice" style={{ marginBottom: 12, background: "#fbedd8", borderColor: "#e9b877" }}>
              <span>✋</span>
              <div>
                <b>Satu form untuk dua format.</b> Angka bertanda <span className="badge b-teal" style={{ fontSize: 10 }}>🔗 dari web</span> sudah
                terisi otomatis dari data web/collection — <b>tetap bisa diubah</b> kalau perlu. Yang tidak diubah
                otomatis ngikut web. Field <b>∑</b> dihitung sendiri.
              </div>
            </div>

            <div className="seg" style={{ marginBottom: 14 }}>
              <button className={`seg-btn ${tab === "inti" ? "on" : ""}`} onClick={() => setTab("inti")}>🔗 Angka Inti</button>
              <button className={`seg-btn ${tab === "daily" ? "on" : ""}`} onClick={() => setTab("daily")}>📄 Daily Report</button>
              <button className={`seg-btn ${tab === "dash" ? "on" : ""}`} onClick={() => setTab("dash")}>📊 Sales Dashboard</button>
            </div>

            {tab === "inti" && (
              <>
                <p className="rolehint" style={{ marginBottom: 12 }}>Dipakai <b>kedua format</b>. Auto dari web, bisa diubah.</p>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("holdUnit", "Hold Unit")}
                  {F("waitingList", "Waiting List")}
                  <div className="field" style={{ flex: "1 1 150px" }} />
                </div>
              </>
            )}

            {tab === "daily" && (
              <>
                <div style={{ marginBottom: 14 }}>
                  <div className="rolehint" style={{ marginBottom: 6 }}>
                    <b>WI per Cluster</b> <span className="badge b-teal" style={{ fontSize: 10 }}>🔗 Walk-In</span> — auto dari web, bisa diubah.
                  </div>
                  <div className="tablewrap">
                    <table>
                      <thead><tr><th>Cluster</th><th>WI Murni</th><th>WI Janjian</th><th>Total</th><th></th></tr></thead>
                      <tbody>
                        {wi.length === 0 && (
                          <tr><td colSpan={5} style={{ color: "var(--sub)" }}>Belum ada WI — tambah baris kalau perlu.</td></tr>
                        )}
                        {wi.map((r, i) => (
                          <tr key={i}>
                            <td><input value={r.cluster} placeholder="cluster" onChange={(e) => setWi((a) => a.map((x, j) => j === i ? { ...x, cluster: e.target.value } : x))} /></td>
                            <td><input inputMode="numeric" value={String(r.murni ?? "")} placeholder="0" onChange={(e) => setWi((a) => a.map((x, j) => j === i ? { ...x, murni: Number(e.target.value) || 0 } : x))} /></td>
                            <td><input inputMode="numeric" value={String(r.janjian ?? "")} placeholder="0" onChange={(e) => setWi((a) => a.map((x, j) => j === i ? { ...x, janjian: Number(e.target.value) || 0 } : x))} /></td>
                            <td><b>{(r.murni ?? 0) + (r.janjian ?? 0)}</b></td>
                            <td style={{ textAlign: "right" }}><button className="btn ghost sm" onClick={() => setWi((a) => a.filter((_, j) => j !== i))}>✕</button></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <button className="btn ghost sm" style={{ marginTop: 8 }} onClick={() => setWi((a) => [...a, { cluster: "", murni: 0, janjian: 0 }])}>＋ Tambah cluster</button>
                </div>

                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("utjGrossDay", "UTJ Gross")}
                  {F("utjGagalDay", "UTJ Gagal")}
                  <Auto label="UTJ Nett" value={utjNettDay} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("sprGrossDay", "SPR Gross")}
                  {F("sprGagalDay", "SPR Gagal")}
                  <Auto label="SPR Nett" value={sprNettDay} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("nupDay", "NUP (hari ini)")}
                  <div className="field" style={{ flex: "1 1 150px" }} />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                </div>

                <div style={{ marginTop: 6 }}>
                  <div className="rolehint" style={{ marginBottom: 6 }}><b>UTJ hari ini per Blok</b></div>
                  <div className="tablewrap">
                    <table>
                      <thead><tr><th>Blok / Phase</th><th>Jumlah</th><th></th></tr></thead>
                      <tbody>
                        {blok.map((b, i) => (
                          <tr key={i}>
                            <td><input value={b.code} placeholder="cth: C2.1" onChange={(e) => setBlok((a) => a.map((x, j) => j === i ? { ...x, code: e.target.value } : x))} /></td>
                            <td><input inputMode="numeric" value={String(b.n ?? "")} placeholder="0" onChange={(e) => setBlok((a) => a.map((x, j) => j === i ? { ...x, n: Number(e.target.value) || 0 } : x))} /></td>
                            <td style={{ textAlign: "right" }}><button className="btn ghost sm" onClick={() => setBlok((a) => a.filter((_, j) => j !== i))}>✕</button></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <button className="btn ghost sm" style={{ marginTop: 8 }} onClick={() => setBlok((a) => [...a, { code: "", n: 0 }])}>＋ Tambah blok</button>
                </div>
              </>
            )}

            {tab === "dash" && (
              <>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("target2026", "Target 2026")}
                  {F("targetUtj", "Target UTJ (bln ini)")}
                  {F("sprToday", "SPR Today (YTD)")}
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("utjNett", "UTJ Nett (bln ini)")}
                  {F("nupNett", "NUP Nett (bln ini)")}
                  {F("remainingDays", "Sisa Hari")}
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("holdUnit", "Hold Unit")}
                  {F("waitingList", "Waiting List")}
                  {F("salesPotensial", "Sales Potensial")}
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  {F("wiHot", "WI Hot Prospect")}
                  <div className="field" style={{ flex: "1 1 150px" }} />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                </div>
                <div className="notice ok" style={{ marginTop: 6 }}>
                  <span>∑</span>
                  <div style={{ fontSize: 13 }}>
                    Otomatis: <b>Sisa ke target</b> {remaining} · <b>SPR</b> {sprPct}% ·{" "}
                    <b>UTJ+NUP Nett</b> {utjNupNett} ({utjNupPct}%) · <b>Min Sales/hari</b> {minSales}
                  </div>
                </div>
              </>
            )}

            <div style={{ marginTop: 14, display: "flex", justifyContent: "flex-end", gap: 10 }}>
              <button className="btn ghost" onClick={() => setShowInput(false)}>Tutup</button>
              <button className="btn" onClick={save} disabled={busy}>{busy ? "Menyimpan…" : "Simpan (dua format)"}</button>
            </div>
          </div>
        </div>
      )}

      <div className="notice ok">
        <span>🖼️</span>
        <div>
          {fmt === "1"
            ? "Format 1 — report harian (WI per cluster, UTJ/SPR/NUP, trend)."
            : "Format 2 — Sales Dashboard (template disetujui)."}{" "}
          Angka auto dari web; semua bisa diubah lewat “📝 Isi Data”.
        </div>
      </div>

      <div className="previewwrap">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt={fmt === "1" ? "Daily Report" : "Sales Dashboard"} width={760} />
      </div>
    </>
  );
}

// Editable field, auto-filled from web. `overridden` menandai user sudah mengubah.
function Field({
  label, value, onChange, src, overridden,
}: {
  label: string; value: string; onChange: (v: string) => void; src: string | null; overridden: boolean;
}) {
  return (
    <div className="field" style={{ flex: "1 1 150px", minWidth: 150 }}>
      <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {label}
        {src ? (
          <span className="badge b-teal" style={{ fontSize: 10 }}>🔗 {src}</span>
        ) : (
          <span className="badge b-amber" style={{ fontSize: 10 }}>✍️ manual</span>
        )}
        {overridden && <span className="badge b-purple" style={{ fontSize: 10 }}>diubah</span>}
      </label>
      <input value={value} onChange={(e) => onChange(e.target.value)} inputMode="numeric" placeholder="0" />
    </div>
  );
}

function Auto({ label, value }: { label: string; value: number }) {
  return (
    <div className="field" style={{ flex: "1 1 150px", minWidth: 150 }}>
      <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {label} <span className="badge b-gray" style={{ fontSize: 10 }}>∑ auto</span>
      </label>
      <div style={{ border: "1px dashed var(--line)", borderRadius: 8, padding: "9px 11px", background: "transparent", color: "var(--muted)", fontWeight: 600 }}>
        {value}
      </div>
    </div>
  );
}
