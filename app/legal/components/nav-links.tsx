"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const LINKS = [
  { href: "/", label: "Direktori", icon: "ti-folder" },
  { href: "/timeline", label: "Timeline", icon: "ti-calendar-event" },
  { href: "/requests", label: "Permintaan", icon: "ti-key" },
  { href: "/templates", label: "Draft Dokumen", icon: "ti-file-text" },
  { href: "/sheet", label: "Lembar Data", icon: "ti-table" },
];

// Menu khusus pengelola user — hanya tampil bila boleh (sinkron canManageUsers).
const ADMIN_LINK = { href: "/users", label: "User", icon: "ti-users" };

export function NavLinks({ canManageUsers = false }: { canManageUsers?: boolean }) {
  const pathname = usePathname();
  const links = canManageUsers ? [...LINKS, ADMIN_LINK] : LINKS;
  return (
    <nav className="flex items-center gap-1">
      {links.map((l) => {
        const active = l.href === "/" ? pathname === "/" : pathname.startsWith(l.href);
        return (
          <Link
            key={l.href}
            href={l.href}
            className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[13px]"
            style={{
              fontWeight: active ? 500 : 400,
              background: active ? "var(--color-background-info)" : "transparent",
              color: active ? "var(--color-text-info)" : "var(--color-text-secondary)",
            }}
          >
            <i className={`ti ${l.icon}`} style={{ fontSize: 15 }} />
            {l.label}
          </Link>
        );
      })}
    </nav>
  );
}
