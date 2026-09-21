"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { format } from "date-fns";
import { id as idLocale } from "date-fns/locale";
import { withBase } from "@/lib/base-path";
import { StatusBadge, TypeBadge, Avatar } from "./ui";
import { DocumentDrawer } from "./document-drawer";
import { keyDate, isExpiringSoon, daysUntil } from "@/lib/format";
import { initialsOf } from "@/lib/utils";
import {
  DOC_STATUSES,
  DOC_STATUS_LABELS,
  DOC_TYPES,
  DOC_TYPE_LABELS,
  STATUS_TONE,
  type DocStatus,
  type DocType,
} from "@/lib/enums";

export type DocRow = {
  id: string;
  docType: DocType;
  title: string;
  docNumber: string | null;
  scope: string;
  cluster: string | null;
  blok: string | null;
  unitNo: string | null;
  buyerName: string | null;
  entityName: string | null;
  status: DocStatus;
  issueDate: string | null;
  expiryDate: string | null;
  originalLocation: string;
  originalLocationDetail: string | null;
  version: string | null;
  isCurrentApproved: boolean;
  accessLevel: string;
  objectLocation: string | null;
  landArea: string | null;
  notes: string | null;
  description: string | null;
  boardStructure: string | null;
  authorizedCapital: string | null;
  issuedCapital: string | null;
  shareholders: string | null;
  parties: string | null;
  reminderDate: string | null;
  reminderNote: string | null;
  // AJB — tautan ke SPR (Final) di Marketing & Sales + data aktanya.
  sprNumber: string | null;
  notaryName: string | null;
  ajbValue: string | null;
  sprSyncedAt: string | null;
  sprSyncError: string | null;
  fileCount: number;
  files: { url: string; fileName: string; mimeType: string | null }[];
  relatedIds: string[];
  picName: string;
  picInitials: string;
  uploaderName: string;
  uploadedAt: string;
  updatedAt: string;
  lastEditedByName: string | null;
  events: { action: string; detail: string | null; actorName: string | null; createdAt: string }[];
};

type TabKey = "all" | "perizinan" | "sertifikasi" | "korporasi" | "perjanjian" | "aset";
const TABS: { key: TabKey; label: string }[] = [
  { key: "all", label: "Semua" },
  { key: "perizinan", label: "Perizinan" },
  { key: "sertifikasi", label: "Sertifikasi" },
  { key: "korporasi", label: "Korporasi" },
  { key: "perjanjian", label: "Perjanjian" },
  { key: "aset", label: "Aset" },
];

const PERIZINAN_TYPES: DocType[] = ["IzinLokasi", "IzinLingkungan", "SKRK", "PBGInduk", "PBGSplit", "AJB"];
const SERTIFIKASI_TYPES: DocType[] = ["PetaBidang", "SKHGB", "SHGBInduk", "SHGBSplit", "PBBSplit"];
const KORPORASI_TYPES: DocType[] = ["AktaPendirian", "RUPS"];
const PERJANJIAN_TYPES: DocType[] = ["PerjanjianAgent", "PerjanjianKPR", "PerjanjianKredit", "PerjanjianJaminanAset", "PerjanjianPihakKetiga"];
const ASET_TYPES: DocType[] = ["Aset"];

// Pipeline order for entity retrieval — roughly follows the document lifecycle.
const PIPELINE_RANK: Record<DocType, number> = {
  IzinLokasi: 1, IzinLingkungan: 2, SKRK: 3, PBGInduk: 4, PBGSplit: 5, AJB: 5.5,
  PetaBidang: 6, SKHGB: 7, SHGBInduk: 8, SHGBSplit: 9, PBBSplit: 10,
  AktaPendirian: 11, RUPS: 12,
  PerjanjianAgent: 13, PerjanjianKPR: 14, PerjanjianKredit: 15, PerjanjianJaminanAset: 16, PerjanjianPihakKetiga: 17,
  Aset: 18,
  Other: 99,
};

type Entity = { kind: "buyer" | "unit"; key: string; label: string };

