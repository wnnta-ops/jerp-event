"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { createAccessRequest } from "@/app/requests/actions";
import { ACCESS_TYPES, ACCESS_TYPE_LABELS, chainSummaryParts, type AccessType } from "@/lib/access";
import { DOC_TYPES, DOC_TYPE_LABELS, type DocType } from "@/lib/enums";

const inputStyle: React.CSSProperties = {
  border: "0.5px solid var(--color-border-secondary)", borderRadius: 8, padding: "8px 10px",
  fontSize: 13, background: "var(--color-background-primary)", color: "var(--color-text-primary)", width: "100%",
};
const labelStyle: React.CSSProperties = { fontSize: 12, fontWeight: 500, color: "var(--color-text-secondary)", display: "block", marginBottom: 5 };

export function NewRequestModal({ open, onClose, prefillItem = "", prefillType = "" }: { open: boolean; onClose: () => void; prefillItem?: string; prefillType?: string }) {
  const router = useRouter();
  const [requestedType, setRequestedType] = useState("");
  const [requestedItem, setRequestedItem] = useState("");
  const [accessType, setAccessType] = useState<AccessType>("view");
  const [purpose, setPurpose] = useState("");
  const [neededBy, setNeededBy] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  // Pre-fill from a document you opened (e.g. drawer "Minta akses") — editable,
  // and the request stays manual/free-text (not bound to that document).
  useEffect(() => {
    if (open) { setRequestedItem(prefillItem); setRequestedType(prefillType); }
  }, [open, prefillItem, prefillType]);

  if (!open) return null;

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    if (!requestedItem.trim()) return setError("Tulis dokumen yang diminta.");
    if (!purpose.trim()) return setError("Tujuan wajib diisi.");
    if (!neededBy) return setError("Tanggal butuh wajib diisi.");
    setBusy(true);
    const fd = new FormData();
    fd.set("requestedItem", requestedItem);
    fd.set("requestedType", requestedType);
    fd.set("accessType", accessType);
    fd.set("purpose", purpose);
    fd.set("neededBy", neededBy);
    const res = await createAccessRequest(fd);
    setBusy(false);
    if (res.ok) { onClose(); router.push(`/requests/${res.id}`); } else setError(res.error);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.4)" }} onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="w-full max-w-[460px] rounded-lg p-5" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <div className="mb-4 flex items-center justify-between">
          <span className="text-[15px] font-medium">Buat permintaan akses</span>
          <button type="button" onClick={onClose} aria-label="Tutup" style={{ color: "var(--color-text-tertiary)" }}><i className="ti ti-x" style={{ fontSize: 18 }} /></button>
        </div>

        <div className="flex flex-col gap-3">
          <div>
            <label style={labelStyle}>Dokumen yang diminta <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <input
              style={inputStyle}
              value={requestedItem}
              onChange={(e) => setRequestedItem(e.target.value)}
              placeholder="cth. Sertifikat HGB unit JRK2 II-11 / Akta Pendirian PT MUP"
            />
            <p className="mt-1 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
              Tulis bebas. Dokumen fisik diserahkan manual oleh Legal setelah disetujui.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label style={labelStyle}>Jenis dokumen <span style={{ color: "var(--color-text-tertiary)", fontWeight: 400 }}>(opsional)</span></label>
              <select style={inputStyle} value={requestedType} onChange={(e) => setRequestedType(e.target.value)}>
                <option value="">— Tidak dipilih —</option>
                {DOC_TYPES.map((t) => <option key={t} value={t}>{DOC_TYPE_LABELS[t as DocType] ?? t}</option>)}
              </select>
            </div>
            <div>
              <label style={labelStyle}>Jenis akses <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
              <select style={inputStyle} value={accessType} onChange={(e) => setAccessType(e.target.value as AccessType)}>
                {ACCESS_TYPES.map((a) => <option key={a} value={a}>{ACCESS_TYPE_LABELS[a]}</option>)}
              </select>
            </div>
          </div>

          <div>
            <label style={labelStyle}>Tujuan <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <textarea style={{ ...inputStyle, minHeight: 60, resize: "vertical" }} value={purpose} onChange={(e) => setPurpose(e.target.value)} placeholder="cth. Berkas pencairan KPR (BCA)" />
          </div>
          <div>
            <label style={labelStyle}>Butuh sebelum <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <input type="date" style={inputStyle} value={neededBy} onChange={(e) => setNeededBy(e.target.value)} />
          </div>

          {(() => {
            const { internal, external } = chainSummaryParts(accessType);
            return (
              <div className="rounded-md px-3 py-2.5" style={{ background: "var(--color-background-secondary)" }}>
                <div className="flex items-center gap-1.5 text-[11px]" style={{ color: "var(--color-text-secondary)" }}>
                  <i className="ti ti-route" style={{ fontSize: 13, color: "var(--color-text-info)" }} /> Rantai persetujuan
                </div>
                <div className="mt-1 text-[13px] font-medium">
                  {internal}
                  {external && <span style={{ color: "var(--color-text-tertiary)" }}> → {external}</span>}
                </div>
                {external ? (
                  <div className="mt-0.5 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                    PIC → Head of Legal di aplikasi ini, lalu {external} diproses di aplikasi approval terpisah.
                  </div>
                ) : (
                  <div className="mt-0.5 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                    Cukup sampai Head of Legal — disetujui di aplikasi ini.
                  </div>
                )}
              </div>
            );
          })()}
        </div>

        {error && <div className="mt-3 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{error}</div>}

        <div className="mt-4 flex justify-end gap-2.5">
          <button type="button" onClick={onClose} className="rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
          <button type="submit" disabled={busy} className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: busy ? 0.6 : 1 }}>
            {busy && <i className="ti ti-loader-2 animate-spin" />}Kirim permintaan
          </button>
        </div>
      </form>
    </div>
  );
}
