"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { LeadStatus } from "@prisma/client";
import type { FormOptions } from "@/lib/options";
import type { LeadRow, LeadSummary } from "@/lib/leads";
import { LEAD_STATUS_LABEL, LEAD_STATUS_ORDER } from "@/lib/constants";
import { Modal } from "@/components/Modal";
import { Combobox } from "@/components/Combobox";
import { LeadStatusBadge } from "@/components/badges";
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
  const [fKoor, setFKoor] = useState<string>("all");

  const [editing, setEditing] = useState<LeadRow | null>(null);
  const [convert, setConvert] = useState<LeadRow | null>(null);
  const [adding, setAdding] = useState(false);

  const koorOpts = useMemo(
    () => [...new Set(leads.map((l) => l.koor))].filter((k) => k !== "-").sort((a, b) => a.localeCompare(b)),
    [leads],
  );

  const rows = useMemo(() => {
    const s = q.trim().toLowerCase();
    return leads.filter((l) => {
      if (fStatus !== "all" && l.status !== fStatus) return false;
      if (fKoor !== "all" && l.koor !== fKoor) return false;
      if (s && ![l.nama, l.phone, l.source, l.campaign, l.minat, l.note, l.koor].join(" ").toLowerCase().includes(s))
        return false;
      return true;
    });
  }, [leads, q, fStatus, fKoor]);

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
        <span style={{ flex: 1 }} />
        {isManager && (
          <button className="btn" onClick={() => setAdding(true)}>
            ＋ Tambah Lead
          </button>
        )}
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
              <div className="field" style={{ minWidth: 150 }}>
                <label>Status</label>
                <select value={fStatus} onChange={(e) => setFStatus(e.target.value)}>
                  <option value="all">Semua status</option>
                  {LEAD_STATUS_ORDER.map((s) => (
                    <option key={s} value={s}>{LEAD_STATUS_LABEL[s]}</option>
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
                          Belum ada lead{fStatus !== "all" || fKoor !== "all" || q ? " sesuai filter" : ""}.
                          Leads akan muncul di sini begitu MarComm mengirimkannya.
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
                        <td>{l.minat ?? "-"}</td>
                        <td><LeadStatusBadge s={l.status} /></td>
                        <td style={{ whiteSpace: "nowrap" }}>{l.followUpDate ?? "-"}</td>
                        <td style={{ maxWidth: 220, whiteSpace: "normal" }}>{l.note ?? "-"}</td>
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

      {adding && (
        <AddLeadModal
          options={options}
          onClose={() => setAdding(false)}
          onSaved={() => {
            setAdding(false);
            router.refresh();
          }}
        />
      )}
    </>
  );
}

function Ringkasan({ summary, isManager }: { summary: LeadSummary; isManager: boolean }) {
  const statusMap = new Map(summary.byStatus.map((s) => [s.status, s.count]));
  return (
    <>
      <div className="grid g4" style={{ marginBottom: 16 }}>
        <div className="card kpi">
          <div className="lab">Total Leads</div>
          <div className="val">{summary.total}</div>
        </div>
        {summary.bySource.slice(0, 3).map((s) => (
          <div className="card kpi" key={s.source}>
            <div className="lab">{s.source}</div>
            <div className="val">{s.count}</div>
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="pad">
          <h3>Kondisi Leads (funnel)</h3>
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
  const [status, setStatus] = useState<string>(lead.status);
  const [followUpDate, setFollowUpDate] = useState(lead.followUpDate ?? "");
  const [note, setNote] = useState(lead.note ?? "");

  async function save() {
    setBusy(true);
    const res = await fetch(`/sales/api/leads/${lead.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        minat: minat.trim() || null,
        status,
        followUpDate: followUpDate || null,
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
      <div className="frow">
        <div className="field">
          <label>Minat</label>
          <input value={minat} onChange={(e) => setMinat(e.target.value)} placeholder="cth: cluster Camar, 2 lantai…" />
        </div>
        <div className="field">
          <label>Status</label>
          <select value={status} onChange={(e) => setStatus(e.target.value)}>
            {LEAD_STATUS_ORDER.map((s) => (
              <option key={s} value={s}>{LEAD_STATUS_LABEL[s]}</option>
            ))}
          </select>
        </div>
      </div>
      <div className="field">
        <label>Follow-up (tanggal)</label>
        <input type="date" value={followUpDate} onChange={(e) => setFollowUpDate(e.target.value)} />
      </div>
      <div className="field">
        <label>Note</label>
        <textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="catatan hasil follow-up…" />
      </div>
      <p className="rolehint">
        💡 “→ Walk-In” di tabel akan membuat customer baru di pipeline dari lead ini.
      </p>
    </Modal>
  );
}

// Tambah lead manual (Management) — untuk uji coba / input sementara sebelum
// integrasi MarComm aktif.
function AddLeadModal({
  options,
  onClose,
  onSaved,
}: {
  options: FormOptions;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [f, setF] = useState({
    nama: "",
    phone: "",
    source: "Meta",
    campaign: "",
    tanggal: new Date().toLocaleDateString("en-CA"),
    coordinatorId: "",
  });

  async function save() {
    if (!f.nama.trim()) return toast("Nama lead wajib diisi");
    setBusy(true);
    const res = await fetch("/sales/api/leads", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        nama: f.nama.trim(),
        phone: f.phone.trim() || null,
        source: f.source.trim() || null,
        campaign: f.campaign.trim() || null,
        tanggal: f.tanggal,
        coordinatorId: f.coordinatorId || null,
      }),
    });
    setBusy(false);
    if (res.ok) {
      toast("✓ Lead ditambahkan");
      onSaved();
    } else {
      toast((await res.json().catch(() => ({}))).error ?? "Gagal menyimpan");
    }
  }

  return (
    <Modal
      title="Tambah Lead"
      open
      onClose={onClose}
      footer={
        <>
          <button className="btn ghost" onClick={onClose}>Batal</button>
          <button className="btn" onClick={save} disabled={busy}>{busy ? "Menyimpan…" : "Simpan"}</button>
        </>
      }
    >
      <div className="notice" style={{ marginBottom: 12, background: "#fbedd8", borderColor: "#e9b877" }}>
        <span>✋</span>
        <div><b>Input manual sementara.</b> Nanti leads masuk otomatis dari MarComm.</div>
      </div>
      <div className="frow">
        <div className="field">
          <label>Nama</label>
          <input value={f.nama} onChange={(e) => setF({ ...f, nama: e.target.value })} placeholder="cth: Budi" />
        </div>
        <div className="field">
          <label>No. HP</label>
          <input value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} placeholder="08…" />
        </div>
      </div>
      <div className="frow">
        <div className="field">
          <label>Sumber</label>
          <select value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })}>
            <option value="Meta">Meta</option>
            <option value="TikTok">TikTok</option>
            <option value="Lainnya">Lainnya</option>
          </select>
        </div>
        <div className="field">
          <label>Tanggal masuk</label>
          <input type="date" value={f.tanggal} onChange={(e) => setF({ ...f, tanggal: e.target.value })} />
        </div>
      </div>
      <div className="frow">
        <div className="field">
          <label>Kampanye</label>
          <input value={f.campaign} onChange={(e) => setF({ ...f, campaign: e.target.value })} placeholder="opsional" />
        </div>
        <div className="field">
          <label>Sales Koor</label>
          <Combobox
            value={f.coordinatorId}
            onChange={(v) => setF({ ...f, coordinatorId: v })}
            options={options.coordinators.map((c) => ({ value: c.id, label: c.name }))}
            allLabel="— pilih koor —"
            allValue=""
          />
        </div>
      </div>
    </Modal>
  );
}
