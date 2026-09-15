"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { LeadFollowUp, LeadStatus } from "@prisma/client";
import type { FormOptions } from "@/lib/options";
import type { LeadRow, LeadSummary } from "@/lib/leads";
import {
  LEAD_FOLLOWUP_LABEL,
  LEAD_FOLLOWUP_ORDER,
  LEAD_STATUS_LABEL,
  LEAD_STATUS_ORDER,
} from "@/lib/constants";
import { Modal } from "@/components/Modal";
import { LeadFollowUpBadge, LeadStatusBadge } from "@/components/badges";
import { WalkInForm } from "@/components/forms/WalkInForm";
import { toast } from "@/components/Toast";

type Tab = "ringkasan" | "daftar";

export function LeadsClient({
  leads,
  summary,
  options,
  isManager,
  myKoor,
}: {
  leads: LeadRow[];
  summary: LeadSummary;
  options: FormOptions;
  isManager: boolean;
  myKoor: string | null;
}) {
  const router = useRouter();
  const [tab, setTab] = useState<Tab>("daftar");
  const [q, setQ] = useState("");
  const [fStatus, setFStatus] = useState<string>("all");
  const [fFollowUp, setFFollowUp] = useState<string>("all");
  const [fKoor, setFKoor] = useState<string>("all");

  const [editing, setEditing] = useState<LeadRow | null>(null);
  const [convert, setConvert] = useState<LeadRow | null>(null);

  const koorOpts = useMemo(
    () => [...new Set(leads.map((l) => l.koor))].filter((k) => k !== "-").sort((a, b) => a.localeCompare(b)),
    [leads],
  );

  const rows = useMemo(() => {
    const s = q.trim().toLowerCase();
    return leads.filter((l) => {
      if (fStatus !== "all" && l.status !== fStatus) return false;
      if (fFollowUp !== "all" && l.followUp !== fFollowUp) return false;
      if (fKoor !== "all" && l.koor !== fKoor) return false;
      if (s && ![l.nama, l.phone, l.source, l.campaign, l.minat, l.note, l.koor].join(" ").toLowerCase().includes(s))
        return false;
      return true;
    });
  }, [leads, q, fStatus, fFollowUp, fKoor]);

  return (
    <>
      <div className="h2">
        Leads {myKoor && <span className="ft">Koor: {myKoor}</span>}
      </div>
      <div className="lead">
        Leads dikirim otomatis dari <b>MarComm</b> (Meta / TikTok / dsb). Sales Koor meng-update
        kondisi tiap lead — <b>minat, status, follow-up, note</b> — lalu bisa dijadikan Walk-In
        untuk masuk pipeline.{" "}
        {isManager ? (
          <b>Kamu (Management) melihat semua koor.</b>
        ) : (
          <b>Kamu hanya melihat lead milikmu sendiri.</b>
        )}
      </div>

      <div className="actions">
        <div className="seg">
          <button className={`seg-btn ${tab === "daftar" ? "on" : ""}`} onClick={() => setTab("daftar")}>
            Daftar Leads
          </button>
          <button className={`seg-btn ${tab === "ringkasan" ? "on" : ""}`} onClick={() => setTab("ringkasan")}>
            Ringkasan
          </button>
        </div>
        <span className="pill" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
          🔌 Leads masuk otomatis dari MarComm
        </span>
      </div>

      {tab === "ringkasan" ? (
        <Ringkasan summary={summary} isManager={isManager} />
      ) : (
        <>
          <div className="card" style={{ marginBottom: 14 }}>
            <div className="pad" style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end" }}>
              <div className="field" style={{ flex: "1 1 220px", minWidth: 180 }}>
                <label>Cari</label>
                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="nama / no HP / sumber / catatan…" />
              </div>
              <div className="field" style={{ minWidth: 140 }}>
                <label>Status</label>
                <select value={fStatus} onChange={(e) => setFStatus(e.target.value)}>
                  <option value="all">Semua status</option>
                  {LEAD_STATUS_ORDER.map((s) => (
                    <option key={s} value={s}>{LEAD_STATUS_LABEL[s]}</option>
                  ))}
                </select>
              </div>
              <div className="field" style={{ minWidth: 140 }}>
                <label>Follow-up</label>
                <select value={fFollowUp} onChange={(e) => setFFollowUp(e.target.value)}>
                  <option value="all">Semua follow-up</option>
                  {LEAD_FOLLOWUP_ORDER.map((s) => (
                    <option key={s} value={s}>{LEAD_FOLLOWUP_LABEL[s]}</option>
                  ))}
                </select>
              </div>
              {isManager && (
                <div className="field" style={{ minWidth: 150 }}>
                  <label>Sales Koor</label>
                  <select value={fKoor} onChange={(e) => setFKoor(e.target.value)}>
                    <option value="all">Semua koor</option>
                    {koorOpts.map((k) => (
                      <option key={k} value={k}>{k}</option>
                    ))}
                  </select>
                </div>
              )}
              <span className="pill" style={{ alignSelf: "center" }}>{rows.length} lead</span>
            </div>
          </div>

          <div className="card">
            <div className="pad">
              <div className="tablewrap" style={{ maxHeight: 560, overflow: "auto" }}>
                <table>
                  <thead>
                    <tr>
                      <th>Tanggal</th>
                      <th>Nama</th>
                      <th>Kontak</th>
                      <th>Sumber</th>
                      {isManager && <th>Koor</th>}
                      <th>Minat</th>
                      <th>Status</th>
                      <th>Follow-up</th>
                      <th>Note</th>
                      <th style={{ textAlign: "right" }}>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 && (
                      <tr>
                        <td colSpan={isManager ? 10 : 9} style={{ color: "var(--sub)" }}>
                          Belum ada lead
                          {fStatus !== "all" || fFollowUp !== "all" || fKoor !== "all" || q
                            ? " sesuai filter"
                            : ""}
                          . Leads akan muncul di sini begitu MarComm mengirimkannya.
                        </td>
                      </tr>
                    )}
                    {rows.map((l) => (
                      <tr key={l.id}>
                        <td style={{ whiteSpace: "nowrap" }}>{l.tanggal}</td>
                        <td><b>{l.nama}</b></td>
                        <td style={{ whiteSpace: "nowrap" }}>{l.phone ?? "-"}</td>
                        <td>{l.source ?? "-"}{l.campaign ? <div style={{ color: "var(--sub)", fontSize: 12 }}>{l.campaign}</div> : null}</td>
                        {isManager && <td>{l.koor}</td>}
                        <td>{l.minat ?? <span style={{ color: "var(--sub)" }}>–</span>}</td>
                        <td><LeadStatusBadge s={l.status} /></td>
                        <td style={{ whiteSpace: "nowrap" }}><LeadFollowUpBadge f={l.followUp} /></td>
                        <td style={{ maxWidth: 220, whiteSpace: "normal" }}>{l.note ?? <span style={{ color: "var(--sub)" }}>–</span>}</td>
                        <td style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                          <button className="btn ghost sm" onClick={() => setEditing(l)}>✏️ Update</button>{" "}
                          {l.converted ? (
                            <span className="badge b-green">Walk-In</span>
                          ) : (
                            <button className="btn sm" onClick={() => setConvert(l)}>→ Walk-In</button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </>
      )}

      {editing && (
        <EditLeadModal
          lead={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            router.refresh();
          }}
        />
      )}

      {convert && (
        <WalkInForm
          options={options}
          open
          onClose={() => setConvert(null)}
          prefill={{ nama: convert.nama, channel: "LEADS", sumberInformasi: convert.source ?? undefined }}
          onCreated={async (dealId) => {
            await fetch(`/sales/api/leads/${convert.id}/convert`, {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({ dealId }),
            });
            router.refresh();
          }}
        />
      )}
    </>
  );
}

function Ringkasan({ summary, isManager }: { summary: LeadSummary; isManager: boolean }) {
  const statusMap = new Map(summary.byStatus.map((s) => [s.status, s.count]));
  const fuMap = new Map(summary.byFollowUp.map((s) => [s.followUp, s.count]));
  return (
    <>
      <div className="grid g4" style={{ marginBottom: 16 }}>
        <div className="card kpi">
          <div className="lab">Total Leads</div>
          <div className="val">{summary.total}</div>
        </div>
        {summary.bySource.slice(0, 2).map((s) => (
          <div className="card kpi" key={s.source}>
            <div className="lab">{s.source}</div>
            <div className="val">{s.count}</div>
          </div>
        ))}
        <div className="card kpi">
          <div className="lab">Belum diisi sales</div>
          <div className="val">{summary.belumDiisi}</div>
        </div>
      </div>

      <div className="grid g2" style={{ marginBottom: 16 }}>
        <div className="card">
          <div className="pad">
            <h3>Status (minat)</h3>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>{LEAD_STATUS_ORDER.map((s) => <th key={s}>{LEAD_STATUS_LABEL[s]}</th>)}</tr>
                </thead>
                <tbody>
                  <tr>
                    {LEAD_STATUS_ORDER.map((s) => (
                      <td key={s}><b>{statusMap.get(s as LeadStatus) ?? 0}</b></td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Follow-up</h3>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>{LEAD_FOLLOWUP_ORDER.map((s) => <th key={s}>{LEAD_FOLLOWUP_LABEL[s]}</th>)}</tr>
                </thead>
                <tbody>
                  <tr>
                    {LEAD_FOLLOWUP_ORDER.map((s) => (
                      <td key={s}><b>{fuMap.get(s as LeadFollowUp) ?? 0}</b></td>
                    ))}
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      {isManager && summary.months.length > 0 && (
        <div className="card">
          <div className="pad">
            <h3>Leads per Bulan × Sales Koor</h3>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>Bulan</th>
                    {summary.koors.map((k) => <th key={k}>{k}</th>)}
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.months.map((m) => {
                    const row = summary.matrix[m] ?? {};
                    const total = summary.koors.reduce((a, k) => a + (row[k] ?? 0), 0);
                    return (
                      <tr key={m}>
                        <td><b>{m}</b></td>
                        {summary.koors.map((k) => <td key={k}>{row[k] ?? 0}</td>)}
                        <td><b>{total}</b></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

// Update kondisi lead — diisi Sales Koor.
function EditLeadModal({
  lead,
  onClose,
  onSaved,
}: {
  lead: LeadRow;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [minat, setMinat] = useState(lead.minat ?? "");
  const [status, setStatus] = useState<string>(lead.status ?? "");
  const [followUp, setFollowUp] = useState<string>(lead.followUp ?? "");
  const [note, setNote] = useState(lead.note ?? "");

  async function save() {
    setBusy(true);
    const res = await fetch(`/sales/api/leads/${lead.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        minat: minat.trim() || null,
        status: status || null,
        followUp: followUp || null,
        note: note.trim() || null,
      }),
    });
    setBusy(false);
    if (res.ok) {
      toast("✓ Kondisi lead diperbarui");
      onSaved();
    } else {
      toast((await res.json().catch(() => ({}))).error ?? "Gagal menyimpan");
    }
  }

  return (
    <Modal
      title={`Update Lead — ${lead.nama}`}
      open
      onClose={onClose}
      footer={
        <>
          <button className="btn ghost" onClick={onClose}>Batal</button>
          <button className="btn" onClick={save} disabled={busy}>{busy ? "Menyimpan…" : "Simpan"}</button>
        </>
      }
    >
      <div className="field">
        <label>Minat <span style={{ color: "var(--sub)", fontWeight: 400 }}>(tulis bebas)</span></label>
        <input value={minat} onChange={(e) => setMinat(e.target.value)} placeholder="cth: cluster Camar, 2 lantai, budget 800jt…" />
      </div>
      <div className="frow">
        <div className="field">
          <label>Status</label>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">— belum diisi —</option>
            {LEAD_STATUS_ORDER.map((s) => (
              <option key={s} value={s}>{LEAD_STATUS_LABEL[s]}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>Follow-up</label>
          <select value={followUp} onChange={(e) => setFollowUp(e.target.value)}>
            <option value="">— belum diisi —</option>
            {LEAD_FOLLOWUP_ORDER.map((s) => (
              <option key={s} value={s}>{LEAD_FOLLOWUP_LABEL[s]}</option>
            ))}
          </select>
        </div>
      </div>
      <div className="field">
        <label>Note <span style={{ color: "var(--sub)", fontWeight: 400 }}>(tulis bebas)</span></label>
        <textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="catatan hasil follow-up…" />
      </div>
      <p className="rolehint">
        💡 “→ Walk-In” di tabel akan membuat customer baru di pipeline dari lead ini (follow-up
        otomatis jadi <b>WI</b>).
      </p>
    </Modal>
  );
}
