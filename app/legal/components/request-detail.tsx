"use client";

import { useEffect, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { format } from "date-fns";
import { id as idLocale } from "date-fns/locale";
import { Avatar } from "./ui";
import { decideStep, notifyApprover, approveWithDocument } from "@/app/requests/actions";
import { watermarkFile } from "@/lib/watermark";
import { ACCESS_TYPE_LABELS, AUDIT_ACTION_LABELS, type AccessType } from "@/lib/access";
import { ROLE_LABELS, DOC_TYPE_LABELS, type Role, type DocType } from "@/lib/enums";
import { relativeDeadlineLabel } from "@/lib/ranking";

export type StepView = { id: string; order: number; role: string; approverId: string | null; approverName: string | null; approverInitials: string | null; decision: string | null; decidedAt: string | null; note: string | null; notifiedAt: string | null };
export type AuditView = { id: string; action: string; actorName: string | null; detail: string | null; createdAt: string };
export type RequestView = {
  id: string; code: string; status: string; currentStep: number;
  docTitle: string; requestedType: string | null; requesterName: string; dept: string;
  accessType: string; purpose: string; neededBy: string;
  hasDoc: boolean; canSeeDoc: boolean; docUrl: string | null; docFileName: string | null;
  docNote: string | null; docByName: string | null; docAt: string | null;
  steps: StepView[]; audit: AuditView[];
};

function nodeStyle(state: string): { node: React.ReactNode; line: string } {
  if (state === "done") return { node: <span className="acnode" style={{ background: "var(--color-background-success)", color: "var(--color-text-success)" }}><i className="ti ti-check" /></span>, line: "var(--color-border-success)" };
  if (state === "current") return { node: <span className="acnode" style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}><i className="ti ti-clock" /></span>, line: "var(--color-border-tertiary)" };
  if (state === "rejected") return { node: <span className="acnode" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}><i className="ti ti-x" /></span>, line: "var(--color-border-tertiary)" };
  return { node: <span className="acnode" style={{ border: "1.5px solid var(--color-border-tertiary)", color: "var(--color-text-tertiary)" }}><i className="ti ti-lock" style={{ fontSize: 12 }} /></span>, line: "var(--color-border-tertiary)" };
}

