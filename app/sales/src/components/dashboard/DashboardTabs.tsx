"use client";

import { useState, type ReactNode } from "react";

// Two-page dashboard: "Ringkasan" (current) + "Executive Summary" (new, experimental).
// Server-rendered content is passed in as nodes so each page keeps its own data.
export function DashboardTabs({ ringkasan, exsum }: { ringkasan: ReactNode; exsum: ReactNode }) {
  const [tab, setTab] = useState<"now" | "new">("now");
  return (
    <>
      <div className="dtabs no-print">
        <button className={`dtab ${tab === "now" ? "on" : ""}`} onClick={() => setTab("now")}>
          📊 Ringkasan
        </button>
        <button className={`dtab ${tab === "new" ? "on" : ""}`} onClick={() => setTab("new")}>
          📈 Executive Summary <span className="dtab-new">BARU</span>
        </button>
      </div>
      <div hidden={tab !== "now"}>{ringkasan}</div>
      <div hidden={tab !== "new"}>{exsum}</div>
    </>
  );
}
