"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import {
  createSection, updateSection, deleteSection,
  createEntry, updateEntry, deleteEntry, moveEntry, setEntryStatus, setCheckpoint,
} from "@/app/timeline/actions";
import { STATUS_OPTIONS, statusTone, TONE_BG, TONE_FG, checkpointLabel } from "@/lib/timeline";
import { BADAN, jenisForBadan, checkpointsFor, badanLabel } from "@/lib/timeline-checkpoints";
import { withBase } from "@/lib/base-path";

export type CheckpointView = {
  id: string; order: number; step: string; done: boolean;
  doneByName: string | null; doneAt: string | null; note: string | null; proofName: string | null;
};
export type MoveView = { id: string; fromMonth: number | null; toMonth: number | null; byName: string | null; createdAt: string };
export type EntryView = {
  id: string; name: string; badan: string | null; jenisDokumen: string | null;
  status: string; durasi: string; progressMonth: number | null; targetMonth: number | null;
  doneCount: number; checkpoints: CheckpointView[]; moves: MoveView[];
};
type MoveField = "progress" | "target";
export type SectionView = { id: string; title: string; cluster: number | null; productType: string | null; range: string; months: string[]; entries: EntryView[] };

type ProgressState = "progress" | "done" | "telat";

const input: React.CSSProperties = { border: "0.5px solid var(--color-border-secondary)", borderRadius: 8, padding: "8px 10px", fontSize: 13, background: "var(--color-background-primary)", color: "var(--color-text-primary)", width: "100%" };
const lbl: React.CSSProperties = { fontSize: 12, fontWeight: 500, color: "var(--color-text-secondary)", display: "block", marginBottom: 5 };
const TH: React.CSSProperties = { border: "0.5px solid var(--color-border-tertiary)", padding: "6px 8px", textAlign: "center", whiteSpace: "nowrap", background: "var(--color-background-secondary)", color: "var(--color-text-secondary)", fontWeight: 500, fontSize: 11 };
const TD: React.CSSProperties = { border: "0.5px solid var(--color-border-tertiary)", padding: "6px 8px", textAlign: "center", whiteSpace: "nowrap", fontSize: 12, color: "var(--color-text-primary)" };

function Chip({ tone, children }: { tone: ReturnType<typeof statusTone>; children: React.ReactNode }) {
  return <span className="inline-block rounded-md px-2 py-0.5 text-[10px] font-medium" style={{ background: TONE_BG[tone], color: TONE_FG[tone] }}>{children}</span>;
}

/** Status inline dropdown — ganti status langsung dari tabel. */
function StatusSelect({ entry }: { entry: EntryView }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  const [val, setVal] = useState(entry.status || "—");
  const tone = statusTone(val);
  return (
    <span style={{ position: "relative", display: "inline-block" }}>
      <select
        value={val}
        disabled={pending}
        onChange={(e) => { const next = e.target.value; setVal(next); start(async () => { await setEntryStatus(entry.id, next); router.refresh(); }); }}
        style={{ appearance: "none", WebkitAppearance: "none", border: "none", borderRadius: 6, padding: "3px 20px 3px 8px", fontSize: 10, fontWeight: 600, cursor: "pointer", background: TONE_BG[tone], color: TONE_FG[tone], opacity: pending ? 0.6 : 1 }}
      >
        {STATUS_OPTIONS.map((o) => <option key={o} value={o}>{o}</option>)}
      </select>
      <i className="ti ti-chevron-down" style={{ position: "absolute", right: 5, top: "50%", transform: "translateY(-50%)", fontSize: 11, pointerEvents: "none", color: TONE_FG[tone] }} />
    </span>
  );
}

function sectionYear(range: string): number | null {
  const m = range.match(/20\d\d/g);
  return m ? Number(m[m.length - 1]) : null;
}


/** State kotak progress: biru "On progress" (isi %), hijau "Done", merah "Telat".
 *  Total langkah = jumlah checkpoint item (bervariasi per jenis dokumen). */
