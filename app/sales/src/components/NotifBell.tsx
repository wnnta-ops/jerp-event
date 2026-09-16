"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";

type Item = { dealId: string; nama: string; koor: string; cluster: string; days: number };

export function NotifBell() {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [count, setCount] = useState(0);
  const [items, setItems] = useState<Item[]>([]);
  const [loaded, setLoaded] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch("/sales/api/notifications", { cache: "no-store" });
      if (!res.ok) return;
      const d = await res.json();
      setCount(d.count ?? 0);
      setItems(Array.isArray(d.items) ? d.items : []);
      setLoaded(true);
    } catch {
      /* diamkan — jangan ganggu UI kalau gagal */
    }
  }, []);

  useEffect(() => {
    load();
    const t = setInterval(load, 60_000); // refresh tiap 1 menit
    return () => clearInterval(t);
  }, [load]);

  // tutup saat klik di luar / Escape
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDoc);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  function go(it: Item) {
    setOpen(false);
    router.push(`/pipeline?q=${encodeURIComponent(it.nama)}`);
  }

  return (
    <div ref={box} style={{ position: "relative" }}>
      <button
        onClick={() => {
          setOpen((o) => !o);
          if (!open) load();
        }}
        aria-label="Notifikasi"
        title="Perlu di-follow-up"
        style={{
          position: "relative", border: "1px solid var(--line)", background: "var(--panel)",
          borderRadius: 9, width: 34, height: 34, cursor: "pointer", fontSize: 16, lineHeight: 1,
        }}
      >
        🔔
        {count > 0 && (
          <span
            style={{
              position: "absolute", top: -6, right: -6, minWidth: 18, height: 18, padding: "0 5px",
              borderRadius: 999, background: "var(--red)", color: "#fff", fontSize: 11, fontWeight: 800,
              display: "grid", placeItems: "center", boxShadow: "0 0 0 2px var(--panel)",
            }}
          >
            {count > 99 ? "99+" : count}
          </span>
        )}
      </button>

      {open && (
        <div
          style={{
            position: "absolute", top: 42, right: 0, width: 320, maxWidth: "90vw",
            background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12,
            boxShadow: "0 10px 30px rgba(20,40,40,.18)", zIndex: 50, overflow: "hidden",
          }}
        >
          <div style={{ padding: "11px 14px", borderBottom: "1px solid var(--line)", display: "flex", alignItems: "center", gap: 8 }}>
            <b style={{ color: "var(--ink)", fontSize: 13.5 }}>Perlu di-follow-up</b>
            {count > 0 && (
              <span style={{ background: "var(--red)", color: "#fff", borderRadius: 999, fontSize: 11, fontWeight: 800, padding: "1px 8px" }}>{count}</span>
            )}
          </div>
          <div style={{ maxHeight: 380, overflowY: "auto" }}>
            {!loaded && <div style={{ padding: 16, color: "var(--sub)", fontSize: 13 }}>Memuat…</div>}
            {loaded && items.length === 0 && (
              <div style={{ padding: 18, color: "var(--sub)", fontSize: 13, textAlign: "center" }}>
                Semua customer sudah di-follow-up 🎉
              </div>
            )}
            {items.map((it) => (
              <button
                key={it.dealId}
                onClick={() => go(it)}
                style={{
                  width: "100%", textAlign: "left", border: "none", borderBottom: "1px solid var(--line)",
                  background: "transparent", cursor: "pointer", padding: "10px 14px", display: "flex",
                  alignItems: "center", gap: 10,
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = "var(--bg)")}
                onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}
              >
                <span
                  style={{
                    flex: "none", minWidth: 40, textAlign: "center", background: it.days >= 7 ? "var(--red)" : "var(--main)",
                    color: "#fff", borderRadius: 8, fontSize: 11, fontWeight: 800, padding: "3px 6px",
                  }}
                >
                  {it.days}h
                </span>
                <span style={{ overflow: "hidden" }}>
                  <span style={{ display: "block", fontWeight: 700, color: "var(--ink)", fontSize: 13.5, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{it.nama}</span>
                  <span style={{ display: "block", color: "var(--sub)", fontSize: 11.5 }}>{it.koor} · {it.cluster}</span>
                </span>
              </button>
            ))}
          </div>
          <button
            onClick={() => { setOpen(false); router.push("/pipeline"); }}
            style={{ width: "100%", border: "none", background: "transparent", color: "var(--main)", fontWeight: 700, fontSize: 12.5, padding: "10px 14px", cursor: "pointer" }}
          >
            Buka Customer Pipeline →
          </button>
        </div>
      )}
    </div>
  );
}
