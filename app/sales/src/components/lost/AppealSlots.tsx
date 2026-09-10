"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Role } from "@prisma/client";
import { toast } from "@/components/Toast";

export interface LostCustomer {
  nama: string;
  cluster: string;
  type: string;
  sales: string;
  bendera: string;
  stage: string;
  when: string;
  category: "BATAL" | "MANDEK";
}

export interface ReasonSlot {
  alasanKey: string;
  label: string;
  n: number;
  teamStrategy: string; // stored value, or the default placeholder
  teamIsDefault: boolean;
  aiText: string | null;
  aiGeneratedAt: string | null; // label
  customers: LostCustomer[];
  isAll?: boolean;
}

interface AiParsed {
  pola: string;
  strategi: string[];
  prioritas: { label: string; alasan: string };
}

// aiText is stored as JSON ({pola, strategi[], prioritas}); tolerate legacy
// plain-text values by showing them as the diagnosa.
function parseAi(raw: string | null): AiParsed | null {
  if (!raw) return null;
  try {
    const j = JSON.parse(raw);
    if (j && typeof j === "object" && ("pola" in j || "strategi" in j)) {
      return {
        pola: String(j.pola ?? ""),
        strategi: Array.isArray(j.strategi) ? j.strategi.map(String) : [],
        prioritas: {
          label: String(j.prioritas?.label ?? "—"),
          alasan: String(j.prioritas?.alasan ?? ""),
        },
      };
    }
  } catch {
    /* legacy plain text */
  }
  return { pola: raw, strategi: [], prioritas: { label: "—", alasan: "" } };
}

function prioClass(label: string): string {
  const l = label.toLowerCase();
  if (l.startsWith("recover")) return "b-green";
  if (l.startsWith("lost")) return "b-red";
  if (l.startsWith("rank")) return "b-blue";
  return "b-amber";
}

function TeamSlot({
  r,
  canEdit,
}: {
  r: ReasonSlot;
  canEdit: boolean;
}) {
  const router = useRouter();
  const [editing, setEditing] = useState(false);
  const [text, setText] = useState(r.teamStrategy);
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    const res = await fetch("/api/lost/strategy", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ alasanKey: r.alasanKey, text }),
    });
    setBusy(false);
    if (res.ok) {
      toast("✓ Strategi tim disimpan");
      setEditing(false);
      router.refresh();
    } else {
      const j = await res.json().catch(() => ({}));
      toast(j.error ?? "Gagal");
    }
  }

  return (
    <div className="slot">
      <div className="slot-h">
        <span>🧑‍💼 Strategi Tim</span>
        {canEdit && !editing && (
          <button className="btn ghost sm" onClick={() => setEditing(true)}>
            ✏️ Edit
          </button>
        )}
      </div>
      {editing ? (
        <>
          <textarea rows={4} value={text} onChange={(e) => setText(e.target.value)} />
          <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
            <button className="btn sm" onClick={save} disabled={busy}>
              Simpan
            </button>
            <button
              className="btn ghost sm"
              onClick={() => {
                setText(r.teamStrategy);
                setEditing(false);
              }}
            >
              Batal
            </button>
          </div>
        </>
      ) : (
        <p className="slot-body">
          {r.teamStrategy}
          {r.teamIsDefault && <span className="pill" style={{ marginLeft: 6 }}>default</span>}
        </p>
      )}
    </div>
  );
}

