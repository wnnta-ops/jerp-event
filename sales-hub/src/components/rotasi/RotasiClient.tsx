"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Modal } from "@/components/Modal";
import { toast } from "@/components/Toast";

export interface CoordGroup {
  id: string;
  name: string;
  agencies: string[];
}

export function RotasiClient({
  groups,
  coordinators,
  agencies,
  canEdit,
}: {
  groups: CoordGroup[];
  coordinators: { id: string; name: string }[];
  agencies: { id: string; name: string }[];
  canEdit: boolean;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [agencyId, setAgencyId] = useState(agencies[0]?.id ?? "");
  const [coordinatorId, setCoordinatorId] = useState(coordinators[0]?.id ?? "");
  const [effectiveFrom, setEffectiveFrom] = useState("");

  async function save() {
    setBusy(true);
    const res = await fetch("/api/rotation", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ agencyId, coordinatorId, effectiveFrom: effectiveFrom || undefined }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ Rotasi diperbarui — tersimpan dgn histori tanggal");
      setOpen(false);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  return (
    <>
      {canEdit && (
        <div className="actions">
          <button className="btn" onClick={() => setOpen(true)}>
            ✏️ Ubah rotasi bendera
          </button>
        </div>
      )}

      <div className="grid g4" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(240px, 1fr))" }}>
        {groups.map((g) => (
          <div className="card" key={g.id}>
            <div className="pad">
              <h3 style={{ justifyContent: "space-between" }}>
                <span>👤 {g.name}</span>
                <span className="pill">{g.agencies.length}</span>
              </h3>
              <div>
                {g.agencies.length === 0 && <span className="rolehint">— belum ada bendera —</span>}
                {g.agencies.map((a) => (
                  <span className="tag-flag" key={a}>
                    {a}
                  </span>
                ))}
              </div>
              {!canEdit && (
                <div style={{ marginTop: 12 }}>
                  <span className="pill">🔒 view only</span>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>

      <Modal
        title="Ubah rotasi bendera"
        open={open}
        onClose={() => setOpen(false)}
        footer={
          <>
            <button className="btn ghost" onClick={() => setOpen(false)}>
              Batal
            </button>
            <button className="btn" onClick={save} disabled={busy}>
              Simpan
            </button>
          </>
        }
      >
        <div className="field">
          <label>Bendera (agen)</label>
          <select value={agencyId} onChange={(e) => setAgencyId(e.target.value)}>
            {agencies.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>Koordinator baru</label>
          <select value={coordinatorId} onChange={(e) => setCoordinatorId(e.target.value)}>
            {coordinators.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>Berlaku mulai (opsional — default hari ini)</label>
          <input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
        </div>
        <p className="rolehint">
          Assignment sebelumnya ditutup otomatis sehari sebelum tanggal ini, jadi report lama tetap
          memakai koordinator yang valid saat itu.
        </p>
      </Modal>
    </>
  );
}