function inTab(doc: DocRow, tab: TabKey): boolean {
  switch (tab) {
    case "perizinan": return PERIZINAN_TYPES.includes(doc.docType);
    case "sertifikasi": return SERTIFIKASI_TYPES.includes(doc.docType);
    case "korporasi": return KORPORASI_TYPES.includes(doc.docType);
    case "perjanjian": return PERJANJIAN_TYPES.includes(doc.docType);
    case "aset": return ASET_TYPES.includes(doc.docType);
    default: return true;
  }
}

function unitKey(d: DocRow): string {
  return [d.cluster, d.blok, d.unitNo].filter(Boolean).join("|");
}
function unitLabel(d: DocRow): string {
  return [d.cluster, d.blok, d.unitNo].filter(Boolean).join(" · ");
}

/**
 * Token (word) match, order-independent: every whitespace-separated term in the
 * query must appear somewhere in the haystack. So "ktp aza" matches "KTP Bu Erwita
 * (PT AZA AP)" — it is NOT a single left-to-right substring scan.
 */
function matchTokens(hay: string, q: string): boolean {
  const terms = q.toLowerCase().split(/\s+/).filter(Boolean);
  if (!terms.length) return true;
  const h = hay.toLowerCase();
  return terms.every((t) => h.includes(t));
}

function matchesQuery(doc: DocRow, q: string): boolean {
  const hay = [
    doc.title, doc.docNumber, DOC_TYPE_LABELS[doc.docType], doc.docType,
    doc.cluster, doc.blok, doc.unitNo, doc.buyerName, doc.entityName, doc.picName,
  ].filter(Boolean).join(" ");
  return matchTokens(hay, q);
}

function matchesEntity(doc: DocRow, entity: Entity): boolean {
  return entity.kind === "buyer" ? doc.buyerName === entity.key : unitKey(doc) === entity.key;
}

const chipStyle: React.CSSProperties = {
  fontSize: 12, padding: "6px 10px", borderRadius: 8,
  border: "0.5px solid var(--color-border-tertiary)",
  color: "var(--color-text-secondary)", background: "var(--color-background-primary)",
};

const TONE_FG: Record<string, string> = {
  neutral: "var(--color-text-secondary)",
  secondary: "var(--color-text-secondary)",
  info: "var(--color-text-info)",
  success: "var(--color-text-success)",
  warning: "var(--color-text-warning)",
  danger: "var(--color-text-danger)",
};