function entryState(s: SectionView, e: EntryView, now: Date): { state: ProgressState; pct: number } {
  const done = e.doneCount;
  const total = e.checkpoints.length || 1;
  const standard = s.months[0] === "Jan";
  const yr = sectionYear(s.range);
  const targetEnd = e.targetMonth != null && standard && yr ? new Date(yr, e.targetMonth + 1, 0, 23, 59, 59) : null;
  if (done >= total) {
    let late = false;
    if (targetEnd) {
      const maxDone = e.checkpoints.reduce((mx, c) => (c.doneAt && new Date(c.doneAt).getTime() > mx ? new Date(c.doneAt).getTime() : mx), 0);
      late = maxDone > 0 && maxDone > targetEnd.getTime();
    }
    return { state: late ? "telat" : "done", pct: 100 };
  }
  if (targetEnd && now > targetEnd) return { state: "telat", pct: 100 };
  return { state: "progress", pct: (done / total) * 100 };
}

// Kotak mengisi penuh sel (edge-to-edge), bukan pil kecil.
const FILLBOX: React.CSSProperties = { display: "block", width: "100%", padding: "8px 4px", fontSize: 9, fontWeight: 700, letterSpacing: 0.3, textAlign: "center", whiteSpace: "nowrap" };

function TargetBox() {
  return <span style={{ ...FILLBOX, background: TONE_BG.warning, color: TONE_FG.warning }}>Target</span>;
}

function StateBox({ state, done, total }: { state: ProgressState; done: number; total: number }) {
  const tone = state === "done" ? "success" : state === "telat" ? "danger" : "info";
  const label = state === "done" ? "Done" : state === "telat" ? "Telat" : "On progress";
  // Done / Telat = penuh. On progress = N segmen (1 per checkpoint), terisi kiri→kanan.
  if (state !== "progress") {
    return <span style={{ ...FILLBOX, background: TONE_BG[tone], color: TONE_FG[tone] }}>{label}</span>;
  }
  const n = Math.max(total, 1);
  // Banyak checkpoint (mis. 17) → garis pembatas per segmen bikin penuh; di atas 12
  // pakai isian proporsional halus tanpa pembatas.
  const showDividers = n <= 12;
  return (
    <span style={{ ...FILLBOX, padding: 0, position: "relative", overflow: "hidden", background: "var(--color-background-primary)", color: TONE_FG.info }} title={`${done}/${n} checkpoint`}>
      <span style={{ position: "absolute", inset: 0, display: "flex" }} aria-hidden>
        {showDividers
          ? Array.from({ length: n }, (_, i) => (
              <span key={i} style={{ flex: 1, borderRight: i < n - 1 ? "1.5px solid var(--color-background-primary)" : "none", background: i < done ? TONE_BG.info : "transparent" }} />
            ))
          : <span style={{ width: `${(done / n) * 100}%`, background: TONE_BG.info }} />}
      </span>
      <span style={{ position: "relative", display: "block", padding: "8px 4px" }}>{label}</span>
    </span>
  );
}

