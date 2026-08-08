"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { format } from "date-fns";
import { id as idLocale } from "date-fns/locale";
import { notifyApprover } from "@/app/requests/actions";
import { rankByDeadline, relativeDeadlineLabel } from "@/lib/ranking";
import { ACCESS_TYPE_LABELS, REQUEST_STATUS_LABELS, type AccessType, type RequestStatus } from "@/lib/access";
import { ROLE_LABELS, type Role } from "@/lib/enums";
import { NewRequestModal } from "./new-request-modal";

export type QueueItem = {
  id: string; code: string; docTitle: string; requesterName: string; dept: string;
  accessType: string; status: string; neededBy: string; createdAt: string;
  currentStep: number; totalSteps: number; currentRole: string | null;
  currentApproverName: string | null; currentNotifiedAt: string | null; waitingOnMe: boolean;
};

function waitingText(createdAt: string, now: Date): string {
  const ms = now.getTime() - new Date(createdAt).getTime();
  const days = Math.floor(ms / 86_400_000);
  if (days >= 1) return `${days} hari menunggu`;
  const hours = Math.max(1, Math.floor(ms / 3_600_000));
  return `${hours} jam menunggu`;
}

function MiniChain({ current, total }: { current: number; total: number }) {
  return (
    <span className="inline-flex items-center">
      {Array.from({ length: total }, (_, i) => (
        <span key={i} className="inline-flex items-center">
          <span
            style={{
              width: 14, height: 14, borderRadius: "50%", display: "inline-flex", alignItems: "center", justifyContent: "center", flex: "none", fontSize: 8,
              ...(i < current
                ? { background: "var(--color-background-success)", color: "var(--color-text-success)" }
                : i === current
                  ? { background: "var(--color-text-info)" }
                  : { border: "1px solid var(--color-border-tertiary)" }),
            }}
          >
            {i < current && <i className="ti ti-check" />}
          </span>
          {i < total - 1 && <span style={{ width: 12, height: 1, background: i < current ? "var(--color-border-success)" : "var(--color-border-tertiary)" }} />}
        </span>
      ))}
    </span>
  );
}

