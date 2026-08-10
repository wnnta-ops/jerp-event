"use client";

import { useEffect, useState, useTransition } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { StatusBadge, TypeBadge, Avatar } from "./ui";
import { NewRequestModal } from "./new-request-modal";
import { formatDateID, keyDate } from "@/lib/format";
import { withBase } from "@/lib/base-path";
import { deleteDocument } from "@/app/documents/actions";
import {
  ACCESS_LEVEL_LABELS,
  ORIGINAL_LOCATION_LABELS,
  type AccessLevel,
  type OriginalLocation,
} from "@/lib/enums";
import type { DocRow } from "./directory-table";

const PIPELINE_RANK: Record<string, number> = {
  IzinLokasi: 1, IzinLingkungan: 2, SKRK: 3, PBGInduk: 4, PBGSplit: 5,
  PetaBidang: 6, SKHGB: 7, SHGBInduk: 8, SHGBSplit: 9, PBBSplit: 10,
  AktaPendirian: 11, RUPS: 12,
  PerjanjianAgent: 13, PerjanjianKPR: 14, PerjanjianKredit: 15, PerjanjianJaminanAset: 16, PerjanjianPihakKetiga: 17,
  Aset: 18, Other: 99,
};

// Map history actions to an icon + label.
const EVENT_META: Record<string, { icon: string; label: string }> = {
  created: { icon: "ti-plus", label: "Dibuat" },
  edit: { icon: "ti-pencil", label: "Diubah" },
  location_change: { icon: "ti-map-pin", label: "Lokasi fisik" },
  file_replace: { icon: "ti-file-symlink", label: "Ganti berkas" },
  reminder: { icon: "ti-bell", label: "Reminder" },
};

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{label}</div>
      <div className="mt-0.5 text-[13px] font-medium">{children || "—"}</div>
    </div>
  );
}

function FilePreview({ url, mimeType }: { url: string; mimeType: string | null }) {
  const isImage = (mimeType ?? "").startsWith("image/") || /\.(png|jpe?g|gif|webp)$/i.test(url);
  return (
    <div
      className="overflow-hidden rounded-md"
      style={{ border: "0.5px solid var(--color-border-tertiary)", background: "var(--color-background-secondary)", height: 200 }}
    >
      {isImage ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={withBase(url)} alt="" className="h-full w-full object-contain" />
      ) : (
        <iframe src={withBase(url)} title="preview" className="h-full w-full" style={{ border: "none" }} />
      )}
    </div>
  );
}

/** Render a description, turning URLs / internal /uploads|/documents paths into links. */
function renderDescription(text: string) {
  const tokens = text.split(/(\s+)/);
  return tokens.map((tok, i) => {
    if (/^https?:\/\/\S+$/.test(tok) || /^\/(uploads|documents)\/\S+$/.test(tok)) {
      return (
        <a key={i} href={withBase(tok)} target="_blank" rel="noreferrer" style={{ color: "var(--color-text-info)", textDecoration: "underline", wordBreak: "break-all" }}>
          {tok}
        </a>
      );
    }
    return <span key={i}>{tok}</span>;
  });
}

function safeList<T>(json: string | null, map: (o: Record<string, unknown>) => T): T[] {
  if (!json) return [];
  try {
    const arr = JSON.parse(json);
    return Array.isArray(arr) ? arr.map((o) => map(o)) : [];
  } catch {
    return [];
  }
}

