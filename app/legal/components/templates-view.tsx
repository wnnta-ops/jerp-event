"use client";

import { useMemo, useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createTemplate, deleteTemplate } from "@/app/templates/actions";

export type TemplateField = { key: string; label: string };
export type TemplateItem = {
  id: string;
  name: string;
  fileName: string;
  fields: TemplateField[];
  createdByName: string | null;
  createdAt: string;
};

const inputStyle: React.CSSProperties = {
  border: "0.5px solid var(--color-border-secondary)",
  borderRadius: 8,
  padding: "8px 10px",
  fontSize: 13,
  background: "var(--color-background-primary)",
  color: "var(--color-text-primary)",
  width: "100%",
};
const labelStyle: React.CSSProperties = { fontSize: 12, fontWeight: 500, color: "var(--color-text-secondary)", display: "block", marginBottom: 5 };

export function TemplatesView({ templates, pdfExact = true }: { templates: TemplateItem[]; pdfExact?: boolean }) {
  const router = useRouter();
  const [selectedId, setSelectedId] = useState<string | null>(templates[0]?.id ?? null);
  const [uploadOpen, setUploadOpen] = useState(templates.length === 0);

  const selected = useMemo(() => templates.find((t) => t.id === selectedId) ?? null, [templates, selectedId]);

  return (
    <div className="mx-auto max-w-[1040px]">
      <div className="mb-4">
        <h1 className="text-[22px] font-medium" style={{ color: "var(--color-text-primary)" }}>Draft Dokumen</h1>
        <p className="text-xs" style={{ color: "var(--color-text-secondary)" }}>
          Template dokumen repetitif — pilih template, isi bagian yang berubah, lalu generate Word/PDF.
        </p>
      </div>

      <div className="flex flex-wrap items-start gap-4">
        {/* Left — template list */}
        <div className="w-[300px] flex-none">
          <button
            onClick={() => setUploadOpen((o) => !o)}
            className="mb-3 inline-flex w-full items-center justify-center gap-1.5 rounded-md px-3 py-2 text-[13px] font-medium"
            style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}
          >
            <i className={`ti ${uploadOpen ? "ti-x" : "ti-upload"}`} />
            {uploadOpen ? "Tutup unggah" : "Unggah template"}
          </button>

          {uploadOpen && <UploadPanel onDone={(id) => { setUploadOpen(false); setSelectedId(id); router.refresh(); }} />}

          <div className="flex flex-col gap-1.5">
            {templates.length === 0 && !uploadOpen ? (
              <div className="rounded-lg px-3 py-8 text-center text-[12px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
                Belum ada template.
              </div>
            ) : (
              templates.map((t) => {
                const active = t.id === selectedId;
                return (
                  <button
                    key={t.id}
                    onClick={() => setSelectedId(t.id)}
                    className="flex w-full items-start gap-2.5 rounded-lg px-3 py-2.5 text-left"
                    style={{
                      border: `0.5px solid ${active ? "var(--color-border-info)" : "var(--color-border-tertiary)"}`,
                      background: active ? "var(--color-background-info)" : "var(--color-background-primary)",
                    }}
                  >
                    <i className="ti ti-file-text" style={{ fontSize: 16, color: "var(--color-text-secondary)", marginTop: 1 }} />
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-[13px] font-medium">{t.name}</div>
                      <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{t.fields.length} kolom isian</div>
                    </div>
                  </button>
                );
              })
            )}
          </div>
        </div>

        {/* Right — fill form */}
        <div className="min-w-[320px] flex-1">
          {selected ? (
            <FillForm key={selected.id} template={selected} pdfExact={pdfExact} onDeleted={() => { setSelectedId(null); router.refresh(); }} />
          ) : (
            <div className="rounded-lg px-5 py-12 text-center" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
              <i className="ti ti-file-text" style={{ fontSize: 26, color: "var(--color-text-tertiary)" }} />
              <p className="mt-2 text-sm font-medium">Pilih template untuk mulai</p>
              <p className="mt-1 text-xs" style={{ color: "var(--color-text-tertiary)" }}>atau unggah template baru di kiri.</p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function UploadPanel({ onDone }: { onDone: (id: string) => void }) {
  const fileRef = useRef<HTMLInputElement>(null);
  const [name, setName] = useState("");
  const [fileName, setFileName] = useState("");
  const [err, setErr] = useState("");
  const [saving, start] = useTransition();

  function submit() {
    setErr("");
    const file = fileRef.current?.files?.[0];
    if (!file) { setErr("Pilih file .docx dulu."); return; }
    const fd = new FormData();
    fd.set("name", name);
    fd.set("file", file);
    start(async () => {
      const r = await createTemplate(fd);
      if (r.ok) onDone(r.id);
      else setErr(r.error);
    });
  }

  return (
    <div className="mb-3 rounded-lg p-3" style={{ border: "0.5px solid var(--color-border-info)", background: "var(--color-background-secondary)" }}>
      <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Unggah template .docx</div>
      <div className="flex flex-col gap-2">
        <div>
          <label style={labelStyle}>Nama template</label>
          <input style={inputStyle} value={name} onChange={(e) => setName(e.target.value)} placeholder="cth. Surat Kuasa" />
        </div>
        <div>
          <label style={labelStyle}>File Word (.docx)</label>
          <input
            ref={fileRef}
            type="file"
            accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            onChange={(e) => setFileName(e.target.files?.[0]?.name ?? "")}
            className="text-[12px]"
          />
          {fileName && <div className="mt-1 truncate text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{fileName}</div>}
        </div>
        <p className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
          Tandai bagian yang berubah dengan <code>{"{{nama_field}}"}</code> di dokumen Word — mis. <code>{"{{nama_penerima}}"}</code>, <code>{"{{tanggal}}"}</code>.
        </p>
        {err && <div className="rounded-md px-2.5 py-1.5 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}
        <button
          onClick={submit}
          disabled={saving}
          className="inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-[13px] font-medium"
          style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: saving ? 0.6 : 1 }}
        >
          {saving && <i className="ti ti-loader-2 animate-spin" />}
          {saving ? "Memproses…" : "Unggah & deteksi kolom"}
        </button>
      </div>
    </div>
  );
}

function FillForm({ template, pdfExact, onDeleted }: { template: TemplateItem; pdfExact: boolean; onDeleted: () => void }) {
  const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(template.fields.map((f) => [f.key, ""])));
  const [busy, setBusy] = useState<"" | "docx" | "pdf">("");
  const [err, setErr] = useState("");
  const [confirmDel, setConfirmDel] = useState(false);
  const [deleting, startDelete] = useTransition();

  async function generate(format: "docx" | "pdf") {
    setErr("");
    setBusy(format);
    try {
      const res = await fetch(`/legal/api/templates/${template.id}/generate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ values, format }),
      });
      if (!res.ok) { setErr("Gagal membuat dokumen. Coba lagi."); return; }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${template.name}.${format}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch {
      setErr("Gagal membuat dokumen. Coba lagi.");
    } finally {
      setBusy("");
    }
  }

  return (
    <div className="rounded-lg p-4" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-[15px] font-medium">{template.name}</div>
          <div className="truncate text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
            Dari {template.fileName}{template.createdByName ? ` · ${template.createdByName}` : ""}
          </div>
        </div>
        <button onClick={() => setConfirmDel(true)} className="inline-flex flex-none items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-danger)" }}>
          <i className="ti ti-trash" style={{ fontSize: 12 }} /> Hapus
        </button>
      </div>

      {confirmDel && (
        <div className="mb-3 rounded-md p-2.5" style={{ border: "0.5px solid var(--color-border-danger)", background: "var(--color-background-danger)" }}>
          <div className="text-[12px]" style={{ color: "var(--color-text-danger)" }}>Hapus template ini?</div>
          <div className="mt-2 flex gap-2">
            <button onClick={() => startDelete(async () => { await deleteTemplate(template.id); onDeleted(); })} disabled={deleting} className="rounded-md px-3 py-1.5 text-[12px] font-medium" style={{ background: "var(--color-text-danger)", color: "white", opacity: deleting ? 0.6 : 1 }}>Hapus</button>
            <button onClick={() => setConfirmDel(false)} disabled={deleting} className="rounded-md px-3 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
          </div>
        </div>
      )}

      {template.fields.length === 0 ? (
        <p className="text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>Template ini tidak punya kolom isian.</p>
      ) : (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit,minmax(200px,1fr))", gap: 12 }}>
          {template.fields.map((f) => (
            <div key={f.key}>
              <label style={labelStyle}>{f.label}</label>
              <input
                style={inputStyle}
                value={values[f.key] ?? ""}
                onChange={(e) => setValues((p) => ({ ...p, [f.key]: e.target.value }))}
                placeholder={`{{${f.key}}}`}
              />
            </div>
          ))}
        </div>
      )}

      {err && <div className="mt-3 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}

      <div className="mt-4 flex flex-wrap gap-2.5">
        <button
          onClick={() => generate("docx")}
          disabled={!!busy}
          className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium"
          style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: busy ? 0.6 : 1 }}
        >
          {busy === "docx" ? <i className="ti ti-loader-2 animate-spin" /> : <i className="ti ti-file-type-docx" />}
          Unduh Word (.docx)
        </button>
        <button
          onClick={() => generate("pdf")}
          disabled={!!busy}
          className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium"
          style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)", opacity: busy ? 0.6 : 1 }}
        >
          {busy === "pdf" ? <i className="ti ti-loader-2 animate-spin" /> : <i className="ti ti-file-type-pdf" />}
          Unduh PDF{!pdfExact ? " (versi teks)" : ""}
        </button>
      </div>
      {!pdfExact && (
        <p className="mt-2 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
          <i className="ti ti-info-circle" style={{ marginRight: 4 }} />
          Di server ini PDF berupa versi teks (LibreOffice tidak tersedia). Untuk <b>format persis</b> seperti dokumen asli, unduh <b>Word (.docx)</b> — atau generate PDF dari server yang ada LibreOffice-nya.
        </p>
      )}
    </div>
  );
}
