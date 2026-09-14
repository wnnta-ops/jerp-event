"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { FormOptions } from "@/lib/options";
import { WalkInForm, type WalkInEdit } from "@/components/forms/WalkInForm";
import { Modal } from "@/components/Modal";
import { toast } from "@/components/Toast";

export function WalkInRowActions({ deal, options }: { deal: WalkInEdit; options: FormOptions }) {
  const router = useRouter();
  const [edit, setEdit] = useState(false);
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);

  async function del() {
    setBusy(true);
    const res = await fetch(`/api/deals/${deal.id}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) {
      setConfirm(false);
      toast("✓ Walk-In dihapus");
      router.refresh();
    } else {
      const j = await res.json().catch(() => ({}));
      toast(j.error ?? "Gagal menghapus");
    }
  }

  return (
    <div style={{ display: "inline-flex", gap: 6, justifyContent: "flex-end" }}>
      <button className="btn ghost sm" onClick={() => setEdit(true)}>✏️ Edit</button>
      <button className="btn ghost sm danger" onClick={() => setConfirm(true)}>🗑️ Hapus</button>

      {edit && <WalkInForm options={options} open={edit} onClose={() => setEdit(false)} deal={deal} />}

      <Modal
        title="Hapus Walk-In?"
        open={confirm}
        onClose={() => setConfirm(false)}
        footer={
          <>
            <button className="btn ghost" onClick={() => setConfirm(false)}>Batal</button>
            <button className="btn danger" onClick={del} disabled={busy}>
              {busy ? "Menghapus…" : "Hapus"}
            </button>
          </>
        }
      >
        <p style={{ fontSize: 14 }}>
          Yakin hapus Walk-In <b>{deal.nama}</b>? Data customer &amp; seluruh riwayat event-nya akan
          dihapus permanen. Aksi ini tidak bisa dibatalkan.
        </p>
      </Modal>
    </div>
  );
}
