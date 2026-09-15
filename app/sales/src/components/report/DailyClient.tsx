"use client";

import { useCallback, useEffect, useState } from "react";
import { toast } from "@/components/Toast";
import { DatePicker } from "@/components/DatePicker";

type Fmt = "1" | "2";
type Tab = "inti" | "daily" | "dash";

type WiRow = { cluster: string; total: number; murni: number; janjian: number };
type Daily = {
  wiByCluster: WiRow[];
  utjGagal: number;
  sprGagal: number;
  holdUnit: number;
  waitingList: number;
};
type Dash = {
  target2026: number;
  targetUtj: number;
  salesPotensial: number;
  wiHot: number;
  remainingDays: number;
  remaining: number;
  sprPct: number;
  utjNupNett: number;
  utjNupPct: number;
  minSalesDay: number;
};
type Blok = { code: string; n: number };

// Field manual yang diketik user (angka dari integrasi eksternal yang belum nyala).
const MANUAL = [
  "utjGrossDay",
  "sprGrossDay",
  "nupDay",
  "sprToday",
  "utjNett",
  "nupNett",
] as const;
type ManualKey = (typeof MANUAL)[number];

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
  const [ver, setVer] = useState(0);
  const [showInput, setShowInput] = useState(false);
  const [tab, setTab] = useState<Tab>("inti");
  const [form, setForm] = useState<Record<ManualKey, string>>({
    utjGrossDay: "",
    sprGrossDay: "",
    nupDay: "",
    sprToday: "",
    utjNett: "",
    nupNett: "",
  });
  const [blok, setBlok] = useState<Blok[]>([]);
  const [daily, setDaily] = useState<Daily | null>(null);
  const [dash, setDash] = useState<Dash | null>(null);
  const [busy, setBusy] = useState(false);

  const src =
    fmt === "1"
      ? `/sales/api/report/daily?date=${date}&v=${ver}`
      : `/sales/api/report/dashboard?date=${date}&v=${ver}`;

  const loadForm = useCallback(async () => {
    const res = await fetch(`/sales/api/report/dashboard/input?date=${date}`);
    if (!res.ok) return;
    const d = await res.json();
    const e = d.entry ?? {};
    const f = {} as Record<ManualKey, string>;
    for (const k of MANUAL) f[k] = e[k] != null ? String(e[k]) : "";
    setForm(f);
    setBlok(Array.isArray(e.utjPerBlok) ? e.utjPerBlok : []);
    setDaily(d.daily ?? null);
    setDash(d.dashboard ?? null);
  }, [date]);

  useEffect(() => {
    if (canEdit) loadForm();
  }, [date, canEdit, loadForm]);

  const numOf = (k: ManualKey) => (form[k]?.trim() === "" ? 0 : Number(form[k]) || 0);
  const set = (k: ManualKey, v: string) => setForm((m) => ({ ...m, [k]: v }));

  async function save() {
    setBusy(true);
    const body: Record<string, unknown> = { dayKey: date };
    for (const k of MANUAL) body[k] = form[k]?.trim() === "" ? null : Number(form[k]);
    body.utjPerBlok = blok.filter((b) => b.code.trim() !== "");
    const res = await fetch("/sales/api/report/dashboard/input", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
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

  // live-computed nett (gross manual − gagal linked)
  const utjNettDay = numOf("utjGrossDay") - (daily?.utjGagal ?? 0);
  const sprNettDay = numOf("sprGrossDay") - (daily?.sprGagal ?? 0);

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
                <b>Satu form untuk dua format.</b> Angka bertanda <LinkChip /> sudah otomatis dari
                web (sales/collection) — <b>tidak bisa diketik</b>. Sisanya diisi manual dulu
                (integrasi belum nyala). Field <b>∑</b> dihitung sendiri.
              </div>
            </div>

            <div className="seg" style={{ marginBottom: 14 }}>
              <button className={`seg-btn ${tab === "inti" ? "on" : ""}`} onClick={() => setTab("inti")}>🔗 Angka Inti</button>
              <button className={`seg-btn ${tab === "daily" ? "on" : ""}`} onClick={() => setTab("daily")}>📄 Daily Report</button>
              <button className={`seg-btn ${tab === "dash" ? "on" : ""}`} onClick={() => setTab("dash")}>📊 Sales Dashboard</button>
            </div>

            {/* ---------- TAB: Angka Inti ---------- */}
            {tab === "inti" && (
              <>
                <p className="rolehint" style={{ marginBottom: 12 }}>
                  Dipakai <b>kedua format</b>. Sudah otomatis dari web — cukup dilihat.
                </p>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Linked label="Hold Unit" value={daily?.holdUnit} src="Stock" />
                  <Linked label="Waiting List" value={daily?.waitingList} src="Deal" />
                </div>
              </>
            )}

            {/* ---------- TAB: Daily Report (harian) ---------- */}
            {tab === "daily" && (
              <>
                <div style={{ marginBottom: 14 }}>
                  <div className="rolehint" style={{ marginBottom: 6 }}>
                    <b>WI per Cluster</b> <LinkChip /> — otomatis dari Walk-In.
                  </div>
                  <div className="tablewrap">
                    <table>
                      <thead>
                        <tr><th>Cluster</th><th>WI Murni</th><th>WI Janjian</th><th>Total</th></tr>
                      </thead>
                      <tbody>
                        {(daily?.wiByCluster ?? []).length === 0 && (
                          <tr><td colSpan={4} style={{ color: "var(--sub)" }}>Belum ada WI hari ini.</td></tr>
                        )}
                        {(daily?.wiByCluster ?? []).map((w) => (
                          <tr key={w.cluster}>
                            <td><b>{w.cluster}</b></td>
                            <td>{w.murni}</td><td>{w.janjian}</td><td>{w.total}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Manual label="UTJ Gross" v={form.utjGrossDay} on={(v) => set("utjGrossDay", v)} />
                  <Linked label="UTJ Gagal" value={daily?.utjGagal} src="Collection" />
                  <Auto label="UTJ Nett" value={utjNettDay} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Manual label="SPR Gross" v={form.sprGrossDay} on={(v) => set("sprGrossDay", v)} />
                  <Linked label="SPR Gagal" value={daily?.sprGagal} src="Collection" />
                  <Auto label="SPR Nett" value={sprNettDay} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Manual label="NUP (hari ini)" v={form.nupDay} on={(v) => set("nupDay", v)} />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                </div>

                <div style={{ marginTop: 6 }}>
                  <div className="rolehint" style={{ marginBottom: 6 }}>
                    <b>UTJ hari ini per Blok</b> ✍️ — isi manual.
                  </div>
                  <div className="tablewrap">
                    <table>
                      <thead><tr><th>Blok / Phase</th><th>Jumlah</th><th></th></tr></thead>
                      <tbody>
                        {blok.map((b, i) => (
                          <tr key={i}>
                            <td>
                              <input
                                value={b.code}
                                placeholder="cth: C2.1"
                                onChange={(e) => setBlok((a) => a.map((x, j) => (j === i ? { ...x, code: e.target.value } : x)))}
                              />
                            </td>
                            <td>
                              <input
                                inputMode="numeric"
                                value={String(b.n ?? "")}
                                placeholder="0"
                                onChange={(e) => setBlok((a) => a.map((x, j) => (j === i ? { ...x, n: Number(e.target.value) || 0 } : x)))}
                              />
                            </td>
                            <td style={{ textAlign: "right" }}>
                              <button className="btn ghost sm" onClick={() => setBlok((a) => a.filter((_, j) => j !== i))}>✕</button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <button className="btn ghost sm" style={{ marginTop: 8 }} onClick={() => setBlok((a) => [...a, { code: "", n: 0 }])}>＋ Tambah blok</button>
                </div>
              </>
            )}

            {/* ---------- TAB: Sales Dashboard (bulanan) ---------- */}
            {tab === "dash" && (
              <>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Linked label="Target 2026" value={dash?.target2026} src="Management" />
                  <Linked label="Target UTJ (bln ini)" value={dash?.targetUtj} src="Management" />
                  <Manual label="SPR Today (YTD)" v={form.sprToday} on={(v) => set("sprToday", v)} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Manual label="UTJ Nett (bln ini)" v={form.utjNett} on={(v) => set("utjNett", v)} />
                  <Manual label="NUP Nett (bln ini)" v={form.nupNett} on={(v) => set("nupNett", v)} />
                  <Auto label="Sisa Hari" value={dash?.remainingDays} />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Linked label="Hold Unit" value={daily?.holdUnit} src="Stock" />
                  <Linked label="Waiting List" value={daily?.waitingList} src="Deal" />
                  <Linked label="Sales Potensial" value={dash?.salesPotensial} src="Walk-In" />
                </div>
                <div className="frow" style={{ flexWrap: "wrap" }}>
                  <Linked label="WI Hot Prospect" value={dash?.wiHot} src="Walk-In" />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                  <div className="field" style={{ flex: "1 1 150px" }} />
                </div>
                <div className="notice ok" style={{ marginTop: 6 }}>
                  <span>∑</span>
                  <div style={{ fontSize: 13 }}>
                    Otomatis: <b>Sisa ke target</b> {dash?.remaining ?? "–"} · <b>SPR</b> {dash?.sprPct ?? 0}% ·{" "}
                    <b>UTJ+NUP Nett</b> {dash?.utjNupNett ?? "–"} ({dash?.utjNupPct ?? 0}%) ·{" "}
                    <b>Min Sales/hari</b> {dash?.minSalesDay ?? "–"} <span style={{ color: "var(--sub)" }}>(refresh setelah simpan)</span>
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
          Angka linked dari web; sisanya dari “📝 Isi Data”.
        </div>
      </div>

      <div className="previewwrap">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src} alt={fmt === "1" ? "Daily Report" : "Sales Dashboard"} width={760} />
      </div>
    </>
  );
}

// ---- small field helpers ----
function LinkChip() {
  return (
    <span className="badge b-teal" style={{ fontSize: 10 }}>🔗 dari web</span>
  );
}

function Linked({ label, value, src }: { label: string; value?: number | null; src: string }) {
  return (
    <div className="field" style={{ flex: "1 1 150px", minWidth: 150 }}>
      <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {label} <span className="badge b-teal" style={{ fontSize: 10 }}>🔗 {src}</span>
      </label>
      <div
        title="Otomatis dari web — tidak bisa diketik"
        style={{
          border: "1px solid var(--line)", borderRadius: 8, padding: "9px 11px",
          background: "var(--bg2, #eef4f0)", color: "var(--ink)", fontWeight: 600,
          cursor: "not-allowed", opacity: 0.85,
        }}
      >
        {value ?? "–"}
      </div>
    </div>
  );
}

function Auto({ label, value }: { label: string; value?: number | null }) {
  return (
    <div className="field" style={{ flex: "1 1 150px", minWidth: 150 }}>
      <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {label} <span className="badge b-gray" style={{ fontSize: 10 }}>∑ auto</span>
      </label>
      <div
        style={{
          border: "1px dashed var(--line)", borderRadius: 8, padding: "9px 11px",
          background: "transparent", color: "var(--muted)", fontWeight: 600,
        }}
      >
        {value ?? "–"}
      </div>
    </div>
  );
}

function Manual({ label, v, on }: { label: string; v: string; on: (v: string) => void }) {
  return (
    <div className="field" style={{ flex: "1 1 150px", minWidth: 150 }}>
      <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
        {label} <span className="badge b-amber" style={{ fontSize: 10 }}>✍️ manual</span>
      </label>
      <input value={v} onChange={(e) => on(e.target.value)} inputMode="numeric" placeholder="0" />
    </div>
  );
}