function AiSlot({ r, canGenerate }: { r: ReasonSlot; canGenerate: boolean }) {
  const [busy, setBusy] = useState(false);
  const [text, setText] = useState(r.aiText);
  const [ts, setTs] = useState(r.aiGeneratedAt);

  async function generate() {
    setBusy(true);
    const res = await fetch("/api/lost/ai", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ alasanKey: r.alasanKey }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      setText(j.aiText);
      setTs(new Date(j.aiGeneratedAt).toLocaleString("id-ID"));
      toast("✓ Analisa AI dibuat");
    } else {
      toast(j.error ?? "Gagal generate");
    }
  }

  const ai = parseAi(text);

  return (
    <div className="slot slot-ai">
      <div className="slot-h">
        <span>🤖 Analisa AI</span>
        {canGenerate && (
          <button className="btn ghost sm" onClick={generate} disabled={busy}>
            {busy ? "Menganalisa…" : ai ? "↻ Refresh" : "✨ Generate"}
          </button>
        )}
      </div>
      {ai ? (
        <>
          <div className="ai-block">
            <span className="ai-lab">🔍 Diagnosa</span>
            <p className="ai-text">{ai.pola}</p>
          </div>
          {ai.strategi.length > 0 && (
            <div className="ai-block">
              <span className="ai-lab">🎯 Strategi appeal</span>
              <ul className="ai-list">
                {ai.strategi.map((s, i) => (
                  <li key={i}>{s}</li>
                ))}
              </ul>
            </div>
          )}
          {ai.prioritas.label !== "—" && (
            <div className="ai-block">
              <span className="ai-lab">⚑ Prioritas</span>
              <p className="ai-text">
                <span className={`badge ${prioClass(ai.prioritas.label)}`}>{ai.prioritas.label}</span>{" "}
                {ai.prioritas.alasan}
              </p>
            </div>
          )}
          {ts && <div className="slot-ts">Dibuat AI · {ts} · dari data agregat (anonim)</div>}
        </>
      ) : (
        <p className="slot-body" style={{ color: "var(--sub)" }}>
          {canGenerate
            ? 'Belum ada. Klik "Generate" — AI baca pola data (anonim) & kasih diagnosa, strategi, prioritas.'
            : "Belum ada analisa AI."}
        </p>
      )}
    </div>
  );
}

function ReasonCard({ r, canEdit, canGenerate }: { r: ReasonSlot; canEdit: boolean; canGenerate: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <div className={`appeal-card ${r.isAll ? "appeal-all" : ""}`}>
      <div className="appeal-h">
        {r.isAll ? "📊 " : ""}
        {r.label}{" "}
        {r.isAll ? (
          <span className="count-btn" style={{ cursor: "default" }}>
            👥 {r.n} total customer
          </span>
        ) : (
          <button className="count-btn" onClick={() => setOpen((o) => !o)}>
            👥 {r.n} customer {open ? "▴" : "▾"}
          </button>
        )}
      </div>
      {open && !r.isAll && (
        <div className="cust-list">
          <table>
            <thead>
              <tr>
                <th>Customer</th>
                <th>Cluster / Type</th>
                <th>Sales</th>
                <th>Bendera</th>
                <th>Tahap terakhir</th>
                <th>Terakhir gerak</th>
              </tr>
            </thead>
            <tbody>
              {r.customers.map((c, i) => (
                <tr key={i}>
                  <td>
                    <b>{c.nama}</b>{" "}
                    {c.category === "MANDEK" ? (
                      <span className="badge b-amber">pasif</span>
                    ) : (
                      <span className="badge b-red">batal</span>
                    )}
                  </td>
                  <td>
                    {c.cluster} · {c.type}
                  </td>
                  <td>{c.sales}</td>
                  <td>{c.bendera}</td>
                  <td>{c.stage}</td>
                  <td>{c.when}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div className="appeal-slots">
        <TeamSlot r={r} canEdit={canEdit} />
        <AiSlot r={r} canGenerate={canGenerate} />
      </div>
    </div>
  );
}

export function AppealSlots({ reasons, role }: { reasons: ReasonSlot[]; role: Role }) {
  const canEdit = role === "MANAGEMENT";
  const canGenerate = role === "SALES" || role === "MANAGEMENT";

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
      {reasons.map((r) => (
        <ReasonCard key={r.alasanKey} r={r} canEdit={canEdit} canGenerate={canGenerate} />
      ))}
    </div>
  );
}
