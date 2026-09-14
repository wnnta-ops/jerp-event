"use client";

import { Fragment, useState } from "react";
import { MATRIX_COLS, MATRIX_ROWS, type MatrixRow } from "@/lib/matrix-def";

type ColMap = Record<string, number>;

// Shared read-only / editable matrix table. Used by the Matrix Report page and
// the Collection Input tab. When `accordion` is on, rows are grouped under
// collapsible section headers (used for data entry so the grid isn't one long
// scroll). Report/Preview render the full flat table.
export function MatrixGrid({
  derived,
  manual,
  editable = false,
  accordion = false,
  onSave,
}: {
  derived: Record<string, ColMap>;
  manual: Record<string, number | null>;
  editable?: boolean;
  accordion?: boolean;
  onSave?: (rowKey: string, colKey: string, raw: string) => void;
}) {
  const key = (row: string, col: string) => `${row}::${col}`;
  const tu = derived.TOTAL_UNIT ?? {};
  const c3tot = (tu.c31_32 ?? 0) + (tu.c33 ?? 0) + (tu.c34 ?? 0);
  const c5tot = (tu.c51 ?? 0) + (tu.c52 ?? 0);

  // group rows into sections for accordion mode
  const sections: { id: string; label: string; note?: string; rows: MatrixRow[] }[] = [];
  {
    let cur: (typeof sections)[number] = { id: "__top", label: "Stok & SPR", rows: [] };
    sections.push(cur);
    for (const row of MATRIX_ROWS) {
      if (row.sep) continue;
      if (row.section) {
        cur = { id: row.section, label: row.section, note: row.note, rows: [] };
        sections.push(cur);
        continue;
      }
      cur.rows.push(row);
    }
  }
  // default: first section open, rest collapsed
  const [collapsed, setCollapsed] = useState<Set<string>>(
    () => new Set(sections.slice(1).map((s) => s.id)),
  );
  const toggle = (id: string) =>
    setCollapsed((c) => {
      const n = new Set(c);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  const allOpen = collapsed.size === 0;
  const setAll = (open: boolean) =>
    setCollapsed(open ? new Set() : new Set(sections.map((s) => s.id)));

  function cellVal(rowKey: string, kind: string | undefined, col: string): number | null {
    if (kind === "input" || col === "c1") {
      const v = manual[key(rowKey, col)];
      return v === undefined ? null : v;
    }
    return derived[rowKey]?.[col] ?? 0;
  }
  function rowTotal(rowKey: string, kind: string | undefined): number {
    return MATRIX_COLS.reduce((s, c) => s + (cellVal(rowKey, kind, c.key) ?? 0), 0);
  }
  const fmt = (v: number | null) => (v === null ? "" : v === 0 ? "-" : String(v));

  function DataRow({ row, i }: { row: MatrixRow; i: number }) {
    const rk = row.key!;
    const total = rowTotal(rk, row.kind);
    const target = manual[key(rk, "target")];
    const dev = target != null ? total - target : null;
    return (
      <tr key={i} className={`${row.strong ? "strong" : ""} ${row.hi ? "hi" : ""}`}>
        <td className="ket">{row.label}</td>
        {MATRIX_COLS.map((c) => {
          const isInput = row.kind === "input" || c.key === "c1";
          const v = cellVal(rk, row.kind, c.key);
          return (
            <td key={c.key} className={isInput ? "inp" : "auto"}>
              {isInput && editable && onSave ? (
                <input
                  defaultValue={v ?? ""}
                  onBlur={(e) => onSave(rk, c.key, e.target.value)}
                  inputMode="numeric"
                  placeholder="0"
                />
              ) : (
                fmt(v)
              )}
            </td>
          );
        })}
        <td className="total">{total === 0 ? "-" : total}</td>
        <td className="inp">
          {editable && onSave ? (
            <input
              defaultValue={target ?? ""}
              onBlur={(e) => onSave(rk, "target", e.target.value)}
              inputMode="numeric"
              placeholder="0"
            />
          ) : (
            target ?? "-"
          )}
        </td>
        <td className={dev != null && dev < 0 ? "dev neg" : "dev"}>{dev ?? ""}</td>
      </tr>
    );
  }

  const head = (
    <thead>
      <tr>
        <th rowSpan={3} className="ket">
          Keterangan
        </th>
        <th colSpan={7}>Cluster</th>
        <th rowSpan={3}>TOTAL</th>
        <th rowSpan={3}>TARGET</th>
        <th rowSpan={3}>Dev</th>
      </tr>
      <tr>
        <th>C1</th>
        <th>C2</th>
        <th colSpan={3}>TOTAL C3 : {c3tot}</th>
        <th colSpan={2}>TOTAL C5 : {c5tot}</th>
      </tr>
      <tr>
        {MATRIX_COLS.map((c) => (
          <th key={c.key}>{c.label}</th>
        ))}
      </tr>
    </thead>
  );

  if (accordion) {
    return (
      <>
        <div className="mtx-acc-bar no-print">
          <button className="btn ghost sm" onClick={() => setAll(!allOpen)}>
            {allOpen ? "▴ Tutup semua" : "▾ Buka semua"}
          </button>
        </div>
        <div className="tablewrap">
          <table className={`mtx ${editable ? "edit cstyle" : ""}`}>
            {head}
            <tbody>
              {sections.map((sec, si) => {
                if (sec.rows.length === 0) return null;
                const isClosed = collapsed.has(sec.id);
                return (
                  <Fragment key={si}>
                    <tr className="mtx-section mtx-acc" onClick={() => toggle(sec.id)}>
                      <td colSpan={11}>
                        <span className="mtx-acc-chev">{isClosed ? "▸" : "▾"}</span>
                        {sec.label}
                        {sec.note && <span className="mtx-note">{sec.note}</span>}
                        {isClosed && <span className="mtx-acc-count">{sec.rows.length} baris</span>}
                      </td>
                    </tr>
                    {!isClosed && sec.rows.map((row, i) => <DataRow key={`${si}-${i}`} row={row} i={i} />)}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      </>
    );
  }

  return (
    <div className="tablewrap">
      <table className={`mtx ${editable ? "edit cstyle" : ""}`}>
        {head}
        <tbody>
          {MATRIX_ROWS.map((row, i) => {
            if (row.sep) return <tr key={i} className="mtx-sep"><td colSpan={11} /></tr>;
            if (row.section)
              return (
                <tr key={i} className="mtx-section">
                  <td colSpan={11}>
                    {row.section}
                    {row.note && <span className="mtx-note">{row.note}</span>}
                  </td>
                </tr>
              );
            return <DataRow key={i} row={row} i={i} />;
          })}
        </tbody>
      </table>
    </div>
  );
}
