"use client";

import { logoutAction } from "@/app/login/actions";
import { Avatar } from "./ui";

export function UserMenu({ name, email, initials }: { name: string; email: string; initials: string }) {
  return (
    <div className="flex items-center gap-2.5">
      {/* Kembali ke portal J-ERP — <a> polos (bukan Link) agar TIDAK diberi basePath /legal. */}
      <a
        href="/dashboard"
        title="Kembali ke Dashboard J-ERP"
        className="inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium"
        style={{ color: "var(--color-text-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}
      >
        <i className="ti ti-arrow-left" style={{ fontSize: 14 }} />
        <span className="hidden md:inline">Dashboard</span>
      </a>
      <div className="flex items-center gap-2">
        <Avatar initials={initials} size={26} />
        <div className="hidden leading-tight sm:block">
          <div className="text-[12px] font-medium">{name}</div>
          <div className="text-[10px]" style={{ color: "var(--color-text-tertiary)" }}>{email}</div>
        </div>
      </div>
      <form action={logoutAction}>
        <button type="submit" title="Keluar" aria-label="Keluar" className="inline-flex h-8 w-8 items-center justify-center rounded-md" style={{ color: "var(--color-text-secondary)", border: "0.5px solid var(--color-border-tertiary)" }}>
          <i className="ti ti-logout" style={{ fontSize: 16 }} />
        </button>
      </form>
    </div>
  );
}