export function DocumentDrawer({
  doc,
  docs,
  canEdit = false,
  onClose,
  onSelect,
}: {
  doc: DocRow | null;
  docs: DocRow[];
  canEdit?: boolean;
  onClose: () => void;
  onSelect: (d: DocRow) => void;
}) {
  const open = !!doc;
  const router = useRouter();
  const [requestOpen, setRequestOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [delErr, setDelErr] = useState("");
  const [deleting, startDelete] = useTransition();

  // Reset transient state whenever a different document opens.
  useEffect(() => {
    setConfirmDelete(false);
    setDelErr("");
  }, [doc?.id]);

  function doDelete() {
    if (!doc) return;
    setDelErr("");
    startDelete(async () => {
      const r = await deleteDocument(doc.id);
      if (!r.ok) setDelErr(r.error);
      else { onClose(); router.refresh(); }
    });
  }

  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    if (open) window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  const related = doc
    ? doc.relatedIds
        .map((id) => docs.find((d) => d.id === id))
        .filter((d): d is DocRow => !!d)
        .sort((a, b) => (PIPELINE_RANK[a.docType] ?? 9) - (PIPELINE_RANK[b.docType] ?? 9))
    : [];

  const kd = doc ? keyDate(doc) : null;
  const unitText =
    doc?.scope === "unit"
      ? [doc.cluster, doc.blok, doc.unitNo].filter(Boolean).join(" · ")
      : doc?.scope === "entity"
        ? `${doc.entityName || "Entitas"} (tingkat PT)`
        : "Seluruh development (tingkat developer)";

  const shareholders = doc ? safeList(doc.shareholders, (o) => ({ name: String(o.name ?? ""), percentage: String(o.percentage ?? "") })) : [];
  const parties = doc ? safeList(doc.parties, (o) => ({ name: String(o.name ?? ""), entity: String(o.entity ?? "") })) : [];

  return (
    <>
      {/* Backdrop */}
      <div
        onClick={onClose}
        className="fixed inset-0 z-30 transition-opacity duration-200"
        style={{ background: "rgba(0,0,0,0.32)", opacity: open ? 1 : 0, pointerEvents: open ? "auto" : "none" }}
        aria-hidden
      />
      {/* Panel */}
      <aside
        className="fixed right-0 top-0 z-40 flex h-full w-full max-w-[440px] flex-col transition-transform duration-200"
        style={{
          background: "var(--color-background-primary)",
          borderLeft: "0.5px solid var(--color-border-tertiary)",
          transform: open ? "translateX(0)" : "translateX(100%)",
          boxShadow: open ? "-12px 0 32px rgba(0,0,0,0.16)" : "none",
        }}
        role="dialog"
        aria-modal="true"
      >
        {doc && kd && (
          <>
            {/* Header */}
            <div className="flex items-start justify-between gap-3 px-5 pb-3 pt-4" style={{ borderBottom: "0.5px solid var(--color-border-tertiary)" }}>
              <div className="min-w-0">
                <div className="mb-1.5 flex items-center gap-2">
                  <TypeBadge docType={doc.docType} />
                  <StatusBadge status={doc.status} />
                  {canEdit && (
                    <>
                      <Link href={`/documents/${doc.id}/edit`} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-secondary)" }}>
                        <i className="ti ti-pencil" style={{ fontSize: 12 }} /> Edit
                      </Link>
                      <button onClick={() => setConfirmDelete(true)} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-danger)" }}>
                        <i className="ti ti-trash" style={{ fontSize: 12 }} /> Hapus
                      </button>
                    </>
                  )}
                </div>
                <h2 className="text-[16px] font-medium leading-tight">{doc.title}</h2>
                {doc.docNumber && <div className="mt-0.5 text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>No. {doc.docNumber}</div>}
              </div>
              <button onClick={onClose} aria-label="Tutup" style={{ color: "var(--color-text-tertiary)" }}>
                <i className="ti ti-x" style={{ fontSize: 18 }} />
              </button>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto px-5 py-4">
              {/* Delete confirmation */}
              {confirmDelete && (
                <div className="mb-4 rounded-md p-3" style={{ border: "0.5px solid var(--color-border-danger)", background: "var(--color-background-danger)" }}>
                  <div className="text-[12px] font-medium" style={{ color: "var(--color-text-danger)" }}>Hapus dokumen ini secara permanen?</div>
                  <div className="mt-0.5 text-[11px]" style={{ color: "var(--color-text-danger)" }}>Baris dokumen beserta seluruh berkasnya akan dihapus dan tidak bisa dipulihkan.</div>
                  {delErr && <div className="mt-1.5 text-[11px]" style={{ color: "var(--color-text-danger)" }}>{delErr}</div>}
                  <div className="mt-2 flex gap-2">
                    <button onClick={doDelete} disabled={deleting} className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium" style={{ background: "var(--color-text-danger)", color: "white", opacity: deleting ? 0.6 : 1 }}>
                      {deleting && <i className="ti ti-loader-2 animate-spin" />} Hapus permanen
                    </button>
                    <button onClick={() => { setConfirmDelete(false); setDelErr(""); }} disabled={deleting} className="rounded-md px-3 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
                  </div>
                </div>
              )}

              {/* Reminder banner */}
              {doc.reminderDate && (
                <div className="mb-4 flex items-start gap-2 rounded-md px-3 py-2" style={{ border: "0.5px solid var(--color-border-warning)", background: "var(--color-background-warning)" }}>
                  <i className="ti ti-bell" style={{ fontSize: 14, color: "var(--color-text-warning)", marginTop: 1 }} />
                  <div className="text-[12px]" style={{ color: "var(--color-text-warning)" }}>
                    <span className="font-medium">Reminder pembaruan: {formatDateID(doc.reminderDate)}</span>
                    {doc.reminderNote && <div className="mt-0.5">{doc.reminderNote}</div>}
                  </div>
                </div>
              )}

              {/* Key facts */}
              <div className="grid grid-cols-2 gap-x-4 gap-y-3.5">
                <Fact label="Unit">{unitText}</Fact>
                <Fact label="Pembeli">{doc.buyerName}</Fact>
                <Fact label="Status tanggal kunci">
                  <span style={{ color: kd.tone === "secondary" ? undefined : `var(--color-text-${kd.tone})` }}>{kd.text}</span>
                </Fact>
                <Fact label="Tanggal terbit">{formatDateID(doc.issueDate)}</Fact>
                <Fact label="Lokasi original fisik">
                  {ORIGINAL_LOCATION_LABELS[doc.originalLocation as OriginalLocation] ?? doc.originalLocation}
                  {doc.originalLocationDetail ? ` · ${doc.originalLocationDetail}` : ""}
                </Fact>
                <Fact label="Tingkat akses">{ACCESS_LEVEL_LABELS[doc.accessLevel as AccessLevel] ?? doc.accessLevel}</Fact>
                {doc.objectLocation && <Fact label="Lokasi / objek">{doc.objectLocation}</Fact>}
                {doc.landArea && <Fact label="Luas lahan">{doc.landArea}</Fact>}
                {doc.boardStructure && <Fact label="Susunan pengurus">{doc.boardStructure}</Fact>}
                {doc.authorizedCapital && <Fact label="Modal dasar">{doc.authorizedCapital}</Fact>}
                {doc.issuedCapital && <Fact label="Modal ditempatkan">{doc.issuedCapital}</Fact>}
                {doc.version && <Fact label="Versi">{doc.version}{doc.isCurrentApproved ? " · disetujui saat ini" : ""}</Fact>}
                <Fact label="PIC">
                  <span className="inline-flex items-center gap-1.5">
                    <Avatar initials={doc.picInitials} size={20} />
                    {doc.picName}
                  </span>
                </Fact>
                <Fact label="Diunggah oleh">{doc.uploaderName} · {formatDateID(doc.uploadedAt)}</Fact>
                {doc.lastEditedByName && <Fact label="Diperbarui oleh">{doc.lastEditedByName} · {formatDateID(doc.updatedAt)}</Fact>}
              </div>

              {/* Shareholders / parties */}
              {shareholders.length > 0 && (
                <div className="mt-5">
                  <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Pemegang saham</div>
                  <div className="flex flex-col gap-1">
                    {shareholders.map((s, i) => (
                      <div key={i} className="flex items-center justify-between rounded-md px-2.5 py-1.5 text-[12px]" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                        <span>{s.name || "—"}</span>
                        <span style={{ color: "var(--color-text-tertiary)" }}>{s.percentage ? `${s.percentage}%` : ""}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              {parties.length > 0 && (
                <div className="mt-5">
                  <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Pihak</div>
                  <div className="flex flex-col gap-1">
                    {parties.map((p, i) => (
                      <div key={i} className="flex items-center justify-between rounded-md px-2.5 py-1.5 text-[12px]" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                        <span>{p.name || "—"}</span>
                        <span style={{ color: "var(--color-text-tertiary)" }}>{p.entity}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Notes + description */}
              {doc.notes && (
                <div className="mt-5">
                  <div className="mb-1.5 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Catatan</div>
                  <div className="text-[13px]" style={{ whiteSpace: "pre-wrap" }}>{doc.notes}</div>
                </div>
              )}
              {doc.description && (
                <div className="mt-5">
                  <div className="mb-1.5 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Deskripsi</div>
                  <div className="text-[13px]" style={{ whiteSpace: "pre-wrap" }}>{renderDescription(doc.description)}</div>
                </div>
              )}

              {/* Files */}
              <div className="mt-5">
                <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
                  Berkas ({doc.files.length})
                </div>
                {doc.files.length > 0 ? (
                  <div className="flex flex-col gap-2">
                    <FilePreview url={doc.files[0].url} mimeType={doc.files[0].mimeType} />
                    {doc.files.map((f, i) => (
                      <a
                        key={i}
                        href={withBase(f.url)}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-2 rounded-md px-2.5 py-2 text-[12px]"
                        style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}
                      >
                        <i className="ti ti-file" style={{ fontSize: 15, color: "var(--color-text-secondary)" }} />
                        <span className="min-w-0 flex-1 truncate">{f.fileName}</span>
                        <i className="ti ti-external-link" style={{ fontSize: 14, color: "var(--color-text-tertiary)" }} />
                      </a>
                    ))}
                  </div>
                ) : (
                  <div className="text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>Tidak ada berkas.</div>
                )}
              </div>

              {/* Linked-doc chain */}
              <div className="mt-5">
                <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
                  Dokumen terkait ({related.length})
                </div>
                {related.length > 0 ? (
                  <div className="flex flex-col gap-1.5">
                    {related.map((r) => (
                      <button
                        key={r.id}
                        onClick={() => onSelect(r)}
                        className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left"
                        style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}
                      >
                        <TypeBadge docType={r.docType} />
                        <span className="min-w-0 flex-1 truncate text-[13px]">{r.title}</span>
                        <StatusBadge status={r.status} />
                      </button>
                    ))}
                  </div>
                ) : (
                  <div className="text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>Belum ada dokumen terkait.</div>
                )}
              </div>

              {/* History log */}
              <div className="mt-5">
                <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
                  Riwayat ({doc.events.length})
                </div>
                {doc.events.length > 0 ? (
                  <div className="flex flex-col gap-2.5">
                    {doc.events.map((ev, i) => {
                      const meta = EVENT_META[ev.action] ?? { icon: "ti-history", label: ev.action };
                      return (
                        <div key={i} className="flex gap-2.5">
                          <span className="mt-0.5 flex h-5 w-5 flex-none items-center justify-center rounded-full" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>
                            <i className={`ti ${meta.icon}`} style={{ fontSize: 12 }} />
                          </span>
                          <div className="min-w-0">
                            <div className="text-[12px]">{ev.detail || meta.label}</div>
                            <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                              {ev.actorName ?? "Sistem"} · {formatDateID(ev.createdAt)}
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <div className="text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>Belum ada riwayat.</div>
                )}
              </div>
            </div>

            {/* Footer action (Request access originates here — wired in feature 4) */}
            <div className="flex items-center justify-between gap-2 px-5 py-3" style={{ borderTop: "0.5px solid var(--color-border-tertiary)" }}>
              <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                Akses dikontrol oleh tingkat: {ACCESS_LEVEL_LABELS[doc.accessLevel as AccessLevel] ?? doc.accessLevel}
              </span>
              <button
                onClick={() => setRequestOpen(true)}
                className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-[13px] font-medium"
                style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}
              >
                <i className="ti ti-key" />
                Minta akses
              </button>
            </div>
          </>
        )}
      </aside>

      <NewRequestModal
        open={requestOpen}
        onClose={() => setRequestOpen(false)}
        prefillItem={doc?.title ?? ""}
        prefillType={doc?.docType ?? ""}
      />
    </>
  );
}
