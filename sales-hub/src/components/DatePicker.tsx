"use client";

import { useEffect, useMemo, useRef, useState } from "react";

const MONTHS = [
  "Januari", "Februari", "Maret", "April", "Mei", "Juni",
  "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];
const DOW = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

const pad = (n: number) => String(n).padStart(2, "0");
const keyOf = (y: number, m: number, d: number) => `${y}-${pad(m)}-${pad(d)}`;
function parse(k: string): [number, number, number] {
  const [y, m, d] = k.split("-").map(Number);
  return [y, m, d];
}
function labelOf(k: string): string {
  const [y, m, d] = parse(k);
  return `${d} ${MONTHS[m - 1]} ${y}`;
}

// Calendar-style date picker replacing long date dropdowns. Works on "YYYY-MM-DD"
// day keys. `markedKeys` shows a dot on days that have data; `maxKey` disables
// future dates.
export function DatePicker({
  value,
  onChange,
  markedKeys = [],
  maxKey,
  todayKey,
}: {
  value: string;
  onChange: (dayKey: string) => void;
  markedKeys?: string[];
  maxKey?: string;
  todayKey?: string;
}) {
  const [open, setOpen] = useState(false);
  const [vy, setVy] = useState(() => parse(value)[0]);
  const [vm, setVm] = useState(() => parse(value)[1]); // 1-12
  const wrapRef = useRef<HTMLDivElement>(null);
  const marked = useMemo(() => new Set(markedKeys), [markedKeys]);

  useEffect(() => {
    const [y, m] = parse(value);
    setVy(y);
    setVm(m);
  }, [value]);

  useEffect(() => {
    if (!open) return;
    function onDoc(e: MouseEvent) {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  const firstDow = new Date(Date.UTC(vy, vm - 1, 1)).getUTCDay();
  const daysInMonth = new Date(Date.UTC(vy, vm, 0)).getUTCDate();
  const cells: (number | null)[] = [];
  for (let i = 0; i < firstDow; i++) cells.push(null);
  for (let d = 1; d <= daysInMonth; d++) cells.push(d);

  function step(delta: number) {
    let m = vm + delta;
    let y = vy;
    if (m < 1) { m = 12; y--; }
    if (m > 12) { m = 1; y++; }
    setVm(m);
    setVy(y);
  }
  function pick(d: number) {
    const k = keyOf(vy, vm, d);
    if (maxKey && k > maxKey) return;
    onChange(k);
    setOpen(false);
  }

  return (
    <div className="dp" ref={wrapRef}>
      <button type="button" className="dp-btn" onClick={() => setOpen((o) => !o)}>
        <span className="dp-ic">📅</span>
        <span className="dp-val">
          {labelOf(value)}
          {todayKey && value === todayKey ? " (hari ini)" : ""}
        </span>
        <span className="dp-caret">▾</span>
      </button>
      {open && (
        <div className="dp-pop">
          <div className="dp-head">
            <button type="button" className="dp-nav" onClick={() => step(-1)} aria-label="Bulan sebelumnya">‹</button>
            <span className="dp-title">{MONTHS[vm - 1]} {vy}</span>
            <button type="button" className="dp-nav" onClick={() => step(1)} aria-label="Bulan berikutnya">›</button>
          </div>
          <div className="dp-grid dp-dow">
            {DOW.map((d) => <span key={d} className="dp-dowc">{d}</span>)}
          </div>
          <div className="dp-grid">
            {cells.map((d, i) => {
              if (d === null) return <span key={i} className="dp-cell empty" />;
              const k = keyOf(vy, vm, d);
              const disabled = !!maxKey && k > maxKey;
              const cls = [
                "dp-cell",
                k === value ? "sel" : "",
                k === todayKey ? "today" : "",
                disabled ? "disabled" : "",
              ].join(" ");
              return (
                <button key={i} type="button" className={cls} disabled={disabled} onClick={() => pick(d)}>
                  {d}
                  {marked.has(k) && <i className="dp-dot" />}
                </button>
              );
            })}
          </div>
          {todayKey && (
            <button
              type="button"
              className="dp-today"
              onClick={() => {
                onChange(todayKey);
                setOpen(false);
              }}
            >
              Hari ini
            </button>
          )}
        </div>
      )}
    </div>
  );
}