export function RequestsQueue({
  items, hasActingRole, canCreate = false,
}: {
  items: QueueItem[];
  hasActingRole: boolean;
  canCreate?: boolean;
}) {
  const router = useRouter();
  const [now] = useState(() => new Date());
  const [pending, startTransition] = useTransition();
  const [mineOnly, setMineOnly] = useState(false);
  const [notifiedIds, setNotifiedIds] = useState<Set<string>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);

  const pendingItems = items.filter((i) => i.status === "pending");
  // Physical checkouts that passed Head of Legal and now sit in the external app.
  const externalItems = items.filter((i) => i.status === "pending_external");
  // Resolved: approved (document already released) or rejected — not the external wait.
  const resolved = items.filter((i) => i.status !== "pending" && i.status !== "pending_external");

  const ranked = useMemo(() => {
    const filtered = mineOnly ? pendingItems.filter((i) => i.waitingOnMe) : pendingItems;
    return rankByDeadline(filtered, (i) => i.neededBy, now, (i) => i.createdAt);
  }, [pendingItems, mineOnly, now]);

  const waitingOnMeCount = pendingItems.filter((i) => i.waitingOnMe).length;

  function notify(id: string) {
    startTransition(async () => {
      await notifyApprover(id);
      setNotifiedIds((s) => new Set(s).add(id));
      router.refresh();
    });
  }

  return (
    <div>
      {/* Sticky header — title + create + filter stay locked while the list scrolls */}
      <div
        style={{
          position: "sticky", top: 56, zIndex: 5,
          background: "var(--color-background-tertiary)",
          borderBottom: "0.5px solid var(--color-border-tertiary)",
          paddingTop: 8, paddingBottom: 10,
        }}
      >
      <div className="mb-1 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h1 className="text-[18px] font-medium">Permintaan akses dokumen</h1>
          <span className="rounded-md px-2 py-0.5 text-[11px] font-medium" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>{pendingItems.length}</span>
        </div>
        {canCreate && (
          <button
            onClick={() => setCreateOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-medium"
            style={{ border: "0.5px solid var(--color-border-secondary)", background: "var(--color-background-info)", color: "var(--color-text-info)" }}
          >
            <i className="ti ti-plus" /> Buat permintaan
          </button>
        )}
      </div>
      <p className="mb-4 text-xs" style={{ color: "var(--color-text-secondary)" }}>
        Urutan: Terlambat → Hari ini → Mengikuti tanggal terdekat
      </p>

      {/* "waiting on me" filter — appears once it matters */}
      {(pendingItems.length > 4 || waitingOnMeCount > 0) && hasActingRole && (
        <div className="mb-3 flex items-center gap-2">
          <button onClick={() => setMineOnly((v) => !v)} className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-tertiary)", background: mineOnly ? "var(--color-background-info)" : "var(--color-background-primary)", color: mineOnly ? "var(--color-text-info)" : "var(--color-text-secondary)" }}>
            <i className="ti ti-user-check" /> Menunggu saya ({waitingOnMeCount})
          </button>
        </div>
      )}
      </div>

      {/* Empty (all caught up) */}
      {ranked.length === 0 ? (
        <div className="rounded-lg px-5 py-12 text-center" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
          <i className="ti ti-checks" style={{ fontSize: 28, color: "var(--color-text-success)" }} />
          <p className="mt-2 text-sm font-medium">Semua selesai</p>
          <p className="mt-1 text-xs" style={{ color: "var(--color-text-tertiary)" }}>{mineOnly ? "Tidak ada yang menunggu Anda." : "Tidak ada permintaan tertunda."}</p>
        </div>
      ) : (
        <div className="flex flex-col gap-2.5">
          {ranked.map((r) => {
            const rel = relativeDeadlineLabel(r.neededBy, now);
            const notified = notifiedIds.has(r.id) || !!r.currentNotifiedAt;
            return (
              <div key={r.id} className="flex items-start gap-3 rounded-lg px-3.5 py-3" style={{ background: "var(--color-background-primary)", border: `0.5px solid ${r.waitingOnMe ? "var(--color-border-info)" : "var(--color-border-tertiary)"}` }}>
                <button onClick={() => router.push(`/requests/${r.id}`)} className="flex min-w-0 flex-1 flex-col text-left">
                  <div className="flex items-center gap-2">
                    <span className="text-[13px] font-medium">{r.docTitle}</span>
                    {r.waitingOnMe && <span className="rounded px-1.5 py-px text-[10px] font-medium" style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}>menunggu Anda</span>}
                  </div>
                  <div className="mb-2 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{r.dept} · {r.requesterName} · {ACCESS_TYPE_LABELS[r.accessType as AccessType] ?? r.accessType} · #{r.code}</div>
                  <div className="flex flex-wrap items-center gap-2.5">
                    <MiniChain current={r.currentStep} total={r.totalSteps} />
                    <span className="text-[11px]" style={{ color: "var(--color-text-secondary)" }}>
                      Macet di <span className="font-medium" style={{ color: "var(--color-text-primary)" }}>{ROLE_LABELS[(r.currentRole ?? "") as Role]?.toLowerCase() ?? "-"}</span> · {r.currentStep + 1} dari {r.totalSteps} · {waitingText(r.createdAt, now)}
                    </span>
                  </div>
                </button>
                <div className="flex flex-none flex-col items-end gap-2">
                  <span className="rounded-md px-2 py-0.5 text-[11px] font-medium" style={{ background: rel.overdue ? "var(--color-background-warning)" : "var(--color-background-secondary)", color: rel.overdue ? "var(--color-text-warning)" : "var(--color-text-secondary)" }}>
                    butuh {format(new Date(r.neededBy), "d MMM", { locale: idLocale })}
                  </span>
                  {notified ? (
                    <span className="inline-flex items-center gap-1.5 text-[12px]" style={{ color: "var(--color-text-info)" }}><i className="ti ti-bell-ringing" /> Dinotifikasi</span>
                  ) : (
                    <button disabled={pending} onClick={() => notify(r.id)} className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px] font-medium" style={{ border: "0.5px solid var(--color-border-info)", color: "var(--color-text-info)" }}>
                      <i className="ti ti-bell" /> Notify {(r.currentApproverName ?? "").split(" ")[0]}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Waiting in the external approval app (GM → Direktur → Owner) */}
      {externalItems.length > 0 && (
        <div className="mt-6">
          <div className="mb-2 flex items-center gap-1.5 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
            <i className="ti ti-external-link" style={{ color: "var(--color-text-info)" }} /> Menunggu approval eksternal ({externalItems.length})
          </div>
          <div className="flex flex-col gap-1.5">
            {externalItems.map((r) => (
              <button key={r.id} onClick={() => router.push(`/requests/${r.id}`)} className="flex items-center gap-3 rounded-md px-3 py-2 text-left" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                <span className="min-w-0 flex-1 truncate text-[12px]">{r.docTitle} <span style={{ color: "var(--color-text-tertiary)" }}>· {r.dept} · #{r.code}</span></span>
                <span className="flex-none text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>GM → Direktur → Owner</span>
                <span className="flex-none rounded-md px-2 py-0.5 text-[11px] font-medium" style={{ background: "var(--color-background-warning)", color: "var(--color-text-warning)" }}>Eksternal</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Resolved history */}
      {resolved.length > 0 && (
        <div className="mt-6">
          <div className="mb-2 text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Selesai ({resolved.length})</div>
          <div className="flex flex-col gap-1.5">
            {resolved.map((r) => (
              <button key={r.id} onClick={() => router.push(`/requests/${r.id}`)} className="flex items-center gap-3 rounded-md px-3 py-2 text-left" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
                <span className="min-w-0 flex-1 truncate text-[12px]">{r.docTitle} <span style={{ color: "var(--color-text-tertiary)" }}>· {r.dept} · #{r.code}</span></span>
                <span className="rounded-md px-2 py-0.5 text-[11px] font-medium" style={{ background: r.status !== "rejected" ? "var(--color-background-success)" : "var(--color-background-danger)", color: r.status !== "rejected" ? "var(--color-text-success)" : "var(--color-text-danger)" }}>{REQUEST_STATUS_LABELS[r.status as RequestStatus]}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      <NewRequestModal open={createOpen} onClose={() => setCreateOpen(false)} />
    </div>
  );
}