export function RequestDetail({ req, actingId, actingRole }: { req: RequestView; actingId: string; actingRole: string }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [rejectingId, setRejectingId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  // Legal PIC's approve-with-document upload state.
  const [docNote, setDocNote] = useState("");
  const [docFile, setDocFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [previewConverted, setPreviewConverted] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [err, setErr] = useState("");

  // Client-side watermarked preview (same logic the server uses to store it).
  async function onPickFile(file: File | null) {
    setErr("");
    setPreviewUrl((prev) => { if (prev) URL.revokeObjectURL(prev); return null; });
    setDocFile(file);
    setPreviewConverted(false);
    if (!file) return;
    setPreviewing(true);
    try {
      const buf = new Uint8Array(await file.arrayBuffer());
      const ext = file.name.split(".").pop() ?? "";
      const wm = await watermarkFile(buf, file.type || "", ext, `COPY · ${req.code}`);
      const mime = wm.ext === "pdf" ? "application/pdf" : file.type || "application/octet-stream";
      const ab = new ArrayBuffer(wm.bytes.byteLength);
      new Uint8Array(ab).set(wm.bytes);
      setPreviewUrl(URL.createObjectURL(new Blob([ab], { type: mime })));
      setPreviewConverted(wm.converted);
    } catch {
      setPreviewUrl(null);
    }
    setPreviewing(false);
  }
  function clearFile() {
    if (fileInputRef.current) fileInputRef.current.value = "";
    onPickFile(null);
  }
  useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

  const rejectedStep = req.steps.find((s) => s.decision === "rejected");
  const released = req.status === "approved"; // fully approved → document released to requester

  function run(fn: () => Promise<{ ok: boolean; error?: string }>) {
    setErr("");
    startTransition(async () => {
      const r = await fn();
      if (!r.ok) setErr(r.error ?? "Gagal.");
      else { setRejectingId(null); setNote(""); router.refresh(); }
    });
  }

  function submitApproveDoc(e: React.FormEvent) {
    e.preventDefault();
    if (!docFile) { setErr("Lampirkan berkas dokumen."); return; }
    const fd = new FormData();
    fd.set("file", docFile);
    fd.set("note", docNote);
    run(() => approveWithDocument(req.id, fd));
  }

  const physical = req.accessType === "checkout_original";

  const statusBadge = (() => {
    if (rejectedStep) return { text: `Ditolak oleh ${rejectedStep.approverName ?? ROLE_LABELS[rejectedStep.role as Role]}`, bg: "var(--color-background-danger)", fg: "var(--color-text-danger)" };
    if (req.status === "rejected") return { text: "Ditolak (approval eksternal)", bg: "var(--color-background-danger)", fg: "var(--color-text-danger)" };
    if (released) return { text: "Disetujui · dokumen tersedia", bg: "var(--color-background-success)", fg: "var(--color-text-success)" };
    if (req.status === "pending_external") return { text: "Menunggu approval eksternal", bg: "var(--color-background-warning)", fg: "var(--color-text-warning)" };
    const cur = req.steps[req.currentStep];
    return { text: `Menunggu ${ROLE_LABELS[(cur?.role ?? "") as Role] ?? ""} · ${req.currentStep + 1} dari ${req.steps.length}`, bg: "var(--color-background-warning)", fg: "var(--color-text-warning)" };
  })();

  // External (separate-app) segment state for a physical checkout.
  const ext = (() => {
    if (req.status === "pending_external") return { text: "Menunggu keputusan di aplikasi approval", fg: "var(--color-text-warning)" };
    if ((req.status === "approved" || req.status === "fulfilled") && physical) return { text: "Disetujui di aplikasi approval", fg: "var(--color-text-success)" };
    if (req.status === "rejected" && !rejectedStep) return { text: "Ditolak di aplikasi approval", fg: "var(--color-text-danger)" };
    return { text: "Menunggu PIC → Head of Legal selesai lebih dulu", fg: "var(--color-text-tertiary)" };
  })();

  const rel = relativeDeadlineLabel(req.neededBy);

  return (
    <div>
      <style>{`.acnode{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:none;font-size:15px;}.acbtn{font-size:12px;font-weight:500;padding:6px 12px;border-radius:8px;border:0.5px solid var(--color-border-secondary);background:transparent;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}`}</style>

      <div className="mb-3 flex items-center gap-2 text-xs" style={{ color: "var(--color-text-tertiary)" }}>
        <a href="/requests" className="inline-flex items-center gap-1" style={{ color: "var(--color-text-info)" }}><i className="ti ti-arrow-left" /> Antrian permintaan</a>
      </div>

      <div className="rounded-lg p-5" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <div className="mb-3.5 flex items-start justify-between gap-3">
          <div>
            <div className="text-[16px] font-medium">Permintaan akses dokumen</div>
            <div className="text-[12px]" style={{ color: "var(--color-text-secondary)" }}>Permintaan #{req.code}</div>
          </div>
          <span className="text-[11px] font-medium" style={{ padding: "4px 10px", borderRadius: 8, background: statusBadge.bg, color: statusBadge.fg }}>{statusBadge.text}</span>
        </div>

        {/* Banner */}
        {rejectedStep && (
          <div className="mb-3.5 rounded-md px-3 py-2.5 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>
            Permintaan ditolak pada {ROLE_LABELS[rejectedStep.role as Role].toLowerCase()}. Dokumen <strong>tidak dilepas ke pemohon</strong> (masih terlihat oleh para approver).
            {rejectedStep.note && <div className="mt-1 font-medium">Alasan: {rejectedStep.note}</div>}
          </div>
        )}
        {released && (
          <div className="mb-3.5 rounded-md px-3 py-2.5 text-[12px]" style={{ background: "var(--color-background-success)", color: "var(--color-text-success)" }}>
            <i className="ti ti-circle-check" style={{ fontSize: 14, verticalAlign: -2, marginRight: 5 }} />
            Disetujui semua pihak — dokumen dilepas ke {req.requesterName}.
          </div>
        )}
        {req.status === "pending_external" && (
          <div className="mb-3.5 rounded-md px-3 py-2.5 text-[12px]" style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}>
            <i className="ti ti-external-link" style={{ fontSize: 14, verticalAlign: -2, marginRight: 5 }} />
            Disetujui sampai Head of Legal. Diteruskan ke approval eksternal (GM → Direktur → Owner) di aplikasi terpisah — keputusan akhir masuk otomatis ke sini.
          </div>
        )}

        {/* Key facts */}
        <div className="mb-4 grid gap-x-5 gap-y-2.5 rounded-md p-3" style={{ background: "var(--color-background-secondary)", gridTemplateColumns: "repeat(auto-fit,minmax(150px,1fr))" }}>
          <Fact k="Dokumen diminta" v={req.docTitle} />
          {req.requestedType && <Fact k="Jenis dokumen" v={DOC_TYPE_LABELS[req.requestedType as DocType] ?? req.requestedType} />}
          <Fact k="Diminta oleh" v={`${req.requesterName} · ${req.dept}`} />
          <Fact k="Jenis akses" v={ACCESS_TYPE_LABELS[req.accessType as AccessType] ?? req.accessType} />
          <Fact k="Tujuan" v={req.purpose} />
          <Fact k="Butuh sebelum" v={`${format(new Date(req.neededBy), "d MMM yyyy", { locale: idLocale })} · ${rel.text}`} />
        </div>

        {/* Attached document — visibility-gated by the server (canSeeDoc) */}
        {req.hasDoc && req.canSeeDoc && req.docUrl && (
          <div className="mb-4 rounded-md p-3" style={{ border: "0.5px solid var(--color-border-tertiary)" }}>
            <div className="mb-2 flex items-center gap-1.5 text-[13px] font-medium"><i className="ti ti-paperclip" style={{ color: "var(--color-text-secondary)" }} /> Dokumen terlampir</div>
            <a href={req.docUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-info)", color: "var(--color-text-info)" }}>
              <i className="ti ti-file-download" /> {req.docFileName ?? "Unduh dokumen"}
            </a>
            <div className="mt-2 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
              Diunggah{req.docByName ? ` oleh ${req.docByName}` : ""}{req.docAt ? ` · ${format(new Date(req.docAt), "d MMM HH:mm", { locale: idLocale })}` : ""} · berwatermark "COPY".
              {!released && actingRole !== "REQUESTER" && " Belum dilepas ke pemohon hingga semua menyetujui."}
            </div>
            {req.docNote && <div className="mt-1 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Catatan: {req.docNote}</div>}
          </div>
        )}
        {req.hasDoc && !req.canSeeDoc && (
          <div className="mb-4 rounded-md px-3 py-2.5 text-[12px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
            <i className="ti ti-lock" style={{ marginRight: 5 }} />
            {rejectedStep ? "Permintaan ditolak — dokumen tidak tersedia untuk Anda." : "Dokumen akan tersedia setelah disetujui semua pihak."}
          </div>
        )}

        {err && <div className="mb-3 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}

        {/* Chain */}
        <div>
          {req.steps.map((s, i) => {
            const state = s.decision === "approved" ? "done" : s.decision === "rejected" ? "rejected" : req.status === "pending" && i === req.currentStep ? "current" : "waiting";
            const { node } = nodeStyle(state);
            // Only the specifically-assigned approver may act (not just any role-holder).
            // SYSADMIN (IT) boleh memutuskan langkah aktif manapun (override).
            const canDecide = req.status === "pending" && i === req.currentStep && (actingRole === "SYSADMIN" || (s.approverId ? actingId === s.approverId : (s.approverName != null && actingRole === s.role)));
            const isPicUpload = s.role === "LEGAL_PIC" && !req.hasDoc; // PIC approves by uploading
            // For a physical checkout, the last in-app node connects down to the external segment.
            const isLast = i === req.steps.length - 1 && !physical;
            const statusLine = state === "done" ? `Disetujui${s.decidedAt ? " · " + format(new Date(s.decidedAt), "d MMM HH:mm", { locale: idLocale }) : ""}` : state === "current" ? (s.notifiedAt ? "Sudah dinotifikasi · menunggu" : "Menunggu persetujuan") : state === "rejected" ? "Ditolak" : "Belum tercapai";
            return (
              <div key={s.id} className="flex gap-3">
                <div className="flex flex-col items-center">
                  {node}
                  {!isLast && <div style={{ width: 2, flex: 1, background: state === "done" ? "var(--color-border-success)" : "var(--color-border-tertiary)", margin: "3px 0" }} />}
                </div>
                <div className="flex-1" style={{ paddingBottom: isLast ? 0 : 18 }}>
                  <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{ROLE_LABELS[s.role as Role] ?? s.role}{isPicUpload && state === "current" ? " · setujui + unggah dokumen" : ""}</div>
                  <div className="mt-0.5 flex items-center gap-2">
                    {s.approverInitials && <Avatar initials={s.approverInitials} size={24} />}
                    <span className="text-[13px] font-medium">{s.approverName ?? "—"}</span>
                  </div>
                  <div className="mt-0.5 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{statusLine}</div>

                  {canDecide && (rejectingId === s.id ? (
                    <div className="mt-2.5">
                      <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Alasan penolakan…" className="w-full rounded-md p-2 text-[12px]" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-primary)" }} />
                      <div className="mt-2 flex gap-2">
                        <button disabled={pending} onClick={() => run(() => decideStep(req.id, "rejected", note))} className="acbtn" style={{ color: "var(--color-text-danger)", borderColor: "var(--color-border-danger)" }}><i className="ti ti-x" /> Konfirmasi tolak</button>
                        <button onClick={() => setRejectingId(null)} className="acbtn" style={{ color: "var(--color-text-secondary)" }}>Batal</button>
                      </div>
                    </div>
                  ) : isPicUpload ? (
                    <form onSubmit={submitApproveDoc} className="mt-2.5 flex flex-col gap-2">
                      <p className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                        Unggah dokumen yang diminta untuk menyetujui. Berkas otomatis diberi <span style={{ color: "var(--color-text-secondary)" }}>watermark "COPY"</span> (+ kode permintaan). PDF/JPG/PNG.
                      </p>
                      <input ref={fileInputRef} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={(e) => onPickFile(e.target.files?.[0] ?? null)} className="text-[12px]" />
                      {docFile && (
                        <div className="rounded-md" style={{ border: "0.5px solid var(--color-border-tertiary)", overflow: "hidden" }}>
                          <div className="flex items-center justify-between gap-2 px-2.5 py-1.5" style={{ background: "var(--color-background-secondary)", borderBottom: "0.5px solid var(--color-border-tertiary)" }}>
                            <span className="min-w-0 truncate text-[12px] font-medium"><i className="ti ti-eye" style={{ marginRight: 5, color: "var(--color-text-tertiary)" }} /> Pratinjau (dengan watermark){previewConverted ? " · disimpan sebagai PDF" : ""}</span>
                            <button type="button" onClick={clearFile} aria-label="Batalkan berkas" className="inline-flex h-5 w-5 flex-none items-center justify-center rounded" style={{ color: "var(--color-text-tertiary)", border: "0.5px solid var(--color-border-tertiary)" }}><i className="ti ti-x" style={{ fontSize: 13 }} /></button>
                          </div>
                          {previewing ? (
                            <div className="px-3 py-8 text-center text-[12px]" style={{ color: "var(--color-text-tertiary)" }}><i className="ti ti-loader-2 animate-spin" style={{ marginRight: 6 }} /> Menyiapkan pratinjau…</div>
                          ) : previewUrl ? (
                            <iframe src={previewUrl} title="Pratinjau dokumen" style={{ width: "100%", height: 380, border: 0, background: "var(--color-background-primary)" }} />
                          ) : (
                            <div className="px-3 py-6 text-center text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>Pratinjau tidak tersedia — watermark tetap diterapkan saat disimpan.</div>
                          )}
                        </div>
                      )}
                      <input value={docNote} onChange={(e) => setDocNote(e.target.value)} placeholder="Catatan (opsional)" className="rounded-md p-2 text-[12px]" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-primary)" }} />
                      <div className="flex flex-wrap items-center gap-2">
                        <button type="submit" disabled={pending} className="acbtn" style={{ background: "var(--color-background-success)", color: "var(--color-text-success)", borderColor: "var(--color-border-success)" }}><i className="ti ti-check" /> Setujui & unggah dokumen</button>
                        <button type="button" disabled={pending} onClick={() => setRejectingId(s.id)} className="acbtn" style={{ color: "var(--color-text-danger)", borderColor: "var(--color-border-danger)" }}><i className="ti ti-x" /> Tolak</button>
                      </div>
                    </form>
                  ) : (
                    <div className="mt-2.5 flex flex-wrap items-center gap-2">
                      <button disabled={pending} onClick={() => run(() => decideStep(req.id, "approved"))} className="acbtn" style={{ background: "var(--color-background-success)", color: "var(--color-text-success)", borderColor: "var(--color-border-success)" }}><i className="ti ti-check" /> Setujui</button>
                      <button disabled={pending} onClick={() => setRejectingId(s.id)} className="acbtn" style={{ color: "var(--color-text-danger)", borderColor: "var(--color-border-danger)" }}><i className="ti ti-x" /> Tolak</button>
                      <span style={{ width: 1, height: 18, background: "var(--color-border-tertiary)" }} />
                      <button disabled={pending} onClick={() => run(() => notifyApprover(req.id))} className="acbtn" style={{ color: "var(--color-text-info)", borderColor: "var(--color-border-info)" }}><i className="ti ti-bell" /> {s.notifiedAt ? "Notify lagi" : `Notify ${(s.approverName ?? "").split(" ")[0]}`}</button>
                    </div>
                  ))}
                </div>
              </div>
            );
          })}

          {/* External segment (separate approval app) — physical checkout only */}
          {physical && (
            <div className="flex gap-3">
              <div className="flex flex-col items-center">
                <span className="acnode" style={{ border: "1.5px dashed var(--color-border-secondary)", color: "var(--color-text-tertiary)" }}><i className="ti ti-external-link" style={{ fontSize: 13 }} /></span>
              </div>
              <div className="flex-1">
                <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Approval eksternal · aplikasi terpisah</div>
                <div className="mt-0.5 text-[13px] font-medium">GM → Direktur → Owner</div>
                <div className="mt-0.5 text-[11px]" style={{ color: ext.fg }}>{ext.text}</div>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Audit trail */}
      <div className="mt-4 rounded-lg p-5" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <div className="mb-2 flex items-center gap-1.5 text-[13px] font-medium"><i className="ti ti-history" style={{ color: "var(--color-text-secondary)" }} /> Jejak audit</div>
        <div className="flex flex-col">
          {req.audit.map((a, i) => (
            <div key={a.id} className="flex items-start gap-2 py-1.5 text-[12px]" style={{ borderTop: i === 0 ? "none" : "0.5px solid var(--color-border-tertiary)" }}>
              <span style={{ width: 6, height: 6, borderRadius: "50%", background: "var(--color-text-tertiary)", marginTop: 6, flex: "none" }} />
              <div className="flex-1">
                <span className="font-medium">{AUDIT_ACTION_LABELS[a.action] ?? a.action}</span>
                {a.actorName && <span style={{ color: "var(--color-text-tertiary)" }}> · {a.actorName}</span>}
                {a.detail && <div style={{ color: "var(--color-text-tertiary)" }}>{a.detail}</div>}
              </div>
              <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{format(new Date(a.createdAt), "d MMM HH:mm", { locale: idLocale })}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function Fact({ k, v }: { k: string; v: string }) {
  return (
    <div className="text-[12px]">
      <div style={{ color: "var(--color-text-tertiary)" }}>{k}</div>
      <div className="font-medium">{v}</div>
    </div>
  );
}
