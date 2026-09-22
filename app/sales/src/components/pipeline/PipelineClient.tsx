"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { Role, Stage } from "@prisma/client";
import { Modal } from "@/components/Modal";
import { Combobox } from "@/components/Combobox";
import { toast } from "@/components/Toast";
import { ChannelBadge, LeadBadge, StageBadge } from "@/components/badges";
import { SALES_MAX_INDEX, STAGES, STAGE_INDEX, LEAD_FIELD_LABEL } from "@/lib/constants";
import { allowedTargets } from "@/lib/pipeline-flow";
import { MANUAL_STAGE_INPUT } from "@/lib/features";

interface HistoryEntry {
  icon: string;
  title: string;
  detail: string;
  dateLabel: string;
  actor: string;
}
interface DealHistory {
  timeline: HistoryEntry[];
  stageDates: Record<string, string | null>;
}

export interface PipelineDeal {
  id: string;
  nama: string;
  channel: "SITE" | "PAMERAN" | "JANJIAN" | "LEADS";
  stage: Stage;
  statusLead: "HOT" | "WARM" | "COLD" | "DONE";
  agency: string;
  sales: string;
  koor: string;
  cluster: string;
  type: string;
  unit: string | null;
  bank: string | null;
  catatan: string | null;
  dead?: boolean; // not-interested (batal) — shown red, out of active flow
}

// One column per stage. Zone marks who owns it: Sales (0–4), Collection (5–6),
// Legal (7 = AJB).
const ZONE_LABEL = { sa: "Sales", co: "Collection", le: "Legal" } as const;
const KANBAN: { t: string; stages: Stage[]; zone: "sa" | "co" | "le" }[] = [
  { t: "Follow-Up", stages: ["WI", "FOLLOW_UP"], zone: "sa" },
  { t: "NUP", stages: ["NUP"], zone: "sa" },
  { t: "UTJ", stages: ["UTJ"], zone: "sa" },
  { t: "SPR", stages: ["SPR"], zone: "sa" },
  { t: "ACC Bank", stages: ["ACC"], zone: "co" },
  { t: "Akad", stages: ["AKAD"], zone: "co" },
  { t: "AJB", stages: ["REALISASI"], zone: "le" },
];

// Max cards rendered per column (count badge still shows the true total).
const CARD_CAP = 40;

// Lead temperature only meaningful before UTJ (WI / Follow-Up / NUP).
const showsLead = (stage: Stage) => STAGE_INDEX[stage] <= STAGE_INDEX["NUP"];

