"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { signOut } from "next-auth/react";
import { useState } from "react";
import type { Role } from "@prisma/client";
import { ROLE_LABEL } from "@/lib/constants";
import { canManageUsers } from "@/lib/user-access";

type NavItem = { href: string; label: string; ic: string; tag?: string; roles?: Role[] };
type NavGroup = { group: string; items: NavItem[] };

// Grup menu khusus pengelola user — hanya tampil bila boleh (canManageUsers).
// Tipenya WAJIB eksplisit: tanpa itu [...NAV, ADMIN_GROUP] melebarkan tipe
// gabungannya, dan `it.tag` pada Daily Report gagal dikompilasi.
const ADMIN_GROUP: NavGroup = {
  group: "Administrasi",
  items: [{ href: "/users", label: "Manajemen User", ic: "👥" }],
};

const NAV: NavGroup[] = [
  {
    group: "Kerja Harian",
    items: [
      { href: "/", label: "Dashboard", ic: "▤" },
      { href: "/input", label: "Input Harian", ic: "✏️" },
      { href: "/pipeline", label: "Customer Pipeline", ic: "🔄" },
      { href: "/leads", label: "Leads", ic: "🎯", tag: "MC", roles: ["SALES", "MANAGEMENT", "SYSADMIN"] },
      { href: "/gagal", label: "Pipeline Gagal", ic: "📉" },
    ],
  },
  {
    group: "Report (auto)",
    items: [
      { href: "/daily", label: "Daily Report", ic: "📄", tag: "WA" },
      { href: "/monthly", label: "Monthly / Yearly", ic: "📈" },
      { href: "/matrix", label: "Matrix Report", ic: "🧮" },
    ],
  },
  {
    group: "Data",
    items: [
      { href: "/stock", label: "Stock Unit", ic: "🏠" },
      { href: "/rotasi", label: "Rotasi & Bendera", ic: "🔁" },
      { href: "/collection", label: "Collection", ic: "💳" },
    ],
  },
  {
    group: "Sistem",
    items: [
      { href: "/management", label: "Management", ic: "🛠️", roles: ["MANAGEMENT", "SALES"] },
    ],
  },
];

const TITLES: Record<string, [string, string]> = {
  "/": ["Dashboard", "Ringkasan divisi sales"],
  "/input": ["Input Harian", "Catat aktivitas hari ini"],
  "/pipeline": ["Customer Pipeline", "Progress tiap customer"],
  "/leads": ["Leads", "Leads dari MarComm — update per Sales Koor"],
  "/gagal": ["Pipeline Gagal", "Analisa deal yang batal & pasif"],
  "/daily": ["Daily Report", "Recap harian untuk WA"],
  "/monthly": ["Monthly / Yearly", "Rekap & target tahunan"],
  "/matrix": ["Matrix Report", "Matrix daily sales — export Excel/PDF"],
  "/stock": ["Stock Unit", "Inventori rumah"],
  "/rotasi": ["Rotasi & Bendera", "Koordinator & agen"],
  "/collection": ["Collection", "Progress akad"],
  "/users": ["Manajemen User", "Tambah/ubah user & hak akses"],
  "/management": ["Management", "Pengaturan sistem"],
};

export function Shell({
  user,
  todayLabel,
  children,
}: {
  user: { name: string; role: Role };
  todayLabel: string;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [title, crumb] = TITLES[pathname] ?? ["Java Sales Hub", ""];

  return (
    <div className="app">
      {open && <div className="scrim" onClick={() => setOpen(false)} />}
      <aside className={`side ${open ? "open" : ""}`}>
        <div className="brand">
          <span className="dot" />
          <div>
            Java Sales Hub
            <small>Internal system</small>
          </div>
        </div>
        {(canManageUsers(user.role) ? [...NAV, ADMIN_GROUP] : NAV).map((g) => {
          const items = g.items.filter((it) => !it.roles || it.roles.includes(user.role));
          if (items.length === 0) return null;
          return (
          <div key={g.group}>
            <div className="navgroup">{g.group}</div>
            <ul className="nav">
              {items.map((it) => {
                const active = pathname === it.href;
                return (
                  <li key={it.href}>
                    <Link
                      href={it.href}
                      prefetch={false}
                      className={active ? "active" : ""}
                      onClick={() => setOpen(false)}
                    >
                      <span className="ic">{it.ic}</span>
                      {it.label}
                      {it.tag && <span className="ftag">{it.tag}</span>}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
          );
        })}
        <div className="side-foot">
          Model: 2 data inti (customer + unit). Semua report otomatis dari event ledger.
        </div>
      </aside>

      <div className="main">
        <div className="topbar">
          <button className="menu-btn" onClick={() => setOpen((v) => !v)} aria-label="Menu">
            ☰
          </button>
          <div>
            <h1>{title}</h1>
            <div className="crumb">{crumb}</div>
          </div>
          <div className="spacer" />
          <div className="rolebox">
            <span>
              📅 <b>{todayLabel}</b>
            </span>
            <span style={{ width: 1, height: 18, background: "var(--line)" }} />
            <span>{user.name}</span>
            <span className="pill">{ROLE_LABEL[user.role]}</span>
            {/* Kembali ke portal J-ERP — <a> polos (bukan Link) agar TIDAK diberi basePath /sales. */}
            <a className="btn ghost sm" href="/dashboard" title="Kembali ke Dashboard J-ERP">
              ← Dashboard
            </a>
            <button className="btn ghost sm" onClick={() => signOut({ callbackUrl: "/sales/login" })}>
              Keluar
            </button>
          </div>
        </div>
        <div className="content">{children}</div>
      </div>
    </div>
  );
}
