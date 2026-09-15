"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { FormOptions } from "@/lib/options";
import { Modal } from "@/components/Modal";
import { Combobox } from "@/components/Combobox";
import { toast } from "@/components/Toast";
import { LEAD_FIELD_LABEL, SUMBER_INFORMASI } from "@/lib/constants";

export interface WalkInEdit {
  id: string;
  nama: string;
  tanggalWi?: string; // YYYY-MM-DD
  channel: string;
  agencyId: string;
  salesId: string;
  coordinatorId: string | null;
  clusterId: string | null;
  phaseId: string | null;
  unitTypeId: string | null;
  statusLead: string;
  sumberInformasi: string | null;
  catatan: string | null;
}

// Shared Walk-In modal — creates a new deal (POST) or edits one (PATCH when
// `deal` is passed). Koordinator auto-derives from bendera when left kosong.
export function WalkInForm({
  options,
  open,
  onClose,
  deal,
}: {
  options: FormOptions;
  open: boolean;
  onClose: () => void;
  deal?: WalkInEdit;
}) {
  const router = useRouter();
  const isEdit = !!deal;
  const [busy, setBusy] = useState(false);
  const [tanggal, setTanggal] = useState(deal?.tanggalWi ?? new Date().toLocaleDateString("en-CA"));
  const [clusterId, setClusterId] = useState(deal?.clusterId ?? options.clusters[0]?.id ?? "");

  // sumber informasi: known option, or "Other" with free text
  const initSumber = deal?.sumberInformasi ?? "";
  const initKnown = SUMBER_INFORMASI.includes(initSumber);
  const [sumberSel, setSumberSel] = useState(initSumber ? (initKnown ? initSumber : "Other") : "");
  const [sumberOther, setSumberOther] = useState(initKnown ? "" : initSumber);

  const [form, setForm] = useState({
    nama: deal?.nama ?? "",
    channel: deal?.channel ?? "SITE",
    agencyId: deal?.agencyId ?? options.agencies[0]?.id ?? "",
    salesId: deal?.salesId ?? options.sales[0]?.id ?? "",
    coordinatorId: deal?.coordinatorId ?? "",
    phaseId: deal?.phaseId ?? "",
    unitTypeId: deal?.unitTypeId ?? "",
    statusLead: deal?.statusLead ?? "COLD",
    catatan: deal?.catatan ?? "",
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
    const sumberInformasi = sumberSel === "Other" ? sumberOther.trim() : sumberSel;
    const body = {
      nama: form.nama,
      tanggalWi: tanggal,
      channel: form.channel,
      agencyId: form.agencyId,
      salesId: form.salesId,
      coordinatorId: form.coordinatorId || null,
      clusterId,
      phaseId: form.phaseId || null,
      unitTypeId: form.unitTypeId || null,
      statusLead: form.statusLead,
      sumberInformasi: sumberInformasi || null,
      catatan: form.catatan || null,
    };
    const res = await fetch(isEdit ? `/sales/api/deals/${deal!.id}` : "/sales/api/deals", {
      method: isEdit ? "PATCH" : "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    setBusy(false);
    if (res.ok) {
      onClose();
      toast(isEdit ? "✓ Perubahan disimpan" : "✓ " + form.nama + " → Pipeline & Daily Report");
      router.refresh();
    } else {
      const j = await res.json().catch(() => ({}));
      toast(j.error ?? "Gagal menyimpan");
    }
  }

  return (
    <Modal
      title={isEdit ? "Edit Walk-In" : "Tambah Walk-In"}
      open={open}
      onClose={onClose}
      footer={
        <>
          <button className="btn ghost" onClick={onClose}>Batal</button>
          <button className="btn" onClick={save} disabled={busy}>
            {busy ? "Menyimpan…" : "Simpan"}
          </button>
        </>
      }
    >
      <div className="frow">
        <div className="field">
          <label>Nama Customer</label>
          <input value={form.nama} onChange={(e) => setForm({ ...form, nama: e.target.value })} placeholder="cth: Budi" />
        </div>
        <div className="field">
          <label>Tanggal WI</label>
          <input type="date" value={tanggal} onChange={(e) => setTanggal(e.target.value)} />
        </div>
      </div>
      <div className="frow">
        <div className="field">
          <label>Channel WI</label>
          <select value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
            <option value="SITE">Site</option>
            <option value="PAMERAN">Pameran</option>
            <option value="JANJIAN">Janjian</option>
            <option value="LEADS">Leads</option>
          </select>
        </div>
        <div className="field" />
      </div>
      <div className="frow">
        <div className="field">
          <label>Agent (bendera)</label>
          <Combobox
            value={form.agencyId}
            onChange={(v) => setForm({ ...form, agencyId: v })}
            options={options.agencies.map((a) => ({ value: a.id, label: a.name }))}
            allLabel="— cari agent —"
            allValue=""
          />
        </div>
        <div className="field">
          <label>Sales</label>
          <Combobox
            value={form.salesId}
            onChange={(v) => setForm({ ...form, salesId: v })}
            options={options.sales.map((s) => ({ value: s.id, label: s.name }))}
            allLabel="— cari sales —"
            allValue=""
          />
        </div>
      </div>
      <div className="frow">
        <div className="field">
          <label>Sales Koor</label>
          <Combobox
            value={form.coordinatorId}
            onChange={(v) => setForm({ ...form, coordinatorId: v })}
            options={options.coordinators.map((c) => ({ value: c.id, label: c.name }))}
            allLabel="Auto dari bendera"
            allValue=""
          />
        </div>
        <div className="field">
          <label>Sumber Informasi</label>
          <select value={sumberSel} onChange={(e) => setSumberSel(e.target.value)}>
            <option value="">— pilih —</option>
            {SUMBER_INFORMASI.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
            <option value="Other">Other…</option>
          </select>
          {sumberSel === "Other" && (
            <input
              style={{ marginTop: 6 }}
              value={sumberOther}
              onChange={(e) => setSumberOther(e.target.value)}
              placeholder="Sumber lain…"
            />
          )}
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
              <option key={c.id} value={c.id}>{c.name}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>Type diminati</label>
          <select value={form.unitTypeId} onChange={(e) => setForm({ ...form, unitTypeId: e.target.value })}>
            <option value="">— pilih —</option>
            {cluster?.types.map((t) => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </div>
      </div>
      <div className="field">
        <label>{LEAD_FIELD_LABEL}</label>
        <select value={form.statusLead} onChange={(e) => setForm({ ...form, statusLead: e.target.value })}>
          <option value="COLD">Cold</option>
          <option value="WARM">Warm</option>
          <option value="HOT">Hot</option>
          <option value="DONE">Done</option>
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
        💡 Sales Koor bisa dikosongin → otomatis dari bendera (rotasi). Sekali simpan → Pipeline + Daily Report + Summary.
      </p>
    </Modal>
  );
}
