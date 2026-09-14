"use client";

import { useEffect, useMemo, useRef, useState } from "react";

export interface ComboOption {
  value: string;
  label: string;
}

// Typeable filter with autocomplete (closest match first) + a dropdown arrow
// that reveals all options. `value === "all"` means nothing selected (shows the
// `allLabel` as placeholder and as the reset option at the top of the list).
export function Combobox({
  value,
  onChange,
  options,
  allLabel,
  allValue = "all",
  title,
}: {
  value: string;
  onChange: (v: string) => void;
  options: ComboOption[];
  allLabel: string;
  allValue?: string;
  title?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [hl, setHl] = useState(0);
  const wrapRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const selLabel = value === allValue ? "" : options.find((o) => o.value === value)?.label ?? "";

  // options shown while open: rank by closeness (startsWith < includes), and
  // include the "all" reset row only when there's no active query.
  const list = useMemo(() => {
    const q = query.trim().toLowerCase();
    const base = options
      .map((o) => ({ o, i: q ? o.label.toLowerCase().indexOf(q) : 0 }))
      .filter((x) => x.i >= 0)
      .sort((a, b) => a.i - b.i || a.o.label.localeCompare(b.o.label))
      .map((x) => x.o);
    return q ? base : [{ value: allValue, label: allLabel }, ...base];
  }, [query, options, allLabel, allValue]);

  useEffect(() => {
    if (!open) return;
    function onDoc(e: MouseEvent) {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  function openMenu(clear = true) {
    if (clear) setQuery("");
    setHl(0);
    setOpen(true);
  }
  function pick(o: ComboOption) {
    onChange(o.value);
    setQuery("");
    setOpen(false);
    inputRef.current?.blur();
  }
  function onKey(e: React.KeyboardEvent) {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      if (!open) openMenu();
      else setHl((h) => Math.min(h + 1, list.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setHl((h) => Math.max(h - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (open && list[hl]) pick(list[hl]);
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  }

  return (
    <div className="cbx" ref={wrapRef} title={title}>
      <input
        ref={inputRef}
        className="cbx-input"
        value={open ? query : selLabel}
        placeholder={allLabel}
        onFocus={() => openMenu()}
        onChange={(e) => {
          setQuery(e.target.value);
          setHl(0);
          if (!open) setOpen(true);
        }}
        onKeyDown={onKey}
      />
      {value !== allValue && !open && (
        <button
          type="button"
          className="cbx-clear"
          title="Reset"
          onClick={() => onChange(allValue)}
        >
          ✕
        </button>
      )}
      <button
        type="button"
        className="cbx-arrow"
        tabIndex={-1}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => (open ? setOpen(false) : (inputRef.current?.focus(), openMenu()))}
        aria-label="Buka pilihan"
      >
        ▾
      </button>
      {open && (
        <div className="cbx-menu">
          {list.length === 0 && <div className="cbx-empty">Tidak ada yang cocok</div>}
          {list.map((o, i) => (
            <div
              key={o.value}
              className={`cbx-opt ${i === hl ? "hl" : ""} ${o.value === value ? "sel" : ""}`}
              onMouseEnter={() => setHl(i)}
              onMouseDown={(e) => {
                e.preventDefault();
                pick(o);
              }}
            >
              {o.label}
              {o.value === value && <span className="cbx-check">✓</span>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
