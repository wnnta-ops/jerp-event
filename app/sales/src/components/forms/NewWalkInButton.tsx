"use client";

import { useRouter } from "next/navigation";
import { useMemo, useState } from "react";
import type { FormOptions } from "@/lib/options";
import { Modal } from "@/components/Modal";
import { toast } from "@/components/Toast";
import { LEAD_FIELD_LABEL } from "@/lib/constants";

export function NewWalkInButton({
  options,
  label = "＋ Walk-In",
  ghost = false,
  disabled = false,
}: {
  options: FormOptions;
  label?: string;
  ghost?: boolean;
  disabled?: boolean;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [clusterId, setClusterId] = useState(options.clusters[0]?.id ?? "");
  const [form, setForm] = useState({
    nama: "",
    channel: "SITE",
    agencyId: options.agencies[0]?.id ?? "",
    salesId: options.sales[0]?.id ?? "",
    phaseId: "",
    unitTypeId: "",
    statusLead: "COLD",
    catatan: "",
  });

  const cluster = useMemo(
    () => options.clusters.find((c) => c.id === clusterId),
    [clusterId, options.clusters],
  );

  async function save() {
    if (!form.nama.trim()) {
      toast("Nama customer wajib diisi");
      return;
    }
    setBusy(true);
    const res = await fetch("/sales/api/deals", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        nama: form.nama,
        channel: form.channel,
        agencyId: form.agencyId,
        salesId: form.salesId,
        clusterId,
        phaseId: form.phaseId || null,
        unitTypeId: form.unitTypeId || null,
        statusLead: form.statusLead,
        catatan: form.catatan || null,
      }),
    });
    setBusy(false);
    if (res.ok) {
      setOpen(false);
      setForm({ ...form, nama: "" });
      toast("✓ " + form.nama + " → Pipeline & Daily Report");
      router.refresh();
    } else {
      const j = await res.json().catch(() => ({}));
      toast(j.error ?? "Gagal menyimpan");
    }
  }

  return (
    <>
      <button
        className={`btn ${ghost ? "ghost" : ""}`}
        disabled={disabled}
        onClick={() => setOpen(true)}
      >
        {label}
      </button>
      <Modal
        title="Tambah Walk-In"
        open={open}
        onClose={() => setOpen(false)}
        footer={
          <>
            <button className="btn ghost" onClick={() => setOpen(false)}>
              Batal
            </button>
            <button className="btn" onClick={save} disabled={busy}>
              {busy ? "Menyimpan…" : "Simpan"}
            </button>
          </>
        }
      >
        <div className="frow">
          <div className="field">
            <label>Nama Customer</label>
            <input
              value={form.nama}
              onChange={(e) => setForm({ ...form, nama: e.target.value })}
              placeholder="cth: Budi"
            />
          </div>
          <div className="field">
            <label>Channel WI</label>
            <select
              value={form.channel}
              onChange={(e) => setForm({ ...form, channel: e.target.value })}
            >
              <option value="SITE">Site</option>
              <option value="PAMERAN">Pameran</option>
              <option value="JANJIAN">Janjian</option>
            </select>
          </div>
        </div>
        <div className="frow">
          <div className="field">
            <label>Agent (bendera)</label>
            <select
              value={form.agencyId}
              onChange={(e) => setForm({ ...form, agencyId: e.target.value })}
            >
              {options.agencies.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Sales</label>
            <select
              value={form.salesId}
              onChange={(e) => setForm({ ...form, salesId: e.target.value })}
            >
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
            <label>Cluster</label>
            <select
              value={clusterId}
              onChange={(e) => {
                setClusterId(e.target.value);
                setForm({ ...form, phaseId: "", unitTypeId: "" });
              }}
            >
              {options.clusters.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Type diminati</label>
            <select
              value={form.unitTypeId}
              onChange={(e) => setForm({ ...form, unitTypeId: e.target.value })}
            >
              <option value="">— pilih —</option>
              {cluster?.types.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>
        </div>
        <div className="field">
          <label>{LEAD_FIELD_LABEL}</label>
          <select
            value={form.statusLead}
            onChange={(e) => setForm({ ...form, statusLead: e.target.value })}
          >
            <option value="COLD">Cold</option>
            <option value="WARM">Warm</option>
            <option value="HOT">Hot</option>
          </select>
        </div>
        <div className="field">
          <label>Deskripsi / Catatan</label>
          <textarea
            rows={2}
            value={form.catatan}
            onChange={(e) => setForm({ ...form, catatan: e.target.value })}
            placeholder="cth: minat type Camar, minta perhitungan KPR, tunggu pasangan…"
          />
        </div>
        <p className="rolehint">
          💡 Koordinator terisi otomatis dari bendera (rotasi). Sekali simpan → Pipeline + Daily
          Report + Summary.
        </p>
      </Modal>
    </>
  );
}