export function DirectoryTable({ docs, canEdit = false }: { docs: DocRow[]; canEdit?: boolean }) {
  const [now] = useState(() => new Date());
  const [tab, setTab] = useState<TabKey>("all");
  const [query, setQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState<DocType | "">("");
  const [placeFilter, setPlaceFilter] = useState(""); // "c:<cluster>" or "e:<entityName>"
  const [statusFilter, setStatusFilter] = useState<DocStatus | "">("");
  const [expiringOnly, setExpiringOnly] = useState(false);
  const [entity, setEntity] = useState<Entity | null>(null);
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<DocRow | null>(null);
  const blurTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Keep the open drawer in sync with refreshed data (e.g. after a status edit).
  useEffect(() => {
    setSelected((cur) => (cur ? docs.find((d) => d.id === cur.id) ?? null : null));
  }, [docs]);

  // Filter dimension = "proyek/PT": clusters (project areas) + entities (PTs).
  const places = useMemo(() => ({
    clusters: Array.from(new Set(docs.map((d) => d.cluster).filter(Boolean) as string[])).sort(),
    entities: Array.from(new Set(docs.map((d) => d.entityName).filter(Boolean) as string[])).sort(),
  }), [docs]);

  // Type filter is scoped to the ACTIVE tab — only the types that belong to that
  // tab (and exist in the data). On "Semua" it's every type present.
  const typesForTab = useMemo(() => {
    const groups: Partial<Record<TabKey, DocType[]>> = {
      perizinan: PERIZINAN_TYPES, sertifikasi: SERTIFIKASI_TYPES, korporasi: KORPORASI_TYPES, perjanjian: PERJANJIAN_TYPES, aset: [],
    };
    const allowed = tab === "all" ? [...DOC_TYPES] : (groups[tab] ?? []);
    return allowed.filter((t) => docs.some((d) => d.docType === t));
  }, [tab, docs]);


  // ---- grouped autocomplete suggestions ----
  const suggestions = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q || entity) return null;

    const buyers = new Map<string, { name: string; count: number; unit: string }>();
    const units = new Map<string, { key: string; label: string; count: number }>();
    for (const d of docs) {
      if (d.buyerName && matchTokens(d.buyerName, q)) {
        const e = buyers.get(d.buyerName) ?? { name: d.buyerName, count: 0, unit: unitLabel(d) };
        e.count += 1;
        if (!e.unit && unitLabel(d)) e.unit = unitLabel(d);
        buyers.set(d.buyerName, e);
      }
      const uk = unitKey(d);
      if (uk && (matchTokens(uk, q) || matchTokens(unitLabel(d), q))) {
        const e = units.get(uk) ?? { key: uk, label: unitLabel(d), count: 0 };
        e.count += 1;
        units.set(uk, e);
      }
    }
    const documents = docs
      .filter((d) =>
        matchTokens([d.title, d.docNumber, DOC_TYPE_LABELS[d.docType], d.docType, d.entityName].filter(Boolean).join(" "), q),
      )
      .slice(0, 8);

    const result = {
      buyers: [...buyers.values()].slice(0, 4),
      units: [...units.values()].slice(0, 4),
      documents,
    };
    if (!result.buyers.length && !result.units.length && !result.documents.length) return null;
    return result;
  }, [docs, query, entity]);

  // ---- base set (entity retrieval overrides tab + text search) ----
  const base = useMemo(() => {
    if (entity) {
      return docs
        .filter((d) => matchesEntity(d, entity))
        .slice()
        .sort((a, b) => PIPELINE_RANK[a.docType] - PIPELINE_RANK[b.docType] || +new Date(b.uploadedAt) - +new Date(a.uploadedAt));
    }
    return docs
      .filter((d) => inTab(d, tab) && matchesQuery(d, query))
      .slice()
      .sort((a, b) => +new Date(b.uploadedAt) - +new Date(a.uploadedAt));
  }, [docs, entity, tab, query]);

  const preExpiring = useMemo(
    () =>
      base.filter(
        (d) =>
          (!typeFilter || d.docType === typeFilter) &&
          (!placeFilter ||
            (placeFilter.startsWith("e:") ? d.entityName === placeFilter.slice(2) : d.cluster === placeFilter.slice(2))) &&
          (!statusFilter || d.status === statusFilter),
      ),
    [base, typeFilter, placeFilter, statusFilter],
  );

  const expiringCount = useMemo(
    () => preExpiring.filter((d) => isExpiringSoon(d.expiryDate, now)).length,
    [preExpiring, now],
  );

  const rows = expiringOnly ? preExpiring.filter((d) => isExpiringSoon(d.expiryDate, now)) : preExpiring;
  const hasActiveFilters = !!(query || typeFilter || placeFilter || statusFilter || expiringOnly || entity);

  function pickBuyer(name: string, unit: string) {
    setEntity({ kind: "buyer", key: name, label: `${name}${unit ? ` · ${unit}` : ""}` });
    setQuery(""); setOpen(false);
  }
  function pickUnit(key: string, label: string) {
    setEntity({ kind: "unit", key, label });
    setQuery(""); setOpen(false);
  }
  function pickDocument(d: DocRow) {
    setQuery(d.title); setOpen(false);
  }

  return (
    <div className="w-full">
      {/* Sticky header: title + tabs + filters stay locked under the app header (57px) */}
      <div
        style={{
          position: "sticky", top: 56, zIndex: 5,
          background: "var(--color-background-tertiary)",
          borderBottom: "0.5px solid var(--color-border-tertiary)",
          paddingTop: 8, paddingBottom: 6,
        }}
      >
        <div className="mb-3">
          <h1 className="text-[22px] font-medium" style={{ color: "var(--color-text-primary)" }}>Direktori dokumen legal</h1>
          <p className="text-xs" style={{ color: "var(--color-text-secondary)" }}>{docs.length} dokumen tersimpan · MUP / Java Residence</p>
        </div>
      {/* Tabs + upload */}
      <div className="flex flex-wrap items-center justify-between gap-3" style={{ borderBottom: "0.5px solid var(--color-border-tertiary)" }}>
        <div className="flex items-end gap-1">
          {TABS.map((t) => {
            const active = t.key === tab && !entity;
            return (
              <button
                key={t.key}
                onClick={() => { setTab(t.key); setEntity(null); setTypeFilter(""); }}
                className="px-3 py-2 text-[13px]"
                style={{
                  fontWeight: active ? 500 : 400,
                  color: active ? "var(--color-text-primary)" : "var(--color-text-secondary)",
                  borderBottom: active ? "2px solid var(--color-text-info)" : "2px solid transparent",
                }}
              >
                {t.label}
              </button>
            );
          })}
        </div>
        <div className="mb-1.5 flex items-center gap-2">
          <a
            href={withBase(`/api/export?tab=${tab}`)}
            title={tab === "all" ? "Export semua tab (5 sheet)" : "Export tab ini ke Excel"}
            className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-medium"
            style={{ border: "0.5px solid var(--color-border-secondary)", color: "var(--color-text-secondary)" }}
          >
            <i className="ti ti-file-spreadsheet" />
            Export Excel
          </a>
          <Link
            href="/documents/new"
            className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-medium"
            style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}
          >
            <i className="ti ti-upload" />
            Unggah dokumen
          </Link>
        </div>
      </div>

      {/* Toolbar */}
      <div className="my-3.5 flex flex-wrap items-center gap-2">
        <div className="relative min-w-[180px] flex-1">
          <div
            className="inline-flex w-full items-center gap-1.5 rounded-md px-2.5 py-1.5"
            style={{
              border: `0.5px solid ${open && suggestions ? "var(--color-border-secondary)" : "var(--color-border-tertiary)"}`,
              background: "var(--color-background-primary)",
              boxShadow: open && suggestions ? "0 0 0 3px var(--color-background-info)" : "none",
            }}
          >
            <i className="ti ti-search" style={{ fontSize: 15, color: "var(--color-text-tertiary)" }} />
            <input
              value={query}
              onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
              onFocus={() => setOpen(true)}
              onBlur={() => { blurTimer.current = setTimeout(() => setOpen(false), 150); }}
              onKeyDown={(e) => { if (e.key === "Escape") { setOpen(false); (e.target as HTMLInputElement).blur(); } }}
              placeholder="Cari judul, no., unit, pembeli…"
              className="w-full bg-transparent text-[12px] outline-none"
              style={{ color: "var(--color-text-primary)" }}
            />
            {query && (
              <button onMouseDown={(e) => e.preventDefault()} onClick={() => setQuery("")} aria-label="Bersihkan" style={{ color: "var(--color-text-tertiary)" }}>
                <i className="ti ti-x" style={{ fontSize: 14 }} />
              </button>
            )}
          </div>

          {/* Grouped autocomplete */}
          {open && suggestions && (
            <div
              className="absolute left-0 right-0 z-20 mt-1.5 overflow-hidden rounded-md"
              style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", boxShadow: "0 8px 24px rgba(0,0,0,0.12)" }}
              onMouseDown={(e) => { e.preventDefault(); if (blurTimer.current) clearTimeout(blurTimer.current); }}
            >
              {suggestions.buyers.length > 0 && (
                <>
                  <div className="px-3 pb-1 pt-2.5 text-[11px] font-medium" style={{ color: "var(--color-text-tertiary)" }}>Pembeli</div>
                  {suggestions.buyers.map((b) => (
                    <button key={b.name} onClick={() => pickBuyer(b.name, b.unit)} className="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:brightness-95" style={{ background: "var(--color-background-info)" }}>
                      <Avatar initials={initialsOf(b.name)} size={26} />
                      <div className="min-w-0 flex-1">
                        <div className="text-[13px] font-medium">{b.name}</div>
                        <div className="truncate text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{b.unit}</div>
                      </div>
                      <span className="text-[11px] font-medium" style={{ color: "var(--color-text-info)" }}>{b.count} dokumen</span>
                    </button>
                  ))}
                </>
              )}

              {suggestions.units.length > 0 && (
                <>
                  <div className="border-t px-3 pb-1 pt-2.5 text-[11px] font-medium" style={{ color: "var(--color-text-tertiary)", borderColor: "var(--color-border-tertiary)" }}>Unit</div>
                  {suggestions.units.map((u) => (
                    <button key={u.key} onClick={() => pickUnit(u.key, u.label)} className="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:brightness-95" style={{ background: "var(--color-background-primary)" }}>
                      <span className="flex h-[26px] w-[26px] items-center justify-center rounded-md" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>
                        <i className="ti ti-home" style={{ fontSize: 14 }} />
                      </span>
                      <div className="min-w-0 flex-1 text-[13px]">{u.label}</div>
                      <span className="text-[11px] font-medium" style={{ color: "var(--color-text-info)" }}>{u.count} dokumen</span>
                    </button>
                  ))}
                </>
              )}

              {suggestions.documents.length > 0 && (
                <>
                  <div className="border-t px-3 pb-1 pt-2.5 text-[11px] font-medium" style={{ color: "var(--color-text-tertiary)", borderColor: "var(--color-border-tertiary)" }}>Dokumen</div>
                  {suggestions.documents.map((d) => (
                    <button key={d.id} onClick={() => pickDocument(d)} className="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:brightness-95" style={{ background: "var(--color-background-primary)" }}>
                      <TypeBadge docType={d.docType} />
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-[13px]">{d.title}</div>
                        {d.docNumber && <div className="truncate text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{d.docNumber}</div>}
                      </div>
                      <span className="text-[11px]" style={{ color: TONE_FG[STATUS_TONE[d.status]] }}>{DOC_STATUS_LABELS[d.status]}</span>
                    </button>
                  ))}
                </>
              )}

              {/* Entity-retrieval footer for the top buyer */}
              {suggestions.buyers[0] && (
                <button
                  onClick={() => pickBuyer(suggestions.buyers[0].name, suggestions.buyers[0].unit)}
                  className="flex w-full items-center gap-1.5 border-t px-3 py-2.5 text-left"
                  style={{ color: "var(--color-text-info)", borderColor: "var(--color-border-tertiary)" }}
                >
                  <i className="ti ti-arrow-right" style={{ fontSize: 14 }} />
                  <span className="text-[12px] font-medium">Lihat semua {suggestions.buyers[0].count} dokumen milik {suggestions.buyers[0].name}</span>
                </button>
              )}
            </div>
          )}
        </div>

        <select style={chipStyle} value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as DocType | "")}>
          <option value="">Semua tipe</option>
          {typesForTab.map((t) => <option key={t} value={t}>{DOC_TYPE_LABELS[t]}</option>)}
        </select>
        <select style={chipStyle} value={placeFilter} onChange={(e) => setPlaceFilter(e.target.value)}>
          <option value="">Semua proyek/PT</option>
          {places.clusters.length > 0 && (
            <optgroup label="Cluster">
              {places.clusters.map((c) => <option key={c} value={`c:${c}`}>{c}</option>)}
            </optgroup>
          )}
          {places.entities.length > 0 && (
            <optgroup label="PT">
              {places.entities.map((e) => <option key={e} value={`e:${e}`}>{e}</option>)}
            </optgroup>
          )}
        </select>
        <select style={chipStyle} value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as DocStatus | "")}>
          <option value="">Semua status</option>
          {DOC_STATUSES.map((s) => <option key={s} value={s}>{DOC_STATUS_LABELS[s]}</option>)}
        </select>
        <button
          onClick={() => setExpiringOnly((v) => !v)}
          className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium"
          style={{ background: "var(--color-background-warning)", color: "var(--color-text-warning)", outline: expiringOnly ? "1.5px solid var(--color-border-warning)" : "none", outlineOffset: -1 }}
          title="Saring dokumen yang kedaluwarsa ≤30 hari"
        >
          <i className="ti ti-clock" style={{ fontSize: 14 }} />
          {expiringCount} akan kedaluwarsa ≤30 hari
        </button>
      </div>

      {/* Active entity chip (entity retrieval) */}
      {entity && (
        <div className="mb-3 flex items-center gap-2">
          <span
            className="inline-flex items-center gap-2 rounded-md px-2.5 py-1.5 text-[12px] font-medium"
            style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}
          >
            <i className={`ti ${entity.kind === "buyer" ? "ti-user" : "ti-home"}`} style={{ fontSize: 14 }} />
            Berkas {entity.label} · {base.length} dokumen
            <button onClick={() => setEntity(null)} aria-label="Tutup berkas" className="ml-1">
              <i className="ti ti-x" style={{ fontSize: 14 }} />
            </button>
          </span>
          <span className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Urut sesuai alur (PPJB → KPR → AJB → Sertifikat)</span>
        </div>
      )}

      </div>
      {/* Table */}
      <table className="w-full table-fixed border-collapse">
        <thead>
          <tr className="text-left">
            {[["Dokumen", "36%"], ["Unit", "19%"], ["Status", "18%"], ["Tanggal kunci", "16%"], ["PIC", "11%"]].map(([label, w]) => (
              <th key={label} style={{ width: w, color: "var(--color-text-tertiary)" }} className="pb-2 pr-2 text-[11px] font-medium">{label}</th>
            ))}
          </tr>
        </thead>
        <tbody className="text-[12px]" style={{ color: "var(--color-text-primary)" }}>
          {rows.map((d) => {
            const kd = keyDate(d, now);
            const sub = [
              d.docNumber ?? (d.version ? `Versi ${d.version}` : null),
              `${d.uploaderName.split(" ")[0]} · ${format(new Date(d.uploadedAt), "d MMM", { locale: idLocale })}`,
            ].filter(Boolean).join(" · ");
            return (
              <tr
                key={d.id}
                onClick={() => setSelected(d)}
                className="cursor-pointer hover:bg-[var(--color-background-secondary)]"
                style={{ borderTop: "0.5px solid var(--color-border-tertiary)" }}
              >
                <td className="py-2.5 pr-2 align-top">
                  <TypeBadge docType={d.docType} />
                  <div className="mt-1 flex items-center gap-1.5">
                    <span className="font-medium">{d.title}</span>
                    {d.reminderDate && (
                      <i
                        className="ti ti-bell"
                        title={`Reminder pembaruan: ${format(new Date(d.reminderDate), "d MMM yyyy", { locale: idLocale })}${d.reminderNote ? ` — ${d.reminderNote}` : ""}`}
                        style={{
                          fontSize: 12,
                          color: daysUntil(d.reminderDate, now) <= 30 ? "var(--color-text-warning)" : "var(--color-text-tertiary)",
                        }}
                      />
                    )}
                  </div>
                  <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{sub}</div>
                </td>
                <td className="py-2.5 pr-2 align-top">
                  {d.scope === "unit" ? (
                    <>
                      <div>{d.cluster}</div>
                      <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{[d.blok, d.unitNo].filter(Boolean).join(" · ")}</div>
                    </>
                  ) : d.scope === "entity" ? (
                    <>
                      <div>{d.entityName || "Entitas"}</div>
                      <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Tingkat entitas / PT</div>
                    </>
                  ) : (
                    <>
                      <div>Seluruh development</div>
                      <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Tingkat developer</div>
                    </>
                  )}
                </td>
                <td className="py-2.5 pr-2 align-top"><StatusBadge status={d.status} /></td>
                <td className="py-2.5 pr-2 align-top" style={{ color: TONE_FG[kd.tone], fontWeight: kd.tone === "secondary" ? 400 : 500 }}>{kd.text}</td>
                <td className="py-2.5 align-top" title={d.picName}><Avatar initials={d.picInitials} /></td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {rows.length === 0 && (
        <div className="mt-3 rounded-lg px-5 py-10 text-center" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
          <i className="ti ti-search-off" style={{ fontSize: 24, color: "var(--color-text-tertiary)" }} />
          <p className="mt-2 text-sm font-medium">Tidak ada dokumen cocok</p>
          <p className="mt-1 text-xs" style={{ color: "var(--color-text-tertiary)" }}>
            {hasActiveFilters ? "Coba ubah atau hapus filter." : "Direktori masih kosong."}
          </p>
        </div>
      )}

      <DocumentDrawer doc={selected} docs={docs} canEdit={canEdit} onClose={() => setSelected(null)} onSelect={(d) => setSelected(d)} />
    </div>
  );
}
