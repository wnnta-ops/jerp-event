"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import type { Role, Stage } from "@prisma/client";
import { Modal } from "@/components/Modal";
import { toast } from "@/components/Toast";
import { StageBadge } from "@/components/badges";
import { STAGES, STAGE_INDEX, SALES_MAX_INDEX, BATAL_ALASAN_LABEL, BATAL_KATEGORI_LABEL } from "@/lib/constants";
import { canAdvanceStage } from "@/lib/rbac";

export interface CollDeal {
  id: string;
  nama: string;
  stage: Stage;
  unit: string | null;
  cluster: string;
  type: string;
  sales: string;
  bank: string | null;
  catatan: string | null;
}

const SUB: { label: string; stage: Stage }[] = [
  { label: "UTJ", stage: "UTJ" },
  { label: "SPR", stage: "SPR" },
  { label: "ACC Bank", stage: "ACC" },
  { label: "Akad", stage: "AKAD" },
  { label: "Realisasi", stage: "REALISASI" },
];

export function CollectionClient({ deals, role }: { deals: CollDeal[]; role: Role }) {
  const router = useRouter();
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);
  const [batalDeal, setBatalDeal] = useState<CollDeal | null>(null);
  const [batal, setBatal] = useState({
    kategori: "UTJ",
    alasan: "REJECT_BANK",
    refundStatus: "NONE",
    refundAmount: "",
  });

  // Only the Collection team may change collection status (ACC → Akad → Realisasi / Batal).
  const canEdit = role === "COLLECTION";

  const filtered = useMemo(() => {
    if (!q.trim()) return deals;
    const s = q.toLowerCase();
    return deals.filter((d) =>
      [d.nama, d.unit, d.sales, d.bank, d.cluster, d.type].join(" ").toLowerCase().includes(s),
    );
  }, [q, deals]);

  async function advance(d: CollDeal) {
    setBusy(true);
    const res = await fetch(`/api/deals/${d.id}/advance`, { method: "POST" });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ " + d.nama + " → " + STAGES[STAGE_INDEX[d.stage] + 1].label);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  async function submitBatal() {
    setBusy(true);
    const res = await fetch(`/api/deals/${batalDeal!.id}/batal`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        kategori: batal.kategori,
        alasan: batal.alasan,
        refundStatus: batal.refundStatus,
        refundAmount: batal.refundAmount ? Number(batal.refundAmount) : null,
      }),
    });
    setBusy(false);
    const j = await res.json().catch(() => ({}));
    if (res.ok) {
      toast("✓ " + batalDeal!.nama + " dibatalkan — unit kembali Open Stock");
      setBatalDeal(null);
      router.refresh();
    } else toast(j.error ?? "Gagal");
  }

  return (
    <>
      <div className="actions">
        <input
          className="search"
          placeholder="🔎 Cari nama / unit / sales / bank"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <span className="rolehint">{filtered.length} unit</span>
      </div>

      {filtered.length === 0 && (
        <div className="card">
          <div className="pad" style={{ color: "var(--sub)" }}>
            Belum ada UTJ untuk diproses.
          </div>
        </div>
      )}

      {filtered.map((d) => {
        const idx = STAGE_INDEX[d.stage];
        const canAdv = canEdit && canAdvanceStage(role, d.stage);
        return (
          <div className="card" style={{ marginBottom: 14 }} key={d.id}>
            <div className="pad">
              <div style={{ display: "flex", justifyContent: "space-between", flexWrap: "wrap", gap: 8, marginBottom: 12 }}>
                <div>
                  <b>{d.nama}</b> <span className="pill">{d.unit ?? "-"}</span>
                  <div className="mt">
                    {d.cluster} · {d.type} · Sales: {d.sales} · Bank: {d.bank ?? "-"}
                  </div>
                </div>
                <div style={{ textAlign: "right" }}>
                  <div className="mt">Tahap</div>
                  <StageBadge stage={d.stage} />
                </div>
              </div>
              <div className="stepper">
                {SUB.map((s, si) => {
                  const sIdx = STAGE_INDEX[s.stage];
                  const cls = sIdx < idx ? "done" : sIdx === idx ? "cur" : "";
                  return (
                    <div className={`s ${cls}`} key={s.stage}>
                      <div className="dot">{sIdx < idx ? "✓" : si + 1}</div>
                      <div className="t">{s.label}</div>
                    </div>
                  );
                })}
              </div>
              <div style={{ marginTop: 12, display: "flex", justifyContent: "space-between", alignItems: "center", flexWrap: "wrap", gap: 8 }}>
                <span className="mt">📝 {d.catatan ?? "-"}</span>
                <div style={{ display: "flex", gap: 8 }}>
                  {idx >= 7 ? (
                    <span className="badge b-green">Realisasi</span>
                  ) : idx < SALES_MAX_INDEX ? (
                    <span className="pill">Nunggu SPR dari Sales</span>
                  ) : canAdv ? (
                    <>
                      <button className="btn sm" onClick={() => advance(d)} disabled={busy}>
                        Update → {STAGES[idx + 1].label}
                      </button>
                      <button className="btn ghost sm" onClick={() => setBatalDeal(d)} disabled={busy}>
                        Batal
                      </button>
                    </>
                  ) : role === "COLLECTION" ? (
                    <span className="pill">🔒 view only</span>
                  ) : (
                    <span className="pill">🔒 Hanya tim Collection yang bisa update</span>
                  )}
                </div>
              </div>
            </div>
          </div>
        );
      })}

      <Modal
        title={batalDeal ? `Batal — ${batalDeal.nama}` : ""}
        open={!!batalDeal}
        onClose={() => setBatalDeal(null)}
        footer={
          <>
            <button className="btn ghost" onClick={() => setBatalDeal(null)}>
              Tutup
            </button>
            <button className="btn" onClick={submitBatal} disabled={busy}>
              Simpan Batal
            </button>
          </>
        }
      >
        <div className="frow">
          <div className="field">
            <label>Kategori</label>
            <select value={batal.kategori} onChange={(e) => setBatal({ ...batal, kategori: e.target.value })}>
              {Object.entries(BATAL_KATEGORI_LABEL).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>Alasan</label>
            <select value={batal.alasan} onChange={(e) => setBatal({ ...batal, alasan: e.target.value })}>
              {Object.entries(BATAL_ALASAN_LABEL).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </div>
        </div>
        <div className="frow">
          <div className="field">
            <label>Status refund</label>
            <select value={batal.refundStatus} onChange={(e) => setBatal({ ...batal, refundStatus: e.target.value })}>
              <option value="NONE">Tidak ada</option>
              <option value="PENDING">Pending</option>
              <option value="DONE">Selesai</option>
            </select>
          </div>
          <div className="field">
            <label>Nominal refund (opsional)</label>
            <input
              type="number"
              value={batal.refundAmount}
              onChange={(e) => setBatal({ ...batal, refundAmount: e.target.value })}
              placeholder="cth: 5000000"
            />
          </div>
        </div>
        <p className="rolehint">Batal → unit kembali Open Stock &amp; masuk rekap batal/refund.</p>
      </Modal>
    </>
  );
}
