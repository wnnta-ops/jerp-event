"use client";

import { useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { createDocument } from "@/app/documents/new/actions";
import { updateDocument } from "@/app/documents/actions";
import { documentInputSchema, fieldErrors } from "@/lib/validation";
import {
  ACCESS_LEVELS,
  ACCESS_LEVEL_LABELS,
  DOC_STATUS_LABELS,
  DOC_CATEGORIES,
  categoryOfDocType,
  statusesForCategory,
  DOC_TYPE_LABELS,
  ENTITY_TYPES,
  EXPIRY_REQUIRED_TYPES,
  ORIGINAL_LOCATIONS,
  ORIGINAL_LOCATION_LABELS,
  ORIGINAL_LOCATION_DETAIL_LABELS,
  ORIGINAL_LOCATION_DETAIL_PLACEHOLDERS,
  ROLE_LABELS,
  TRANSACTION_TYPES,
  type AccessLevel,
  type DocStatus,
  type DocType,
  type OriginalLocation,
  type Role,
} from "@/lib/enums";

type UserOption = { id: string; name: string; role: string; department: string | null };

const inputStyle: React.CSSProperties = {
  border: "0.5px solid var(--color-border-secondary)",
  borderRadius: "8px",
  padding: "8px 10px",
  fontSize: 13,
  background: "var(--color-background-primary)",
  color: "var(--color-text-primary)",
  width: "100%",
};

const labelStyle: React.CSSProperties = {
  fontSize: 12,
  fontWeight: 500,
  color: "var(--color-text-secondary)",
  display: "block",
  marginBottom: 5,
};

function Field({
  label,
  required,
  error,
  span,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  span?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div style={span ? { gridColumn: "1 / -1" } : undefined}>
      <label style={labelStyle}>
        {label} {required && <span style={{ color: "var(--color-text-danger)" }}>*</span>}
      </label>
      {children}
      {error && (
        <div className="mt-1 flex items-center gap-1 text-[11px]" style={{ color: "var(--color-text-danger)" }}>
          <i className="ti ti-alert-circle" style={{ fontSize: 12 }} />
          {error}
        </div>
      )}
    </div>
  );
}

function fmtBytes(n: number) {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

const emptyValues = {
  docType: "" as DocType | "",
  title: "",
  docNumber: "",
  cluster: "",
  blok: "",
  unitNo: "",
  buyerName: "",
  entityName: "",
  status: "" as DocStatus | "",
  issueDate: "",
  expiryDate: "",
  picId: "",
  originalLocation: "" as OriginalLocation | "",
  originalLocationDetail: "",
  version: "",
  isCurrentApproved: false,
  objectLocation: "",
  landArea: "",
  notes: "",
  description: "",
  boardStructure: "",
  authorizedCapital: "",
  issuedCapital: "",
  reminderDate: "",
  reminderNote: "",
  accessLevel: "" as AccessLevel | "",
};

export type EditInitial = {
  id: string;
  values: typeof emptyValues;
  shareholders: { name: string; pct: string }[];
  parties: { name: string; entity: string }[];
  existingFiles: { fileName: string }[];
};

export function UploadForm({
  users,
  defaultPicId,
  clusters,
  mode = "create",
  initial,
}: {
  users: UserOption[];
  defaultPicId?: string;
  clusters: string[];
  mode?: "create" | "edit";
  initial?: EditInitial;
}) {
  const isEdit = mode === "edit";
  const router = useRouter();
  const fileRef = useRef<HTMLInputElement>(null);
  const [values, setValues] = useState(
    isEdit && initial ? { ...emptyValues, ...initial.values } : { ...emptyValues, picId: defaultPicId ?? "" },
  );
  const [files, setFiles] = useState<File[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [dragOver, setDragOver] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [saved, setSaved] = useState<{ id: string; title: string } | null>(null);
  const [category, setCategory] = useState(
    isEdit && initial ? categoryOfDocType(initial.values.docType as DocType) ?? "" : "",
  );
  const [shareholders, setShareholders] = useState<{ name: string; pct: string }[]>(
    isEdit && initial ? initial.shareholders : [],
  );
  const [parties, setParties] = useState<{ name: string; entity: string }[]>(
    isEdit && initial ? initial.parties : [],
  );

  const catTypes = useMemo(() => DOC_CATEGORIES.find((c) => c.key === category)?.types ?? [], [category]);
  const isPerizinan = category === "perizinan";
  const isSertifikasi = category === "sertifikasi";
  const isKorporasi = category === "korporasi";
  const isPerjanjian = category === "perjanjian";
  const isAset = category === "aset";
  const showObjectLocation = isPerizinan || isSertifikasi || isAset || isPerjanjian; // "Lokasi objek" / "Objek perjanjian"
  const showLandArea = isPerizinan || isSertifikasi || isAset; // Luas lahan (perjanjian tidak)
  const showNotesDesc = isPerizinan || isSertifikasi || isKorporasi || isPerjanjian || isAset;
  const showMasaBerakhir = isPerizinan || isSertifikasi || isKorporasi || isPerjanjian || isAset;
  const objectLocationLabel = isPerjanjian
    ? "Objek perjanjian"
    : isSertifikasi
      ? "Lokasi objek sertifikasi"
      : isAset
        ? "Lokasi objek aset"
        : "Lokasi objek perizinan";
  const issueDateLabel = isPerjanjian ? "Tanggal perjanjian" : "Tanggal terbit";
  const docType = values.docType as DocType | "";
  const isTransaction = !!docType && TRANSACTION_TYPES.includes(docType);
  const isEntity = !!docType && ENTITY_TYPES.includes(docType);
  const isDevelopment = !!docType && !isTransaction && !isEntity; // perizinan, sertifikasi (non-split), perjanjian
  const expiryRequired = !!docType && EXPIRY_REQUIRED_TYPES.includes(docType);

  const set = (k: keyof typeof values) => (v: string | boolean) => {
    setValues((prev) => ({ ...prev, [k]: v }));
    setErrors((prev) => (prev[k] ? { ...prev, [k]: "" } : prev));
  };

  const addShareholder = () => setShareholders((s) => [...s, { name: "", pct: "" }]);
  const updateShareholder = (i: number, field: "name" | "pct", val: string) =>
    setShareholders((s) => s.map((sh, idx) => (idx === i ? { ...sh, [field]: val } : sh)));
  const removeShareholder = (i: number) => setShareholders((s) => s.filter((_, idx) => idx !== i));

  const addParty = () => setParties((p) => [...p, { name: "", entity: "" }]);
  const updateParty = (i: number, field: "name" | "entity", val: string) =>
    setParties((p) => p.map((pt, idx) => (idx === i ? { ...pt, [field]: val } : pt)));
  const removeParty = (i: number) => setParties((p) => p.filter((_, idx) => idx !== i));

  // Picking a category resets the type + category-specific fields.
  function onCategoryChange(c: string) {
    setCategory(c);
    setValues((prev) => ({ ...prev, docType: "", status: "", objectLocation: "", landArea: "", notes: "", description: "", boardStructure: "", authorizedCapital: "", issuedCapital: "" }));
    setShareholders([]);
    setParties([]);
    setErrors({});
  }

  function addFiles(list: FileList | null) {
    if (!list) return;
    const incoming = Array.from(list);
    setFiles((prev) => {
      const seen = new Set(prev.map((f) => f.name + f.size));
      return [...prev, ...incoming.filter((f) => !seen.has(f.name + f.size))];
    });
    setErrors((prev) => (prev.files ? { ...prev, files: "" } : prev));
  }

  function removeFile(i: number) {
    setFiles((prev) => prev.filter((_, idx) => idx !== i));
  }

  const picUsers = useMemo(
    // PIC owns the next action — surface Legal staff first, but allow anyone.
    () => [...users].sort((a, b) => (a.role === "REQUESTER" ? 1 : 0) - (b.role === "REQUESTER" ? 1 : 0)),
    [users],
  );

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();

    // Client-side validation mirrors the server (same Zod schema).
    const result = documentInputSchema.safeParse({ ...values, docType: values.docType || undefined });
    const errs: Record<string, string> = result.success ? {} : fieldErrors(result.error);
    if (!isEdit && files.length === 0) errs.files = "Lampirkan minimal satu berkas";

    if (Object.keys(errs).length > 0) {
      setErrors(errs);
      return;
    }

    setSubmitting(true);
    setErrors({});

    const fd = new FormData();
    fd.set("docType", values.docType);
    fd.set("title", values.title);
    fd.set("docNumber", values.docNumber);
    fd.set("cluster", values.cluster);
    fd.set("blok", values.blok);
    fd.set("unitNo", values.unitNo);
    fd.set("buyerName", values.buyerName);
    fd.set("entityName", values.entityName);
    fd.set("status", values.status);
    fd.set("issueDate", values.issueDate);
    fd.set("expiryDate", values.expiryDate);
    fd.set("picId", values.picId);
    fd.set("originalLocation", values.originalLocation);
    fd.set("originalLocationDetail", values.originalLocationDetail);
    fd.set("version", values.version);
    fd.set("isCurrentApproved", values.isCurrentApproved ? "true" : "false");
    fd.set("objectLocation", values.objectLocation);
    fd.set("landArea", values.landArea);
    fd.set("notes", values.notes);
    fd.set("description", values.description);
    fd.set("boardStructure", values.boardStructure);
    fd.set("authorizedCapital", values.authorizedCapital);
    fd.set("issuedCapital", values.issuedCapital);
    const cleanShareholders = shareholders.filter((s) => s.name.trim() || s.pct.trim());
    fd.set("shareholders", cleanShareholders.length ? JSON.stringify(cleanShareholders.map((s) => ({ name: s.name.trim(), percentage: s.pct.trim() }))) : "");
    const cleanParties = parties.filter((p) => p.name.trim() || p.entity.trim());
    fd.set("parties", cleanParties.length ? JSON.stringify(cleanParties.map((p) => ({ name: p.name.trim(), entity: p.entity.trim() }))) : "");
    fd.set("reminderDate", values.reminderDate);
    fd.set("reminderNote", values.reminderNote);
    fd.set("accessLevel", values.accessLevel);
    files.forEach((f) => fd.append("files", f));

    let res: Awaited<ReturnType<typeof createDocument>> | Awaited<ReturnType<typeof updateDocument>>;
    try {
      res = isEdit && initial ? await updateDocument(initial.id, fd) : await createDocument(fd);
    } catch {
      setErrors({ _form: "Gagal menyimpan. Coba lagi." });
      setSubmitting(false);
      return;
    }

    if (res.ok) {
      const newId = isEdit && initial ? initial.id : (res as { id?: string }).id ?? "";
      setSaved({ id: newId, title: values.title });
      router.refresh();
    } else {
      const r = res as { errors?: Record<string, string>; message?: string; error?: string };
      const msg = r.message ?? r.error;
      setErrors({ ...(r.errors ?? {}), ...(msg ? { _form: msg } : {}) });
    }
    setSubmitting(false);
  }

  function resetForm() {
    setValues({ ...emptyValues, picId: defaultPicId ?? "" });
    setFiles([]);
    setErrors({});
    setSaved(null);
    setCategory("");
    setShareholders([]);
    setParties([]);
  }

  // ---- success state ----
  if (saved) {
    return (
      <div style={{ background: "var(--color-background-secondary)", borderRadius: 12, padding: 18 }}>
        <div
          className="flex flex-col items-center gap-2 px-5 py-10 text-center"
          style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", borderRadius: 12 }}
        >
          <span
            className="flex h-11 w-11 items-center justify-center rounded-full"
            style={{ background: "var(--color-background-success)", color: "var(--color-text-success)" }}
          >
            <i className="ti ti-check" style={{ fontSize: 22 }} />
          </span>
          <div className="text-[15px] font-medium">{isEdit ? "Perubahan tersimpan" : "Dokumen tersimpan"}</div>
          <div className="text-xs" style={{ color: "var(--color-text-secondary)" }}>
            {isEdit
              ? `Perubahan pada “${saved.title}” telah disimpan dan tercatat di riwayat.`
              : `“${saved.title}” telah masuk ke direktori dengan ${files.length} berkas.`}
          </div>
          <div className="mt-3 flex gap-2">
            {!isEdit && (
              <button
                onClick={resetForm}
                className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium"
                style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}
              >
                <i className="ti ti-plus" />
                Unggah dokumen lain
              </button>
            )}
            <Link
              href="/"
              className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium"
              style={{ border: "0.5px solid var(--color-border-secondary)", background: isEdit ? "var(--color-background-info)" : "transparent", color: isEdit ? "var(--color-text-info)" : "var(--color-text-secondary)" }}
            >
              Lihat direktori
            </Link>
          </div>
        </div>
      </div>
    );
  }

  // ---- form ----
  return (
    <div style={{ background: "var(--color-background-secondary)", borderRadius: 12, padding: 18 }}>
      <form
        onSubmit={onSubmit}
        noValidate
        style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", borderRadius: 12, padding: "18px 20px" }}
      >
        <div className="mb-3.5 flex items-center justify-between">
          <span className="text-[15px] font-medium">{isEdit ? "Edit dokumen" : "Unggah dokumen legal"}</span>
          <Link href="/" aria-label="Tutup" style={{ color: "var(--color-text-tertiary)" }}>
            <i className="ti ti-x" style={{ fontSize: 18 }} />
          </Link>
        </div>

        {/* Step 1 — pick a category; this gates the rest of the form (fixed in edit) */}
        <div className="mb-3" style={{ maxWidth: 300 }}>
          <label style={labelStyle}>Kategori dokumen <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
          {isEdit ? (
            <div style={{ ...inputStyle, background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>
              {DOC_CATEGORIES.find((c) => c.key === category)?.label ?? "—"}
            </div>
          ) : (
            <select style={inputStyle} value={category} onChange={(e) => onCategoryChange(e.target.value)}>
              <option value="">Pilih kategori…</option>
              {DOC_CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
            </select>
          )}
        </div>

        {!category ? (
          <div className="rounded-md px-3 py-7 text-center text-[12px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
            <i className="ti ti-arrow-up" style={{ fontSize: 16, display: "block", marginBottom: 4 }} />
            Pilih kategori dokumen dulu untuk mulai mengisi.
          </div>
        ) : catTypes.length === 0 ? (
          <div className="rounded-md px-3 py-7 text-center text-[12px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
            Kategori ini belum memiliki tipe dokumen.
          </div>
        ) : (
        <>

        {/* Existing files (edit mode) */}
        {isEdit && initial && initial.existingFiles.length > 0 && (
          <div className="mb-3">
            <div className="mb-1.5 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Berkas saat ini ({initial.existingFiles.length})</div>
            <div className="flex flex-col gap-1.5">
              {initial.existingFiles.map((f, i) => (
                <div key={i} className="flex items-center gap-2 rounded-md px-2.5 py-1.5" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                  <i className="ti ti-file" style={{ fontSize: 15, color: "var(--color-text-secondary)" }} />
                  <span className="min-w-0 flex-1 truncate text-[12px]">{f.fileName}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Dropzone */}
        <label
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            addFiles(e.dataTransfer.files);
          }}
          className="mb-3 flex cursor-pointer flex-col items-center gap-1 text-center"
          style={{
            border: `1px dashed ${dragOver ? "var(--color-border-info)" : errors.files ? "var(--color-border-danger)" : "var(--color-border-secondary)"}`,
            borderRadius: 8,
            padding: 18,
            background: dragOver ? "var(--color-background-info)" : "transparent",
          }}
        >
          <i className="ti ti-upload" style={{ fontSize: 22, color: "var(--color-text-secondary)" }} />
          <span className="text-[13px] font-medium">{isEdit ? "Ganti berkas atau seret ke sini" : "Pilih berkas atau seret ke sini"}</span>
          <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
            {isEdit ? "Kosongkan jika tidak ingin mengganti — berkas lama tetap dipakai" : "PDF, JPG, PNG · maks 20 MB · scan + salinan bertanda tangan"}
          </span>
          <input
            ref={fileRef}
            type="file"
            multiple
            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*"
            className="hidden"
            onChange={(e) => addFiles(e.target.files)}
          />
        </label>

        {/* Selected files */}
        {files.length > 0 && (
          <div className="mb-3 flex flex-col gap-1.5">
            {files.map((f, i) => (
              <div
                key={f.name + f.size + i}
                className="flex items-center gap-2 rounded-md px-2.5 py-1.5"
                style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}
              >
                <i className="ti ti-file" style={{ fontSize: 15, color: "var(--color-text-secondary)" }} />
                <span className="min-w-0 flex-1 truncate text-[12px]">{f.name}</span>
                <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{fmtBytes(f.size)}</span>
                <button type="button" onClick={() => removeFile(i)} aria-label="Hapus berkas" style={{ color: "var(--color-text-tertiary)" }}>
                  <i className="ti ti-x" style={{ fontSize: 14 }} />
                </button>
              </div>
            ))}
          </div>
        )}
        {errors.files && (
          <div className="mb-3 flex items-center gap-1 text-[11px]" style={{ color: "var(--color-text-danger)" }}>
            <i className="ti ti-alert-circle" style={{ fontSize: 12 }} />
            {errors.files}
          </div>
        )}

        {/* Adaptive hint */}
        <div className="mb-3 flex items-center gap-1.5">
          <i className="ti ti-wand" style={{ fontSize: 14, color: "var(--color-text-info)" }} />
          <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
            Kolom di bawah menyesuaikan jenis dokumen yang dipilih.
          </span>
        </div>

        {/* Fields */}
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit,minmax(200px,1fr))", gap: 12 }}>
          <Field label="Jenis dokumen" required error={errors.docType}>
            <select style={inputStyle} value={values.docType} onChange={(e) => set("docType")(e.target.value)}>
              <option value="">Pilih jenis…</option>
              {catTypes.map((t) => (
                <option key={t} value={t}>{DOC_TYPE_LABELS[t]}</option>
              ))}
            </select>
          </Field>

          <Field label="Status" required error={errors.status}>
            <select style={inputStyle} value={values.status} onChange={(e) => set("status")(e.target.value)}>
              <option value="">Pilih status…</option>
              {statusesForCategory(category).map((s) => (
                <option key={s} value={s}>{DOC_STATUS_LABELS[s]}</option>
              ))}
            </select>
          </Field>

          <Field label="Judul dokumen" required span error={errors.title}>
            <input style={inputStyle} value={values.title} onChange={(e) => set("title")(e.target.value)} placeholder="cth. PPJB — Unit A-12" />
          </Field>

          <Field label="Nomor dokumen" error={errors.docNumber}>
            <input style={inputStyle} value={values.docNumber} onChange={(e) => set("docNumber")(e.target.value)} placeholder="cth. 045/PPJB/VI/2026" />
          </Field>

          {isTransaction && (
            <>
              <Field label="Cluster" required error={errors.cluster}>
                <input list="cluster-list" style={inputStyle} value={values.cluster} onChange={(e) => set("cluster")(e.target.value)} placeholder="cth. Cluster Melati" />
                <datalist id="cluster-list">
                  {clusters.map((c) => <option key={c} value={c} />)}
                </datalist>
              </Field>
              <Field label="Blok" required error={errors.blok}>
                <input style={inputStyle} value={values.blok} onChange={(e) => set("blok")(e.target.value)} placeholder="cth. Blok A" />
              </Field>
              <Field label="Nomor unit" required error={errors.unitNo}>
                <input style={inputStyle} value={values.unitNo} onChange={(e) => set("unitNo")(e.target.value)} placeholder="cth. A-12" />
              </Field>
              <Field label="Nama pembeli" error={errors.buyerName}>
                <input style={inputStyle} value={values.buyerName} onChange={(e) => set("buyerName")(e.target.value)} placeholder="cth. Budi Santoso" />
              </Field>
            </>
          )}

          {isEntity && (
            <Field label="Nama entitas / PT" required span error={errors.entityName}>
              <input style={inputStyle} value={values.entityName} onChange={(e) => set("entityName")(e.target.value)} placeholder="cth. PT Mitra Usaha Propertindo" />
            </Field>
          )}

          {isDevelopment && (
            <div style={{ gridColumn: "1 / -1" }}>
              <div
                className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[11px]"
                style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}
              >
                <i className="ti ti-building-community" style={{ fontSize: 13 }} />
                Dokumen tingkat developer (seluruh Java Residence) — tanpa data unit.
              </div>
            </div>
          )}

          {showObjectLocation && (
            <Field label={objectLocationLabel} span error={errors.objectLocation}>
              <input style={inputStyle} value={values.objectLocation} onChange={(e) => set("objectLocation")(e.target.value)} placeholder={isPerjanjian ? "cth. Sewa-menyewa lahan parkir" : "cth. Jl. Raya Krian, Sidoarjo"} />
            </Field>
          )}

          {showLandArea && (
            <Field label="Luas lahan" error={errors.landArea}>
              <input style={inputStyle} value={values.landArea} onChange={(e) => set("landArea")(e.target.value)} placeholder="cth. 12.500 m²" />
            </Field>
          )}

          {isPerjanjian && (
            <div style={{ gridColumn: "1 / -1" }}>
              <label style={labelStyle}>Nama pihak</label>
              <div className="flex flex-col gap-1.5">
                {parties.map((pt, i) => (
                  <div key={i} className="flex items-center gap-2">
                    <input style={{ ...inputStyle, flex: 1 }} value={pt.name} onChange={(e) => updateParty(i, "name", e.target.value)} placeholder="Nama orang" />
                    <input style={{ ...inputStyle, flex: 1 }} value={pt.entity} onChange={(e) => updateParty(i, "entity", e.target.value)} placeholder="Nama badan / PT" />
                    <button type="button" onClick={() => removeParty(i)} aria-label="Hapus" className="inline-flex h-7 w-7 flex-none items-center justify-center rounded" style={{ color: "var(--color-text-tertiary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                      <i className="ti ti-x" style={{ fontSize: 13 }} />
                    </button>
                  </div>
                ))}
                <button type="button" onClick={addParty} className="inline-flex w-fit items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-info)" }}>
                  <i className="ti ti-plus" /> Tambah pihak
                </button>
              </div>
            </div>
          )}

          {isKorporasi && (
            <>
              <Field label="Susunan pengurus" span error={errors.boardStructure}>
                <textarea style={{ ...inputStyle, minHeight: 56, resize: "vertical" }} value={values.boardStructure} onChange={(e) => set("boardStructure")(e.target.value)} placeholder="cth. Direktur: …, Komisaris: …" />
              </Field>
              <Field label="Modal dasar (Rp)" error={errors.authorizedCapital}>
                <input style={inputStyle} value={values.authorizedCapital} onChange={(e) => set("authorizedCapital")(e.target.value)} placeholder="cth. 1.000.000.000" />
              </Field>
              <Field label="Modal ditempatkan (Rp)" error={errors.issuedCapital}>
                <input style={inputStyle} value={values.issuedCapital} onChange={(e) => set("issuedCapital")(e.target.value)} placeholder="cth. 500.000.000" />
              </Field>
              <div style={{ gridColumn: "1 / -1" }}>
                <label style={labelStyle}>Pemegang saham</label>
                <div className="flex flex-col gap-1.5">
                  {shareholders.map((sh, i) => (
                    <div key={i} className="flex items-center gap-2">
                      <input style={{ ...inputStyle, flex: 1 }} value={sh.name} onChange={(e) => updateShareholder(i, "name", e.target.value)} placeholder="Nama pemegang saham" />
                      <input style={{ ...inputStyle, width: 90 }} value={sh.pct} onChange={(e) => updateShareholder(i, "pct", e.target.value)} placeholder="%" />
                      <button type="button" onClick={() => removeShareholder(i)} aria-label="Hapus" className="inline-flex h-7 w-7 flex-none items-center justify-center rounded" style={{ color: "var(--color-text-tertiary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                        <i className="ti ti-x" style={{ fontSize: 13 }} />
                      </button>
                    </div>
                  ))}
                  <button type="button" onClick={addShareholder} className="inline-flex w-fit items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-info)" }}>
                    <i className="ti ti-plus" /> Tambah pemegang saham
                  </button>
                </div>
              </div>
            </>
          )}

          <Field label="Penanggung jawab (PIC)" required error={errors.picId}>
            <select style={inputStyle} value={values.picId} onChange={(e) => set("picId")(e.target.value)}>
              <option value="">Pilih PIC…</option>
              {picUsers.map((u) => (
                <option key={u.id} value={u.id}>{u.name} · {ROLE_LABELS[u.role as Role] ?? u.role}</option>
              ))}
            </select>
          </Field>

          <Field label="Lokasi original fisik" required error={errors.originalLocation}>
            <select
              style={inputStyle}
              value={values.originalLocation}
              onChange={(e) => {
                // Switching location clears the detail (its meaning changes).
                setValues((prev) => ({ ...prev, originalLocation: e.target.value as OriginalLocation | "", originalLocationDetail: "" }));
                setErrors((prev) => (prev.originalLocation ? { ...prev, originalLocation: "" } : prev));
              }}
            >
              <option value="">Pilih lokasi…</option>
              {ORIGINAL_LOCATIONS.map((l) => (
                <option key={l} value={l}>{ORIGINAL_LOCATION_LABELS[l]}</option>
              ))}
            </select>
          </Field>

          {values.originalLocation && (
            <Field label={ORIGINAL_LOCATION_DETAIL_LABELS[values.originalLocation as OriginalLocation]} error={errors.originalLocationDetail}>
              <input
                style={inputStyle}
                value={values.originalLocationDetail}
                onChange={(e) => set("originalLocationDetail")(e.target.value)}
                placeholder={ORIGINAL_LOCATION_DETAIL_PLACEHOLDERS[values.originalLocation as OriginalLocation]}
              />
            </Field>
          )}

          <Field label={issueDateLabel} required={isPerizinan} error={errors.issueDate}>
            <input type="date" style={inputStyle} value={values.issueDate} onChange={(e) => set("issueDate")(e.target.value)} />
          </Field>

          <Field
            label={showMasaBerakhir ? "Masa berakhir" : expiryRequired ? "Tanggal kedaluwarsa / berlaku s/d" : "Tanggal kedaluwarsa / perpanjangan"}
            required={expiryRequired}
            error={errors.expiryDate}
          >
            <input type="date" style={inputStyle} value={values.expiryDate} onChange={(e) => set("expiryDate")(e.target.value)} />
          </Field>

          <Field label="Tingkat akses" required error={errors.accessLevel}>
            <select style={inputStyle} value={values.accessLevel} onChange={(e) => set("accessLevel")(e.target.value)}>
              <option value="">Pilih tingkat…</option>
              {ACCESS_LEVELS.map((a) => (
                <option key={a} value={a}>{ACCESS_LEVEL_LABELS[a]}</option>
              ))}
            </select>
          </Field>

          <Field label="Reminder pembaruan" error={errors.reminderDate}>
            <input type="date" style={inputStyle} value={values.reminderDate} onChange={(e) => set("reminderDate")(e.target.value)} />
          </Field>

          <Field label="Catatan reminder" error={errors.reminderNote}>
            <input style={inputStyle} value={values.reminderNote} onChange={(e) => set("reminderNote")(e.target.value)} placeholder="cth. Perpanjang sebelum jatuh tempo" />
          </Field>

          {showNotesDesc && (
            <>
              <Field label="Catatan lain" span error={errors.notes}>
                <textarea style={{ ...inputStyle, minHeight: 56, resize: "vertical" }} value={values.notes} onChange={(e) => set("notes")(e.target.value)} placeholder="Catatan tambahan…" />
              </Field>
              <Field label="Deskripsi" span error={errors.description}>
                <textarea style={{ ...inputStyle, minHeight: 56, resize: "vertical" }} value={values.description} onChange={(e) => set("description")(e.target.value)} placeholder="Deskripsi dokumen…" />
              </Field>
            </>
          )}
        </div>

        {errors._form && (
          <div className="mt-4 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>
            {errors._form}
          </div>
        )}

        <div className="mt-[18px] flex justify-end gap-2.5">
          <Link
            href="/"
            className="rounded-md px-4 py-2 text-[13px] font-medium"
            style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}
          >
            Batal
          </Link>
          <button
            type="submit"
            disabled={submitting}
            className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium"
            style={{
              border: "0.5px solid var(--color-border-secondary)",
              background: "var(--color-background-info)",
              color: "var(--color-text-info)",
              opacity: submitting ? 0.6 : 1,
            }}
          >
            {submitting && <i className="ti ti-loader-2 animate-spin" />}
            {submitting ? "Menyimpan…" : isEdit ? "Simpan perubahan" : "Simpan dokumen"}
          </button>
        </div>
        </>
        )}
      </form>
    </div>
  );
}