export function TimelineBoard({ sections, canEdit, nowISO }: { sections: SectionView[]; canEdit: boolean; nowISO: string }) {
  const now = useMemo(() => new Date(nowISO), [nowISO]);
  const [secEdit, setSecEdit] = useState<{ section: SectionView | null } | null>(null);
  const [entryEdit, setEntryEdit] = useState<{ sectionId: string; entry: EntryView | null } | null>(null);
  const [cpRef, setCpRef] = useState<{ sectionId: string; entryId: string } | null>(null);
  const [drag, setDrag] = useState<{ entryId: string; field: MoveField; fromMonth: number } | null>(null);
  const [pendingMove, setPendingMove] = useState<{ entry: EntryView; section: SectionView; field: MoveField; toMonth: number } | null>(null);
  const [query, setQuery] = useState("");
  const [cluster, setCluster] = useState("");
  const [product, setProduct] = useState("");

  const clusters = useMemo(() => Array.from(new Set(sections.map((s) => s.cluster).filter((c): c is number => c != null))).sort((a, b) => a - b), [sections]);
  const products = useMemo(() => Array.from(new Set(sections.map((s) => s.productType).filter((p): p is string => !!p))).sort(), [sections]);
  const visible = useMemo(
    () => sections.filter((s) =>
      (!query || s.title.toLowerCase().includes(query.toLowerCase())) &&
      (!cluster || String(s.cluster) === cluster) &&
      (!product || s.productType === product)),
    [sections, query, cluster, product],
  );
  // Popup checkpoint selalu baca data terbaru (bukan snapshot saat dibuka).
  const cpEntry = useMemo(() => {
    if (!cpRef) return null;
    const s = sections.find((x) => x.id === cpRef.sectionId);
    const e = s?.entries.find((x) => x.id === cpRef.entryId);
    return s && e ? { section: s, entry: e } : null;
  }, [cpRef, sections]);

  return (
    <div className="mx-auto max-w-[1180px]">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-[22px] font-medium" style={{ color: "var(--color-text-primary)" }}>Timeline legal</h1>
          <p className="text-xs" style={{ color: "var(--color-text-secondary)" }}>Progres per item lewat checkpoint dokumen · klik nama item untuk update & unggah bukti</p>
        </div>
        {canEdit && (
          <button onClick={() => setSecEdit({ section: null })} className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}>
            <i className="ti ti-plus" /> Tambah kategori
          </button>
        )}
      </div>

      {/* Legend */}
      <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px]" style={{ color: "var(--color-text-secondary)" }}>
        <span className="inline-flex items-center gap-1.5"><span style={{ width: 12, height: 12, borderRadius: 3, background: TONE_BG.warning, boxShadow: `inset 0 0 0 1px ${TONE_FG.warning}` }} /> Target (tetap)</span>
        <span className="inline-flex items-center gap-1.5"><span style={{ width: 12, height: 12, borderRadius: 3, background: TONE_BG.info, boxShadow: `inset 0 0 0 1px ${TONE_FG.info}` }} /> On progress (isi tiap checkpoint)</span>
        <span className="inline-flex items-center gap-1.5"><span style={{ width: 12, height: 12, borderRadius: 3, background: TONE_BG.success, boxShadow: `inset 0 0 0 1px ${TONE_FG.success}` }} /> Done (on-time)</span>
        <span className="inline-flex items-center gap-1.5"><span style={{ width: 12, height: 12, borderRadius: 3, background: TONE_BG.danger, boxShadow: `inset 0 0 0 1px ${TONE_FG.danger}` }} /> Telat</span>
        {canEdit && <span><i className="ti ti-arrows-move" style={{ marginRight: 4 }} />Seret kotak ke bulan lain → pindah jadwal (ada konfirmasi & log)</span>}
      </div>

      {/* Search nama kategori + filter cluster */}
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative min-w-[200px] flex-1" style={{ maxWidth: 420 }}>
          <i className="ti ti-search" style={{ position: "absolute", left: 10, top: "50%", transform: "translateY(-50%)", fontSize: 15, color: "var(--color-text-tertiary)" }} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Cari nama kategori…" style={{ ...input, paddingLeft: 30 }} />
          {query && <button onClick={() => setQuery("")} aria-label="Bersihkan" style={{ position: "absolute", right: 8, top: "50%", transform: "translateY(-50%)", color: "var(--color-text-tertiary)" }}><i className="ti ti-x" style={{ fontSize: 14 }} /></button>}
        </div>
        <select value={cluster} onChange={(e) => setCluster(e.target.value)} style={{ ...input, width: "auto", minWidth: 140 }} title="Filter cluster">
          <option value="">Semua cluster</option>
          {clusters.map((c) => <option key={c} value={String(c)}>Cluster {c}</option>)}
        </select>
        <select value={product} onChange={(e) => setProduct(e.target.value)} style={{ ...input, width: "auto", minWidth: 150 }} title="Filter jenis produk">
          <option value="">Semua jenis produk</option>
          {products.map((p) => <option key={p} value={p}>{p}</option>)}
        </select>
      </div>

      {visible.length === 0 && (
        <div className="rounded-lg px-5 py-10 text-center text-[13px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
          {sections.length === 0 ? "Belum ada kategori. Klik “Tambah kategori” untuk mulai." : "Tidak ada kategori yang cocok."}
        </div>
      )}

      {visible.map((s) => (
        <div key={s.id} style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", borderRadius: 12, padding: "14px 16px", marginBottom: 14 }}>
          <div className="mb-2.5 flex flex-wrap items-center gap-2">
            <h2 className="text-[14px] font-medium" style={{ color: "var(--color-text-primary)" }}>{s.title}</h2>
            {s.cluster != null && <Chip tone="info">Cluster {s.cluster}</Chip>}
            {s.productType && <Chip tone="success">{s.productType}</Chip>}
            <Chip tone="neutral">{s.range}</Chip>
            {canEdit && (
              <div className="ml-auto flex items-center gap-1.5">
                <button onClick={() => setEntryEdit({ sectionId: s.id, entry: null })} className="inline-flex items-center gap-1 rounded px-2 py-1 text-[11px] font-medium" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-info)" }}><i className="ti ti-plus" /> Item</button>
                <button onClick={() => setSecEdit({ section: s })} className="inline-flex items-center gap-1 rounded px-2 py-1 text-[11px]" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-secondary)" }}><i className="ti ti-pencil" /></button>
                <DeleteBtn onDelete={() => deleteSection(s.id)} confirmText="Hapus kategori ini beserta semua itemnya?" />
              </div>
            )}
          </div>

          <div style={{ overflowX: "auto", border: "0.5px solid var(--color-border-tertiary)", borderRadius: 8 }}>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12 }}>
              <thead>
                <tr>
                  <th style={{ ...TH, textAlign: "left", position: "sticky", left: 0, zIndex: 1, minWidth: 150 }}>Item</th>
                  <th style={TH}>Status</th>
                  <th style={TH}>Durasi</th>
                  {s.months.map((m, i) => <th key={i} style={TH}>{m}</th>)}
                </tr>
              </thead>
              <tbody>
                {s.entries.map((e) => {
                  const st = entryState(s, e, now);
                  const pm = e.progressMonth ?? e.targetMonth; // kotak progres default ke bulan target
                  return (
                    <tr key={e.id}>
                      <td style={{ ...TD, textAlign: "left", fontWeight: 500, position: "sticky", left: 0, zIndex: 1, background: "var(--color-background-primary)", minWidth: 150 }}>
                        <button onClick={() => setCpRef({ sectionId: s.id, entryId: e.id })} className="text-left hover:underline" style={{ color: "var(--color-text-primary)" }}>
                          {e.name}
                        </button>
                        {canEdit && (
                          <span className="ml-1.5 inline-flex gap-1 align-middle">
                            <button onClick={() => setEntryEdit({ sectionId: s.id, entry: e })} aria-label="Edit item" style={{ color: "var(--color-text-tertiary)" }}><i className="ti ti-pencil" style={{ fontSize: 12 }} /></button>
                            <DeleteBtn small onDelete={() => deleteEntry(e.id)} confirmText="Hapus item ini?" />
                          </span>
                        )}
                      </td>
                      <td style={TD}>{canEdit ? <StatusSelect entry={e} /> : <Chip tone={statusTone(e.status)}>{e.status || "—"}</Chip>}</td>
                      <td style={{ ...TD, color: "var(--color-text-tertiary)", fontSize: 11 }}>{e.durasi || "—"}</td>
                      {s.months.map((m, i) => {
                        const isProgress = pm != null && i === pm;
                        const isTarget = i === e.targetMonth && i !== pm; // sebulan -> kotak progres yg tampil
                        const hasBox = isProgress || isTarget;
                        return (
                          <td
                            key={i}
                            style={{ ...TD, minWidth: hasBox ? 58 : 40, padding: hasBox ? 0 : undefined, verticalAlign: "middle", outline: drag?.entryId === e.id && i !== drag.fromMonth ? "1.5px dashed var(--color-border-info)" : undefined, outlineOffset: -2 }}
                            onDragOver={(ev) => { if (canEdit && drag?.entryId === e.id) ev.preventDefault(); }}
                            onDrop={(ev) => { ev.preventDefault(); if (drag?.entryId === e.id && i !== drag.fromMonth) setPendingMove({ entry: e, section: s, field: drag.field, toMonth: i }); setDrag(null); }}
                          >
                            {isProgress && (
                              <div
                                draggable={canEdit}
                                onDragStart={() => setDrag({ entryId: e.id, field: "progress", fromMonth: i })}
                                onDragEnd={() => setDrag(null)}
                                onClick={() => setCpRef({ sectionId: s.id, entryId: e.id })}
                                title={canEdit ? "Klik: update checkpoint · Seret: pindah jadwal" : "Klik untuk update checkpoint"}
                                style={{ cursor: canEdit ? "grab" : "pointer" }}
                              >
                                <StateBox state={st.state} done={e.doneCount} total={e.checkpoints.length} />
                              </div>
                            )}
                            {isTarget && (
                              <div
                                draggable={canEdit}
                                onDragStart={() => setDrag({ entryId: e.id, field: "target", fromMonth: i })}
                                onDragEnd={() => setDrag(null)}
                                title={canEdit ? "Seret: pindah target" : undefined}
                                style={{ cursor: canEdit ? "grab" : "default" }}
                              >
                                <TargetBox />
                              </div>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
                {s.entries.length === 0 && (
                  <tr><td style={{ ...TD, textAlign: "left", color: "var(--color-text-tertiary)" }} colSpan={3 + s.months.length}>Belum ada item.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      ))}

      {secEdit && <SectionModal section={secEdit.section} onClose={() => setSecEdit(null)} />}
      {entryEdit && <EntryModal sectionId={entryEdit.sectionId} entry={entryEdit.entry} months={sections.find((x) => x.id === entryEdit.sectionId)?.months ?? []} onClose={() => setEntryEdit(null)} />}
      {cpEntry && <CheckpointModal entry={cpEntry.entry} months={cpEntry.section.months} onClose={() => setCpRef(null)} />}
      {pendingMove && <MoveConfirmModal move={pendingMove} nowISO={nowISO} onClose={() => setPendingMove(null)} />}
    </div>
  );
}

function MoveConfirmModal({ move, nowISO, onClose }: { move: { entry: EntryView; section: SectionView; field: MoveField; toMonth: number }; nowISO: string; onClose: () => void }) {
  const router = useRouter();
  const [pending, start] = useTransition();
  const fromIdx = move.field === "progress" ? move.entry.progressMonth : move.entry.targetMonth;
  const fromLabel = fromIdx != null ? move.section.months[fromIdx] : "—";
  const toLabel = move.section.months[move.toMonth];
  const noun = move.field === "progress" ? "jadwal" : "target";
  const when = new Date(nowISO).toLocaleString("id-ID", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
  function confirm() {
    start(async () => { await moveEntry(move.entry.id, move.field, move.toMonth); onClose(); router.refresh(); });
  }
  return (
    <Modal title="Apakah anda yakin?" onClose={onClose}>
      <p className="text-[13px]" style={{ color: "var(--color-text-secondary)", lineHeight: 1.6 }}>
        Anda akan memindahkan {noun} <b style={{ color: "var(--color-text-primary)" }}>{move.entry.name}</b> di <b style={{ color: "var(--color-text-primary)" }}>{move.section.title}</b> dari <b>{fromLabel}</b> ke <b>{toLabel}</b>. Waktu dan tanggal pemindahan akan tampil di log jadwal.
      </p>
      <div className="mt-2 inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>
        <i className="ti ti-clock" /> {when}
      </div>
      <div className="mt-4 flex justify-end gap-2">
        <button onClick={onClose} className="rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
        <button onClick={confirm} disabled={pending} className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: pending ? 0.6 : 1 }}>{pending && <i className="ti ti-loader-2 animate-spin" />}Pindahkan</button>
      </div>
    </Modal>
  );
}

function DeleteBtn({ onDelete, confirmText, small }: { onDelete: () => Promise<unknown>; confirmText: string; small?: boolean }) {
  const router = useRouter();
  const [confirm, setConfirm] = useState(false);
  const [pending, start] = useTransition();
  if (confirm) {
    return (
      <span className="inline-flex items-center gap-1">
        <button disabled={pending} onClick={() => start(async () => { await onDelete(); router.refresh(); })} className="rounded px-1.5 py-0.5 text-[10px] font-medium" style={{ background: "var(--color-text-danger)", color: "#fff" }}>Hapus</button>
        <button onClick={() => setConfirm(false)} className="rounded px-1.5 py-0.5 text-[10px]" style={{ color: "var(--color-text-secondary)" }}>Batal</button>
      </span>
    );
  }
  return <button onClick={() => setConfirm(true)} aria-label="Hapus" title={confirmText} style={{ color: "var(--color-text-danger)" }}><i className="ti ti-trash" style={{ fontSize: small ? 12 : 13 }} /></button>;
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(0,0,0,0.4)" }} onClick={onClose}>
      <div onClick={(e) => e.stopPropagation()} className="w-full max-w-[460px] rounded-lg p-5" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", maxHeight: "88vh", overflowY: "auto" }}>
        <div className="mb-4 flex items-center justify-between">
          <span className="text-[15px] font-medium">{title}</span>
          <button onClick={onClose} aria-label="Tutup" style={{ color: "var(--color-text-tertiary)" }}><i className="ti ti-x" style={{ fontSize: 18 }} /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

function SectionModal({ section, onClose }: { section: SectionView | null; onClose: () => void }) {
  const router = useRouter();
  const [title, setTitle] = useState(section?.title ?? "");
  const [cluster, setCluster] = useState(section?.cluster != null ? String(section.cluster) : "");
  const [productType, setProductType] = useState(section?.productType ?? "");
  const [range, setRange] = useState(section?.range ?? "2026");
  const [err, setErr] = useState("");
  const [pending, start] = useTransition();
  function save() {
    setErr("");
    if (!title.trim()) { setErr("Nama kategori wajib diisi."); return; }
    if (!cluster.trim()) { setErr("Cluster wajib diisi."); return; }
    if (!productType.trim()) { setErr("Jenis produk wajib diisi."); return; }
    if (!range.trim()) { setErr("Periode/tahun wajib diisi."); return; }
    const fd = new FormData(); fd.set("title", title); fd.set("cluster", cluster); fd.set("productType", productType); fd.set("range", range);
    start(async () => {
      const r = section ? await updateSection(section.id, fd) : await createSection(fd);
      if (r.ok) { onClose(); router.refresh(); } else setErr(r.error);
    });
  }
  return (
    <Modal title={section ? "Edit kategori" : "Tambah kategori"} onClose={onClose}>
      <div className="flex flex-col gap-3">
        <div><label style={lbl}>Nama kategori <span style={{ color: "var(--color-text-danger)" }}>*</span></label><input style={input} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="cth. C6 Tahap 1 — Perizinan" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label style={lbl}>Cluster <span style={{ color: "var(--color-text-danger)" }}>*</span> <span style={{ color: "var(--color-text-tertiary)", fontWeight: 400 }}>(angka)</span></label><input style={input} value={cluster} onChange={(e) => setCluster(e.target.value.replace(/\D/g, ""))} inputMode="numeric" placeholder="cth. 6" /></div>
          <div><label style={lbl}>Jenis produk <span style={{ color: "var(--color-text-danger)" }}>*</span></label><input style={input} value={productType} onChange={(e) => setProductType(e.target.value)} placeholder="cth. Perizinan" /></div>
        </div>
        <div><label style={lbl}>Periode / tahun <span style={{ color: "var(--color-text-danger)" }}>*</span></label><input style={input} value={range} onChange={(e) => setRange(e.target.value)} placeholder="2026" /></div>
        {err && <div className="rounded-md px-2.5 py-1.5 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
          <button onClick={save} disabled={pending} className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: pending ? 0.6 : 1 }}>{pending && <i className="ti ti-loader-2 animate-spin" />}Simpan</button>
        </div>
      </div>
    </Modal>
  );
}

function EntryModal({ sectionId, entry, months, onClose }: { sectionId: string; entry: EntryView | null; months: string[]; onClose: () => void }) {
  const router = useRouter();
  const [name, setName] = useState(entry?.name ?? "");
  const [badan, setBadan] = useState(entry?.badan ?? "");
  const [jenisDokumen, setJenisDokumen] = useState(entry?.jenisDokumen ?? "");
  const [status, setStatus] = useState(entry?.status || "—");
  const [durasi, setDurasi] = useState(entry?.durasi ?? "");
  const [progressMonth, setProgressMonth] = useState<string>(entry?.progressMonth != null ? String(entry.progressMonth) : "");
  const [targetMonth, setTargetMonth] = useState<string>(entry?.targetMonth != null ? String(entry.targetMonth) : "");
  const [err, setErr] = useState("");
  const [pending, start] = useTransition();

  const jenisOptions = useMemo(() => jenisForBadan(badan), [badan]);
  const previewSteps = useMemo(() => checkpointsFor(badan, jenisDokumen), [badan, jenisDokumen]);
  // Edit: ganti jenis dokumen → checkpoint (dan progresnya) direset.
  const jenisWillReset = !!entry && (entry.badan !== badan || entry.jenisDokumen !== jenisDokumen);

  function save() {
    setErr("");
    if (!name.trim()) { setErr("Nama item wajib diisi."); return; }
    if (!badan) { setErr("Badan wajib dipilih."); return; }
    if (!jenisDokumen) { setErr("Jenis dokumen wajib dipilih."); return; }
    if (!durasi.trim()) { setErr("Durasi wajib diisi."); return; }
    if (progressMonth === "") { setErr("Bulan progres wajib dipilih."); return; }
    if (targetMonth === "") { setErr("Bulan target wajib dipilih."); return; }
    const fd = new FormData();
    fd.set("sectionId", sectionId); fd.set("name", name); fd.set("badan", badan); fd.set("jenisDokumen", jenisDokumen);
    fd.set("status", status); fd.set("durasi", durasi); fd.set("progressMonth", progressMonth); fd.set("targetMonth", targetMonth);
    start(async () => {
      const r = entry ? await updateEntry(entry.id, fd) : await createEntry(fd);
      if (r.ok) { onClose(); router.refresh(); } else setErr(r.error);
    });
  }
  return (
    <Modal title={entry ? "Edit item" : "Tambah item"} onClose={onClose}>
      <div className="flex flex-col gap-3">
        <div><label style={lbl}>Nama item <span style={{ color: "var(--color-text-danger)" }}>*</span></label><input style={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="cth. SK Siteplan" /></div>

        <div className="grid grid-cols-2 gap-3">
          <div><label style={lbl}>Badan <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <select style={input} value={badan} onChange={(e) => { setBadan(e.target.value); setJenisDokumen(""); }}>
              <option value="">— Pilih badan —</option>
              {BADAN.map((b) => <option key={b.key} value={b.key}>{b.label}</option>)}
            </select>
          </div>
          <div><label style={lbl}>Jenis dokumen <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <select style={{ ...input, opacity: badan ? 1 : 0.6 }} value={jenisDokumen} disabled={!badan} onChange={(e) => setJenisDokumen(e.target.value)}>
              <option value="">{badan ? "— Pilih jenis —" : "— Pilih badan dulu —"}</option>
              {jenisOptions.map((j) => <option key={j} value={j}>{j}</option>)}
            </select>
          </div>
        </div>

        {previewSteps.length > 0 && (
          <div className="rounded-md p-2.5" style={{ border: "0.5px solid var(--color-border-tertiary)", background: "var(--color-background-secondary)" }}>
            <div className="mb-1.5 text-[11px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
              {previewSteps.length} checkpoint {entry ? "" : "otomatis"} · kotak progres kebagi {previewSteps.length} segmen
            </div>
            <ol className="flex flex-col gap-1" style={{ counterReset: "cp" }}>
              {previewSteps.map((c, i) => (
                <li key={i} className="flex items-start gap-1.5 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                  <span style={{ flex: "none", minWidth: 15, height: 15, borderRadius: "50%", display: "inline-flex", alignItems: "center", justifyContent: "center", fontSize: 9, fontWeight: 600, background: "var(--color-background-tertiary)", color: "var(--color-text-secondary)" }}>{i + 1}</span>
                  <span>{c}</span>
                </li>
              ))}
            </ol>
            {jenisWillReset && <div className="mt-1.5 text-[11px]" style={{ color: "var(--color-text-warning)" }}>⚠ Ganti jenis dokumen akan mengganti daftar checkpoint & mereset progres langkah.</div>}
          </div>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div><label style={lbl}>Status <span style={{ color: "var(--color-text-danger)" }}>*</span></label><select style={input} value={status} onChange={(e) => setStatus(e.target.value)}>{STATUS_OPTIONS.map((o) => <option key={o} value={o}>{o}</option>)}</select></div>
          <div><label style={lbl}>Durasi <span style={{ color: "var(--color-text-danger)" }}>*</span></label><input style={input} value={durasi} onChange={(e) => setDurasi(e.target.value)} placeholder="cth. 3 Bulan" /></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><label style={lbl}>Bulan progres <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <select style={input} value={progressMonth} onChange={(e) => setProgressMonth(e.target.value)}>
              <option value="">— Pilih bulan —</option>
              {months.map((m, i) => <option key={i} value={i}>{m}</option>)}
            </select>
          </div>
          <div><label style={lbl}>Bulan target (tenggat) <span style={{ color: "var(--color-text-danger)" }}>*</span></label>
            <select style={input} value={targetMonth} onChange={(e) => setTargetMonth(e.target.value)}>
              <option value="">— Pilih bulan —</option>
              {months.map((m, i) => <option key={i} value={i}>{m}</option>)}
            </select>
          </div>
        </div>
        {err && <div className="rounded-md px-2.5 py-1.5 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}>Batal</button>
          <button onClick={save} disabled={pending} className="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-[13px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: pending ? 0.6 : 1 }}>{pending && <i className="ti ti-loader-2 animate-spin" />}Simpan</button>
        </div>
      </div>
    </Modal>
  );
}

function CheckpointModal({ entry, months, onClose }: { entry: EntryView; months: string[]; onClose: () => void }) {
  return (
    <Modal title={entry.name} onClose={onClose}>
      {(entry.badan || entry.jenisDokumen) && (
        <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
          {entry.badan && <Chip tone="neutral">{badanLabel(entry.badan)}</Chip>}
          {entry.jenisDokumen && <Chip tone="info">{entry.jenisDokumen}</Chip>}
        </div>
      )}
      <div className="mb-3 text-[12px]" style={{ color: "var(--color-text-secondary)" }}>
        <span className="font-medium" style={{ color: "var(--color-text-primary)" }}>{entry.doneCount}/{entry.checkpoints.length}</span> checkpoint selesai{entry.checkpoints.length > 0 && entry.doneCount >= entry.checkpoints.length ? " · DONE" : ""}
      </div>
      <div className="flex flex-col gap-2.5">
        {entry.checkpoints.map((c) => <CheckpointRow key={c.id} cp={c} />)}
      </div>

      {entry.moves.length > 0 && (
        <div className="mt-4">
          <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
            <i className="ti ti-history" /> Log jadwal ({entry.moves.length})
          </div>
          <div className="flex flex-col gap-1">
            {entry.moves.map((mv) => (
              <div key={mv.id} className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>
                Dipindah <b style={{ color: "var(--color-text-secondary)" }}>{mv.fromMonth != null ? months[mv.fromMonth] : "—"} → {mv.toMonth != null ? months[mv.toMonth] : "—"}</b> · {mv.byName ?? "—"} · {new Date(mv.createdAt).toLocaleString("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })}
              </div>
            ))}
          </div>
        </div>
      )}
    </Modal>
  );
}

function CheckpointRow({ cp }: { cp: CheckpointView }) {
  const router = useRouter();
  const label = checkpointLabel(cp.step, cp.order);
  const [done, setDone] = useState(cp.done);
  const [note, setNote] = useState(cp.note ?? "");
  const [file, setFile] = useState<File | null>(null);
  const [pending, start] = useTransition();
  const [saved, setSaved] = useState(false);
  const [err, setErr] = useState("");

  // Tidak boleh menyelesaikan langkah tanpa bukti (baru atau yang sudah ada).
  const needsProof = done && !file && !cp.proofName;

  function save() {
    setErr("");
    if (needsProof) { setErr("Lampirkan bukti dulu untuk menyelesaikan langkah."); return; }
    const fd = new FormData();
    fd.set("done", done ? "true" : "false");
    fd.set("note", note);
    if (file) fd.set("proof", file);
    start(async () => {
      const r = await setCheckpoint(cp.id, fd);
      if (r.ok) { setSaved(true); router.refresh(); setTimeout(() => setSaved(false), 1500); }
      else setErr(r.error);
    });
  }

  return (
    <div className="rounded-md p-3" style={{ border: `0.5px solid ${done ? "var(--color-border-success)" : "var(--color-border-tertiary)"}`, background: done ? "var(--color-background-success)" : "var(--color-background-secondary)" }}>
      <label className="flex items-center gap-2 text-[13px] font-medium" style={{ cursor: "pointer" }}>
        <input type="checkbox" checked={done} onChange={(e) => setDone(e.target.checked)} />
        {label}
      </label>
      {cp.done && cp.doneByName && (
        <div className="mt-1 text-[11px]" style={{ color: "var(--color-text-secondary)" }}>
          oleh {cp.doneByName}{cp.doneAt ? ` · ${new Date(cp.doneAt).toLocaleString("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })}` : ""}
        </div>
      )}
      <div className="mt-2 flex flex-col gap-1.5">
        <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Catatan (opsional)" style={{ ...input, fontSize: 12, padding: "6px 8px" }} />
        <div className="flex flex-wrap items-center gap-2 text-[11px]">
          <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-[11px]" style={{ maxWidth: 200 }} />
          {cp.proofName && (
            <a href={withBase(`/api/timeline/proof/${cp.id}`)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1" style={{ color: "var(--color-text-info)" }}>
              <i className="ti ti-paperclip" /> {cp.proofName}
            </a>
          )}
        </div>
        {needsProof && <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>* Bukti wajib dilampirkan untuk menyelesaikan langkah.</div>}
        {err && <div className="rounded-md px-2.5 py-1.5 text-[11px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{err}</div>}
        <div>
          <button onClick={save} disabled={pending || needsProof} className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)", opacity: pending || needsProof ? 0.5 : 1 }}>
            {pending ? <i className="ti ti-loader-2 animate-spin" /> : saved ? <i className="ti ti-check" /> : null}
            {saved ? "Tersimpan" : "Simpan langkah"}
          </button>
        </div>
      </div>
    </div>
  );
}
