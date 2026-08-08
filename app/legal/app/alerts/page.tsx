import Link from "next/link";
import { format } from "date-fns";
import { id as idLocale } from "date-fns/locale";
import { getActingUser } from "@/lib/current-user";
import { loadAlertsFor, type AlertSeverity } from "@/lib/alerts";
import { ROLE_LABELS, type Role } from "@/lib/enums";

export const dynamic = "force-dynamic";

const ICON: Record<AlertSeverity, string> = { danger: "ti-alert-triangle", warning: "ti-clock", info: "ti-bell" };
const TONE: Record<AlertSeverity, { bg: string; fg: string; line: string }> = {
  danger: { bg: "var(--color-background-danger)", fg: "var(--color-text-danger)", line: "var(--color-border-danger)" },
  warning: { bg: "var(--color-background-warning)", fg: "var(--color-text-warning)", line: "var(--color-border-warning)" },
  info: { bg: "var(--color-background-info)", fg: "var(--color-text-info)", line: "var(--color-border-info)" },
};

export default async function AlertsPage() {
  const acting = await getActingUser();
  const alerts = await loadAlertsFor(acting);

  return (
    <div className="mx-auto max-w-[860px]">
      <div className="mb-1 flex items-center gap-2">
        <h1 className="text-[18px] font-medium">Pemberitahuan</h1>
        <span className="rounded-md px-2 py-0.5 text-[11px] font-medium" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-secondary)" }}>{alerts.length}</span>
      </div>
      <p className="mb-4 text-xs" style={{ color: "var(--color-text-secondary)" }}>
        Untuk {acting?.name} · {ROLE_LABELS[(acting?.role ?? "") as Role] ?? acting?.role}. Pengingat perpanjangan di 90/60/30 hari ke PIC; item terlambat dieskalasi ke pimpinan.
      </p>

      {alerts.length === 0 ? (
        <div className="rounded-lg px-5 py-12 text-center" style={{ background: "var(--color-background-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
          <i className="ti ti-bell-check" style={{ fontSize: 28, color: "var(--color-text-success)" }} />
          <p className="mt-2 text-sm font-medium">Tidak ada pemberitahuan</p>
          <p className="mt-1 text-xs" style={{ color: "var(--color-text-tertiary)" }}>Tidak ada tenggat atau permintaan yang butuh perhatian Anda.</p>
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          {alerts.map((a) => {
            const tone = TONE[a.severity];
            return (
              <Link key={a.id} href={a.href} className="flex items-start gap-3 rounded-lg px-3.5 py-3" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)", borderLeft: `3px solid ${tone.line}` }}>
                <span className="mt-0.5 flex h-7 w-7 flex-none items-center justify-center rounded-full" style={{ background: tone.bg, color: tone.fg }}>
                  <i className={`ti ${ICON[a.severity]}`} style={{ fontSize: 15 }} />
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="text-[13px] font-medium">{a.title}</span>
                    {a.escalation && <span className="rounded px-1.5 py-px text-[10px] font-medium" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>Eskalasi</span>}
                  </div>
                  <div className="mt-0.5 text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>{a.detail}</div>
                </div>
                <span className="flex-none text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>{format(new Date(a.date), "d MMM", { locale: idLocale })}</span>
              </Link>
            );
          })}
        </div>
      )}

      <div className="mt-5 flex items-start gap-2 rounded-md px-3 py-2.5 text-[11px]" style={{ background: "var(--color-background-secondary)", color: "var(--color-text-tertiary)" }}>
        <i className="ti ti-info-circle" style={{ marginTop: 1, color: "var(--color-text-info)" }} />
        <span>v1 mengirim in-app (daftar ini). Email/push di-stub di balik satu antarmuka (<code>lib/notify.ts</code>) — tinggal disambung ke notifikasi portal nanti.</span>
      </div>
    </div>
  );
}