export function PipelineClient({ deals, role }: { deals: PipelineDeal[]; role: Role }) {
  const router = useRouter();
  // prefilter dari ?q= (mis. saat diklik dari lonceng notifikasi)
  const [q, setQ] = useState(() =>
    typeof window !== "undefined" ? new URLSearchParams(window.location.search).get("q") ?? "" : "",
  );
  const [sel, setSel] = useState<PipelineDeal | null>(null);
  const [busy, setBusy] = useState(false);
  const [fuNote, setFuNote] = useState("");
  const [fuStatus, setFuStatus] = useState("WARM");
  const [fSales, setFSales] = useState("all");
  const [fKoor, setFKoor] = useState("all");
  const [fTahap, setFTahap] = useState("all");
  const [history, setHistory] = useState<DealHistory | null>(null);

  async function reloadHistory(id: string) {
    try {
      const d = await fetch(`/sales/api/deals/${id}/history`).then((r) => r.json());
      setHistory(d);
    } catch {
      /* biarkan */
    }
  }

  // Load the full history whenever a customer's journey modal opens.
  useEffect(() => {
    if (!sel) {
      setHistory(null);
      return;
    }
    setHistory(null);
    reloadHistory(sel.id);
    // hanya saat id berubah (buka customer lain), bukan tiap patch sel
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sel?.id]);

  // Distinct filter options derived from the current deal set.
  const salesOpts = useMemo(
    () => [...new Set(deals.map((d) => d.sales))].sort((a, b) => a.localeCompare(b)),
    [deals],
  );
  const koorOpts = useMemo(
    () => [...new Set(deals.map((d) => d.koor))].sort((a, b) => a.localeCompare(b)),
    [deals],
  );
  const tahapOpts = useMemo(
    () =>
      [...new Set(deals.map((d) => d.stage))].sort((a, b) => STAGE_INDEX[a] - STAGE_INDEX[b]),
    [deals],
  );

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase();
    return deals.filter((d) => {
      if (fSales !== "all" && d.sales !== fSales) return false;
      if (fKoor !== "all" && d.koor !== fKoor) return false;
      if (fTahap !== "all" && d.stage !== fTahap) return false;
      if (s && ![d.nama, d.sales, d.agency, d.koor, d.cluster, d.type].join(" ").toLowerCase().includes(s))
        return false;
      return true;
    });
  }, [q, deals, fSales, fKoor, fTahap]);

  const hasFilter = fSales !== "all" || fKoor !== "all" || fTahap !== "all" || q.trim() !== "";

  async function advance(d: PipelineDeal, target: Stage) {
    setBusy(true);
    const res = await fetch(`/sales/api/deals/${d.id}/advance`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ toStage: target }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ " + d.nama + " → " + STAGES[STAGE_INDEX[target]].label);
      setSel(null);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  async function addFollowUp(d: PipelineDeal) {
    if (!fuNote.trim()) return toast("Isi catatan follow-up");
    setBusy(true);
    const res = await fetch(`/sales/api/deals/${d.id}/followup`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ note: fuNote, statusLead: fuStatus }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ Follow-up tercatat");
      setFuNote("");
      // biarkan modal terbuka & tampilkan update di Riwayat seketika
      setSel((s) => (s ? { ...s, statusLead: fuStatus as PipelineDeal["statusLead"] } : s));
      reloadHistory(d.id);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  async function notInterested(d: PipelineDeal) {
    if (!window.confirm(`Tandai "${d.nama}" tidak berminat? Customer keluar dari pipeline & masuk Pipeline Gagal.`)) return;
    setBusy(true);
    const res = await fetch(`/sales/api/deals/${d.id}/not-interested`, { method: "POST" });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ " + d.nama + " ditandai tidak berminat");
      setSel(null);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  // Lead lanjut → NUP atau UTJ. Pindah stage + status lead jadi DONE.
  async function convertStage(d: PipelineDeal, stage: "NUP" | "UTJ") {
    setBusy(true);
    const res = await fetch(`/sales/api/deals/${d.id}/convert-stage`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ stage }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ " + d.nama + " → " + stage + " (status: Done)");
      setSel((s) => (s ? { ...s, stage, statusLead: "DONE" } : s));
      reloadHistory(d.id);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  const idx = sel ? STAGE_INDEX[sel.stage] : 0;
  const targets = sel ? allowedTargets(role, sel.stage) : [];
  const canFu = sel ? (role === "SALES" || role === "MANAGEMENT") && idx < SALES_MAX_INDEX && !sel.dead : false;

  return (
    <>
      <div className="actions">
        <input
          className="search"
          placeholder="🔎 Cari customer / sales / agent"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <Combobox
          value={fSales}
          onChange={setFSales}
          options={salesOpts.map((s) => ({ value: s, label: s }))}
          allLabel="Semua Sales"
          title="Filter Sales"
        />
        <Combobox
          value={fKoor}
          onChange={setFKoor}
          options={koorOpts.map((k) => ({ value: k, label: k }))}
          allLabel="Semua Koor"
          title="Filter Koordinator"
        />
        <Combobox
          value={fTahap}
          onChange={setFTahap}
          options={tahapOpts.map((st) => ({ value: st, label: STAGES[STAGE_INDEX[st]].label }))}
          allLabel="Semua Tahap"
          title="Filter Tahap"
        />
        {hasFilter && (
          <button
            className="btn ghost sm"
            onClick={() => {
              setQ("");
              setFSales("all");
              setFKoor("all");
              setFTahap("all");
            }}
          >
            ✕ Reset
          </button>
        )}
        <span className="rolehint">
          {role === "SALES"
            ? "Kamu gerakkan customer s/d SPR."
            : role === "COLLECTION"
              ? "Kamu lanjutkan dari ACC ke atas."
              : "Kamu bisa gerakkan semua tahap."}
        </span>
      </div>

      <div className="kan">
        {KANBAN.map((col) => {
          const items = filtered.filter((d) => col.stages.includes(d.stage));
          return (
            <div className={`col z${col.zone}`} key={col.t}>
              <div className="zlab">{ZONE_LABEL[col.zone]}</div>
              <div className="ch">
                <span>{col.t}</span>
                <span className="cnt">{items.length}</span>
              </div>
              {items.slice(0, CARD_CAP).map((d) => (
                <div className={`lcard ${d.dead ? "dead" : ""}`} key={d.id} onClick={() => setSel(d)}>
                  <div className="nm">
                    {d.nama}{" "}
                    {d.dead ? (
                      <span className="badge b-red">tidak berminat</span>
                    ) : (
                      showsLead(d.stage) && <LeadBadge t={d.statusLead} />
                    )}
                  </div>
                  <div className="mt">
                    {d.cluster} · {d.type}
                  </div>
                </div>
              ))}
              {items.length > CARD_CAP && (
                <div className="more">+{items.length - CARD_CAP} lainnya — pakai filter</div>
              )}
            </div>
          );
        })}
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="pad">
          <h3>Semua customer ({filtered.length})</h3>
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Channel</th>
                  <th>Cluster/Type</th>
                  <th>Sales</th>
                  <th>Koor</th>
                  <th>Tahap</th>
                  <th>Progress</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {filtered.map((d) => {
                  const p = (STAGE_INDEX[d.stage] / 7) * 100;
                  return (
                    <tr key={d.id}>
                      <td>
                        <b>{d.nama}</b> {showsLead(d.stage) && <LeadBadge t={d.statusLead} />}
                      </td>
                      <td>
                        <ChannelBadge c={d.channel} />
                      </td>
                      <td>
                        {d.cluster} · {d.type}
                      </td>
                      <td>{d.sales}</td>
                      <td>{d.koor}</td>
                      <td>
                        <StageBadge stage={d.stage} />
                      </td>
                      <td style={{ minWidth: 110 }}>
                        <div className="bar-track">
                          <div
                            className="bar-fill"
                            style={{
                              width: `${p}%`,
                              background:
                                STAGE_INDEX[d.stage] >= 5 ? "var(--purple)" : "var(--teal)",
                            }}
                          />
                        </div>
                      </td>
                      <td>
                        <button className="btn ghost sm" onClick={() => setSel(d)}>
                          journey
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <Modal
        title={sel?.nama ?? ""}
        open={!!sel}
        onClose={() => setSel(null)}
        footer={
          sel ? (
            <>
              <div style={{ flex: 1, display: "flex", gap: 8, flexWrap: "wrap" }}>
                {!MANUAL_STAGE_INPUT && idx < 7 ? (
                  <span className="pill">🔌 Tahap lanjut masuk dari sistem lain (integrasi)</span>
                ) : MANUAL_STAGE_INPUT && targets.length > 0 ? (
                  targets.map((t) => (
                    <button key={t} className="btn" onClick={() => advance(sel, t)} disabled={busy}>
                      {role === "COLLECTION" ? "Update ke" : "Lanjut ke"}: {STAGES[STAGE_INDEX[t]].label} →
                    </button>
                  ))
                ) : idx >= 7 ? (
                  <span className="badge b-legal">Selesai / AJB</span>
                ) : role === "SALES" && idx >= SALES_MAX_INDEX ? (
                  <span className="pill">🔒 Tahap ini diisi Collection / Legal</span>
                ) : role === "COLLECTION" && idx < SALES_MAX_INDEX ? (
                  <span className="pill">🔒 Tahap awal diisi Sales</span>
                ) : (
                  <span className="pill">🔒 Mode lihat</span>
                )}
              </div>
              <button className="btn ghost" onClick={() => setSel(null)}>
                Tutup
              </button>
            </>
          ) : null
        }
      >
        {sel && (
          <>
            <div style={{ display: "flex", gap: 7, flexWrap: "wrap", marginBottom: 12 }}>
              <StageBadge stage={sel.stage} />
              {showsLead(sel.stage) && <LeadBadge t={sel.statusLead} />}
              <ChannelBadge c={sel.channel} />
              <span className="pill key">
                {sel.cluster} · {sel.type}
              </span>
              <span className="pill key">Bendera: {sel.agency}</span>
              <span className="pill key">Sales: {sel.sales}</span>
              <span className="pill key">Koor: {sel.koor}</span>
              {sel.unit && <span className="pill">{sel.unit}</span>}
              {sel.bank && <span className="pill">Bank: {sel.bank}</span>}
            </div>
            {/* zona pengisi — proporsional di atas stepper (Sales 5 / Collection 2 / Legal 1) */}
            <div style={{ display: "flex", gap: 6, marginBottom: 6 }}>
              <span className="zone sa" style={{ flex: 5 }}>Sales (s/d SPR)</span>
              <span className="zone co" style={{ flex: 2 }}>Collection</span>
              <span className="zone le" style={{ flex: 1 }}>Legal</span>
            </div>
            <div className="stepper">
              {STAGES.map((s, i) => {
                const cls = i < idx ? "done" : i === idx ? "cur" : "";
                const dt = history?.stageDates?.[s.key];
                return (
                  <div className={`s ${cls}`} key={s.key}>
                    <div className="dot">{i < idx ? "✓" : i + 1}</div>
                    <div className="t">{s.label}</div>
                    {dt && <div className="sdate">{dt}</div>}
                  </div>
                );
              })}
            </div>
            <div className="field" style={{ marginTop: 14 }}>
              <label>Catatan terakhir</label>
              <textarea rows={2} defaultValue={sel.catatan ?? ""} readOnly />
            </div>

            {/* Riwayat — history of every stage change / follow-up / batal */}
            <div style={{ borderTop: "1px solid var(--line)", paddingTop: 12, marginTop: 4 }}>
              <label style={{ fontSize: 11.5, fontWeight: 600, color: "var(--sub)" }}>
                Riwayat perubahan
              </label>
              {!history ? (
                <div className="rolehint" style={{ marginTop: 6 }}>
                  Memuat riwayat…
                </div>
              ) : history.timeline.length === 0 ? (
                <div className="rolehint" style={{ marginTop: 6 }}>
                  Belum ada perubahan tercatat.
                </div>
              ) : (
                <ul className="timeline">
                  {history.timeline.map((e, i) => (
                    <li key={i}>
                      <span className="ticon">{e.icon}</span>
                      <div>
                        <div className="ttitle">{e.title}</div>
                        {e.detail && <div className="tdetail">{e.detail}</div>}
                        <div className="tmeta">
                          {e.dateLabel} · {e.actor}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            {canFu && (
              <div style={{ borderTop: "1px solid var(--line)", paddingTop: 12, marginTop: 4 }}>
                <div className="frow">
                  <div className="field">
                    <label>Catatan follow-up</label>
                    <input
                      value={fuNote}
                      onChange={(e) => setFuNote(e.target.value)}
                      placeholder="cth: sudah hitung KPR"
                    />
                  </div>
                  <div className="field">
                    <label>{LEAD_FIELD_LABEL}</label>
                    <select value={fuStatus} onChange={(e) => setFuStatus(e.target.value)}>
                      <option value="COLD">Cold</option>
                      <option value="WARM">Warm</option>
                      <option value="HOT">Hot</option>
                    </select>
                  </div>
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <button className="btn ghost sm" onClick={() => addFollowUp(sel)} disabled={busy}>
                    ＋ Tambah Follow-up
                  </button>
                  <span style={{ flex: 1 }} />
                  <button className="btn ghost sm danger" onClick={() => notInterested(sel)} disabled={busy}>
                    🚫 Tidak Berminat
                  </button>
                  {idx < STAGE_INDEX["NUP"] && (
                    <button className="btn ghost sm" onClick={() => convertStage(sel, "NUP")} disabled={busy}>
                      → NUP
                    </button>
                  )}
                  {idx < STAGE_INDEX["UTJ"] && (
                    <button className="btn sm" onClick={() => convertStage(sel, "UTJ")} disabled={busy}>
                      → UTJ
                    </button>
                  )}
                </div>
                <p className="rolehint" style={{ marginTop: 8 }}>
                  Pilih <b>NUP</b> atau <b>UTJ</b> saat lead lanjut — customer pindah ke tahap itu &amp;
                  status lead jadi <b>Done</b>. <b>Tidak Berminat</b> = keluarin dari pipeline (masuk
                  Pipeline Gagal).
                </p>
              </div>
            )}
            {sel.dead && (
              <div className="notice" style={{ marginTop: 12, background: "#fdecea", borderColor: "#f1c4bb" }}>
                <span>🚫</span>
                <div>Customer ini ditandai <b>tidak berminat</b> — sudah keluar dari pipeline aktif &amp; masuk Pipeline Gagal.</div>
              </div>
            )}
          </>
        )}
      </Modal>
    </>
  );
}
