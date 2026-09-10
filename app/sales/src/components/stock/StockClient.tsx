"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import type { Role, UnitStatus } from "@prisma/client";
import { Modal } from "@/components/Modal";
import { toast } from "@/components/Toast";
import { UnitStatusBadge } from "@/components/badges";
import { UNIT_STATUS_LABEL } from "@/lib/constants";
import { MANUAL_STAGE_INPUT } from "@/lib/features";
import type { FormOptions } from "@/lib/options";

export interface StockRow {
  id: string;
  code: string;
  cluster: string;
  phase: string;
  type: string;
  status: UnitStatus;
  user: string | null;
  sales: string | null;
  agent: string | null;
  bayar: string | null;
  harga: string | null;
  hasBooking: boolean;
}

// Editable statuses (Batal is not a persistent unit status — see "Batalkan").
const EDIT_STATUS = Object.entries(UNIT_STATUS_LABEL).filter(([k]) => k !== "BATAL");

interface UnitHistory {
  bookings: {
    current: boolean;
    nama: string;
    sales: string;
    agent: string;
    bayar: string;
    batal: boolean;
    batalAlasan: string | null;
    tglUtj: string | null;
    tglSpr: string | null;
    tglAkad: string | null;
    batalTanggal: string | null;
  }[];
  logs: { action: string; detail: string; dateLabel: string; actor: string }[];
}

