import Link from "next/link";
import { getActingUser } from "@/lib/current-user";
import { loadAlertsFor } from "@/lib/alerts";
import { initialsOf } from "@/lib/utils";
import { UserMenu } from "./user-menu";
import { NavLinks } from "./nav-links";

export async function AppHeader() {
  const acting = await getActingUser();
  if (!acting) return null; // unauthenticated → login/register pages have no app chrome
  const alerts = await loadAlertsFor(acting);
  const alertCount = alerts.length;
  const hasDanger = alerts.some((a) => a.severity === "danger");

  return (
    <header
      className="sticky top-0 z-10 py-3"
      style={{
        background: "var(--color-background-primary)",
        borderBottom: "0.5px solid var(--color-border-tertiary)",
      }}
    >
      <div className="mx-auto flex w-full max-w-[1320px] items-center justify-between gap-3 px-5 sm:px-8 lg:px-10">
      <div className="flex items-center gap-3">
        <Link href="/" className="flex items-center gap-2">
          <span
            className="flex h-7 w-7 items-center justify-center rounded-md text-sm font-semibold"
            style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}
          >
            <i className="ti ti-scale" />
          </span>
          <span className="text-sm font-medium" style={{ color: "var(--color-text-primary)" }}>
            Legal · MUP
          </span>
        </Link>
        <span className="mx-1 hidden h-4 w-px sm:inline-block" style={{ background: "var(--color-border-tertiary)" }} />
        <NavLinks />
      </div>
      <div className="flex items-center gap-3">
        <Link href="/alerts" aria-label="Pemberitahuan" className="relative inline-flex h-8 w-8 items-center justify-center rounded-md" style={{ color: "var(--color-text-secondary)" }} title="Pemberitahuan">
          <i className="ti ti-bell" style={{ fontSize: 17 }} />
          {alertCount > 0 && (
            <span
              className="absolute -right-0.5 -top-0.5 flex min-w-[16px] items-center justify-center rounded-full px-1 text-[9px] font-semibold"
              style={{
                height: 16,
                background: hasDanger ? "var(--color-text-danger)" : "var(--color-background-info)",
                color: hasDanger ? "#fff" : "var(--color-text-info)",
                border: "1px solid var(--color-background-primary)",
              }}
            >
              {alertCount}
            </span>
          )}
        </Link>
        <UserMenu name={acting.name} email={acting.email} initials={acting.initials || initialsOf(acting.name)} />
      </div>
      </div>
    </header>
  );
}