export function StockClient({
  rows,
  options,
  role,
  canEdit,
  filters,
  clusters,
}: {
  rows: StockRow[];
  options: FormOptions;
  role: Role;
  canEdit: boolean;
  filters: { cluster: string; phase: string; status: string; q: string };
  clusters: { name: string; phases: string[] }[];
}) {
  const router = useRouter();
  const params = useSearchParams();
  const [utjUnit, setUtjUnit] = useState<StockRow | null>(null);
  const [editUnit, setEditUnit] = useState<StockRow | null>(null);
  const [detailUnit, setDetailUnit] = useState<StockRow | null>(null);
  const [detail, setDetail] = useState<UnitHistory | null>(null);
  const [busy, setBusy] = useState(false);

  const [utjForm, setUtjForm] = useState({
    nama: "",
    salesId: options.sales[0]?.id ?? "",
    agencyId: options.agencies[0]?.id ?? "",
    caraBayar: "KPR",
    promoId: "",
    bankId: "",
    statusLead: "WARM",
  });

  const [edit, setEdit] = useState({
    status: "PROSES" as UnitStatus,
    nama: "",
    salesId: "",
    agencyId: "",
    caraBayar: "KPR",
  });

  // Load house history when the detail modal opens.
  useEffect(() => {
    if (!detailUnit) {
      setDetail(null);
      return;
    }
    let alive = true;
    setDetail(null);
    fetch(`/sales/api/units/${detailUnit.id}/history`)
      .then((r) => r.json())
      .then((d) => alive && setDetail(d))
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, [detailUnit]);

  function openEdit(s: StockRow) {
    setEdit({
      status: s.status === "BATAL" ? "OPEN_STOCK" : s.status,
      nama: s.user ?? "",
      salesId: options.sales.find((o) => o.name === s.sales)?.id ?? options.sales[0]?.id ?? "",
      agencyId: options.agencies.find((o) => o.name === s.agent)?.id ?? options.agencies[0]?.id ?? "",
      caraBayar: s.bayar === "CASH" ? "CASH" : "KPR",
    });
    setEditUnit(s);
  }

  function setParam(key: string, value: string) {
    const p = new URLSearchParams(params.toString());
    if (value && value !== "all") p.set(key, value);
    else p.delete(key);
    router.push(`/stock?${p.toString()}`);
  }

  const allPhases = [...new Set(clusters.flatMap((c) => c.phases))].sort();

  async function submitUtj() {
    if (!utjForm.nama.trim()) return toast("Nama user wajib diisi");
    setBusy(true);
    const res = await fetch(`/sales/api/units/${utjUnit!.id}/utj`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        nama: utjForm.nama,
        salesId: utjForm.salesId,
        agencyId: utjForm.agencyId,
        caraBayar: utjForm.caraBayar,
        promoId: utjForm.promoId || null,
        bankId: utjForm.bankId || null,
        statusLead: utjForm.statusLead,
      }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ UTJ " + utjUnit!.code + " → stok berkurang, masuk Collection");
      setUtjUnit(null);
      setUtjForm({ ...utjForm, nama: "" });
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  async function submitEdit() {
    setBusy(true);
    const res = await fetch(`/sales/api/units/${editUnit!.id}/update`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        status: edit.status,
        nama: editUnit!.hasBooking ? edit.nama : undefined,
        salesId: editUnit!.hasBooking ? edit.salesId : undefined,
        agencyId: editUnit!.hasBooking ? edit.agencyId : undefined,
        caraBayar: editUnit!.hasBooking ? edit.caraBayar : undefined,
      }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ Unit " + editUnit!.code + " diupdate (tercatat di log)");
      setEditUnit(null);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  async function submitBatal() {
    setBusy(true);
    const res = await fetch(`/sales/api/units/${editUnit!.id}/update`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ batal: true }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ Booking dibatalkan — unit kembali Open Stock");
      setEditUnit(null);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  return (
    <>
      <div className="actions">
        <select value={filters.cluster} onChange={(e) => setParam("cluster", e.target.value)}>
          <option value="all">Semua Cluster</option>
          {clusters.map((c) => (
            <option key={c.name} value={c.name}>
              {c.name}
            </option>
          ))}
        </select>
        <select value={filters.phase} onChange={(e) => setParam("phase", e.target.value)}>
          <option value="all">Semua Phase</option>
          {allPhases.map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
        <select value={filters.status} onChange={(e) => setParam("status", e.target.value)}>
          <option value="all">Semua Status</option>
          {EDIT_STATUS.map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </select>
        <input
          className="search"
          placeholder="🔎 Cari unit / type"
          defaultValue={filters.q}
          onKeyDown={(e) => {
            if (e.key === "Enter") setParam("q", (e.target as HTMLInputElement).value);
          }}
        />
        <span className="rolehint">
          {canEdit && MANUAL_STAGE_INPUT
            ? "Klik kode unit untuk lihat riwayat. UTJ pada unit Open Stock via + UTJ."
            : "Klik kode unit untuk lihat riwayat. Status unit sinkron dari sistem lain (integrasi)."}
        </span>
      </div>

      <div className="card">
        <div className="pad">
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Unit</th>
                  <th>Cluster</th>
                  <th>Phase</th>
                  <th>Type</th>
                  <th>User</th>
                  <th>Sales</th>
                  <th>Agent</th>
                  <th>Bayar</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={10} style={{ color: "var(--sub)" }}>
                      Tidak ada unit untuk filter ini.
                    </td>
                  </tr>
                )}
                {rows.map((s) => (
                  <tr key={s.id}>
                    <td>
                      <button className="linklike" onClick={() => setDetailUnit(s)}>
                        {s.code}
                      </button>
                    </td>
                    <td>{s.cluster}</td>
                    <td>{s.phase}</td>
                    <td>{s.type}</td>
                    <td>{s.user ?? "—"}</td>
                    <td>{s.sales ?? "—"}</td>
                    <td>{s.agent ?? "—"}</td>
                    <td>{s.bayar ?? "—"}</td>
                    <td>
                      <UnitStatusBadge s={s.status} />
                    </td>
                    <td>
                      {canEdit &&
                        (s.status === "OPEN_STOCK" ? (
                          MANUAL_STAGE_INPUT ? (
                            <button className="btn sm" onClick={() => setUtjUnit(s)}>
                              + UTJ
                            </button>
                          ) : null
                        ) : (
                          <button className="btn ghost sm" onClick={() => openEdit(s)}>
                            Update
                          </button>
                        ))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* UTJ modal */}
      <Modal
        title={utjUnit ? `Input UTJ — ${utjUnit.code}` : ""}
        open={!!utjUnit}
        onClose={() => setUtjUnit(null)}
        footer={
          <>
            <button className="btn ghost" onClick={() => setUtjUnit(null)}>
              Batal
            </button>
            <button className="btn" onClick={submitUtj} disabled={busy}>
              Simpan UTJ
            </button>
          </>
        }
      >
        <div className="frow">
          <div className="field">
            <label>Nama User</label>
            <input
              value={utjForm.nama}
              onChange={(e) => setUtjForm({ ...utjForm, nama: e.target.value })}
              placeholder="pembeli"
            />
          </div>
          <div className="field">
            <label>Sales</label>
            <select value={utjForm.salesId} onChange={(e) => setUtjForm({ ...utjForm, salesId: e.target.value })}>
              {options.sales.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
        </div>
        <div className="frow">
          <div className="field">
            <label>Agent (bendera)</label>
            <select value={utjForm.agencyId} onChange={(e) => setUtjForm({ ...utjForm, agencyId: e.target.value })}>
              {options.agencies.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Cara bayar</label>
            <select value={utjForm.caraBayar} onChange={(e) => setUtjForm({ ...utjForm, caraBayar: e.target.value })}>
              <option value="KPR">KPR</option>
              <option value="CASH">Cash</option>
            </select>
          </div>
        </div>
        <div className="frow">
          <div className="field">
            <label>Promo</label>
            <select value={utjForm.promoId} onChange={(e) => setUtjForm({ ...utjForm, promoId: e.target.value })}>
              <option value="">—</option>
              {options.promos.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Bank</label>
            <select value={utjForm.bankId} onChange={(e) => setUtjForm({ ...utjForm, bankId: e.target.value })}>
              <option value="">—</option>
              {options.banks.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
          </div>
        </div>
        <p className="rolehint">
          Simpan → unit jadi PROSES, customer masuk tahap UTJ, muncul di Collection &amp; Daily Report.
        </p>
      </Modal>

      {/* Full edit modal */}
      <Modal
        title={editUnit ? `Update Unit — ${editUnit.code}` : ""}
        open={!!editUnit}
        onClose={() => setEditUnit(null)}
        footer={
          <>
            {MANUAL_STAGE_INPUT && editUnit?.hasBooking && (
              <button
                className="btn ghost"
                style={{ borderColor: "var(--red)", color: "var(--red)", marginRight: "auto" }}
                onClick={submitBatal}
                disabled={busy}
              >
                ✖ Batalkan booking
              </button>
            )}
            <button className="btn ghost" onClick={() => setEditUnit(null)}>
              Tutup
            </button>
            <button className="btn" onClick={submitEdit} disabled={busy}>
              Simpan
            </button>
          </>
        }
      >
        <div className="field">
          <label>Status</label>
          <select value={edit.status} onChange={(e) => setEdit({ ...edit, status: e.target.value as UnitStatus })}>
            {EDIT_STATUS.map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </div>
        {editUnit?.hasBooking && (
          <>
            <div className="field">
              <label>Nama User</label>
              <input value={edit.nama} onChange={(e) => setEdit({ ...edit, nama: e.target.value })} />
            </div>
            <div className="frow">
              <div className="field">
                <label>Sales</label>
                <select value={edit.salesId} onChange={(e) => setEdit({ ...edit, salesId: e.target.value })}>
                  {options.sales.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="field">
                <label>Agent (bendera)</label>
                <select value={edit.agencyId} onChange={(e) => setEdit({ ...edit, agencyId: e.target.value })}>
                  {options.agencies.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <div className="field">
              <label>Cara bayar</label>
              <select value={edit.caraBayar} onChange={(e) => setEdit({ ...edit, caraBayar: e.target.value })}>
                <option value="KPR">KPR</option>
                <option value="CASH">Cash</option>
              </select>
            </div>
          </>
        )}
        <p className="rolehint">
          Semua perubahan tersimpan di <b>log rumah</b> (klik kode unit untuk melihatnya). Status
          otomatis dari alur customer; ini untuk koreksi/override manual.
        </p>
      </Modal>

      {/* Detail / history modal */}
      <Modal
        title={detailUnit ? `Riwayat Rumah — ${detailUnit.code}` : ""}
        open={!!detailUnit}
        onClose={() => setDetailUnit(null)}
        footer={
          <button className="btn ghost" onClick={() => setDetailUnit(null)}>
            Tutup
          </button>
        }
      >
        {detailUnit && (
          <div style={{ display: "flex", gap: 7, flexWrap: "wrap", marginBottom: 12 }}>
            <span className="pill">
              {detailUnit.cluster} · Phase {detailUnit.phase} · {detailUnit.type}
            </span>
            <UnitStatusBadge s={detailUnit.status} />
          </div>
        )}
        {!detail ? (
          <div className="rolehint">Memuat riwayat…</div>
        ) : (
          <>
            <label style={{ fontSize: 11.5, fontWeight: 600, color: "var(--sub)" }}>
              Booking pada rumah ini
            </label>
            {detail.bookings.length === 0 ? (
              <div className="rolehint" style={{ marginTop: 6 }}>
                Belum pernah ada booking.
              </div>
            ) : (
              detail.bookings.map((b, i) => (
                <div
                  key={i}
                  className="lcard"
                  style={{ cursor: "default", borderColor: b.current ? "var(--green)" : b.batal ? "#f2c9c9" : "var(--line)" }}
                >
                  <div className="nm">
                    {b.nama}{" "}
                    {b.current ? (
                      <span className="badge b-green">Aktif</span>
                    ) : b.batal ? (
                      <span className="badge b-red">Batal{b.batalAlasan ? ` · ${b.batalAlasan}` : ""}</span>
                    ) : (
                      <span className="badge b-gray">Riwayat</span>
                    )}
                  </div>
                  <div className="mt">
                    Sales: {b.sales} · Agent: {b.agent} · Bayar: {b.bayar}
                  </div>
                  <div className="mt">
                    {[b.tglUtj && `UTJ ${b.tglUtj}`, b.tglSpr && `SPR ${b.tglSpr}`, b.tglAkad && `Akad ${b.tglAkad}`, b.batalTanggal && `Batal ${b.batalTanggal}`]
                      .filter(Boolean)
                      .join(" · ") || "—"}
                  </div>
                </div>
              ))
            )}

            <label style={{ fontSize: 11.5, fontWeight: 600, color: "var(--sub)", display: "block", marginTop: 12 }}>
              Log perubahan
            </label>
            {detail.logs.length === 0 ? (
              <div className="rolehint" style={{ marginTop: 6 }}>
                Belum ada perubahan tercatat.
              </div>
            ) : (
              <ul className="timeline">
                {detail.logs.map((l, i) => (
                  <li key={i}>
                    <span className="ticon">🛠️</span>
                    <div>
                      <div className="ttitle">{l.detail}</div>
                      <div className="tmeta">
                        {l.dateLabel} · {l.actor}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </Modal>
    </>
  );
}
