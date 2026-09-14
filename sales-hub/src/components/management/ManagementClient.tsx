"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Role } from "@prisma/client";
import type { ReminderData, TargetData } from "@/lib/management";
import { ROLE_LABEL } from "@/lib/constants";
import { toast } from "@/components/Toast";

type UserRow = { id: string; name: string; email: string; role: Role };
type Tab = "reminder" | "target" | "user" | "master" | "regulasi";

export function ManagementClient({
  role,
  selfId,
  reminder,
  targets,
  users,
}: {
  role: Role;
  selfId: string;
  reminder: ReminderData;
  targets: TargetData | null;
  users: UserRow[] | null;
}) {
  const isMgmt = role === "MANAGEMENT";
  const [tab, setTab] = useState<Tab>("reminder");

  const allTabs: { k: Tab; label: string; mgmt?: boolean }[] = [
    { k: "reminder", label: "🔔 Reminder Follow-up" },
    { k: "target", label: "🎯 Target", mgmt: true },
    { k: "user", label: "👤 User & Role", mgmt: true },
    { k: "master", label: "🗂️ Master Data", mgmt: true },
    { k: "regulasi", label: "🛡️ Regulasi Akses", mgmt: true },
  ];
  const tabs = allTabs.filter((t) => isMgmt || !t.mgmt);

  return (
    <>
      <div className="tabbar">
        {tabs.map((t) => (
          <button key={t.k} className={`tab-link ${tab === t.k ? "on" : ""}`} onClick={() => setTab(t.k)}>
            {t.label}
          </button>
        ))}
      </div>
      {tab === "reminder" && <ReminderPanel data={reminder} />}
      {tab === "target" && targets && <TargetPanel data={targets} />}
      {tab === "user" && users && <UserPanel users={users} selfId={selfId} />}
      {tab === "master" && <Placeholder title="Master Data" />}
      {tab === "regulasi" && <Placeholder title="Regulasi Akses" />}
    </>
  );
}

function Placeholder({ title }: { title: string }) {
  return (
    <div className="card"><div className="pad">
      <h3>{title}</h3>
      <p className="rolehint" style={{ marginTop: 6 }}>Segera — bagian ini digarap berikutnya.</p>
    </div></div>
  );
}

function ReminderPanel({ data }: { data: ReminderData }) {
  const router = useRouter();
  const [days, setDays] = useState(data.global.days);
  const [notifyApp, setNotifyApp] = useState(data.global.notifyApp);
  const [notifyWa, setNotifyWa] = useState(data.global.notifyWa);
  const [active, setActive] = useState(data.global.active);
  const [ov, setOv] = useState<Record<string, string>>(
    Object.fromEntries(data.overrides.map((o) => [o.coordinatorId, String(o.days)])),
  );
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    const overrides = Object.entries(ov)
      .filter(([, v]) => v.trim() !== "" && Number(v) > 0)
      .map(([coordinatorId, v]) => ({ coordinatorId, days: Math.round(Number(v)), active: true }));
    const res = await fetch("/api/management/reminder", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ global: { days, notifyApp, notifyWa, active }, overrides }),
    });
    setBusy(false);
    if (res.ok) { toast("✓ Pengaturan reminder disimpan"); router.refresh(); }
    else toast((await res.json().catch(() => ({}))).error ?? "Gagal");
  }

  return (
    <>
      <div className="card"><div className="pad">
        <h3>Reminder Follow-up</h3>
        <p className="rolehint" style={{ marginBottom: 14 }}>
          Ingetin sales/koor kalau ada customer yang belum di-follow-up dalam batas waktu. Sales Koor bisa atur batas tim-nya di tabel bawah.
        </p>
        <div className="frow">
          <div className="field">
            <label>Ingatkan kalau belum follow-up dalam (hari)</label>
            <div style={{ display: "inline-flex", alignItems: "center", gap: 8 }}>
              <button className="btn ghost sm" onClick={() => setDays((d) => Math.max(1, d - 1))}>−</button>
              <input style={{ width: 64, textAlign: "center", fontWeight: 700 }} value={days}
                onChange={(e) => setDays(Math.max(1, Math.round(Number(e.target.value) || 1)))} inputMode="numeric" />
              <button className="btn ghost sm" onClick={() => setDays((d) => d + 1)}>＋</button>
              <span className="rolehint">hari</span>
            </div>
          </div>
          <div className="field">
            <label>Notifikasi</label>
            <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <label className="rolehint" style={{ display: "flex", gap: 7, alignItems: "center" }}>
                <input type="checkbox" checked={notifyApp} onChange={(e) => setNotifyApp(e.target.checked)} /> Notif di aplikasi
              </label>
              <label className="rolehint" style={{ display: "flex", gap: 7, alignItems: "center" }}>
                <input type="checkbox" checked={notifyWa} onChange={(e) => setNotifyWa(e.target.checked)} /> Notif WhatsApp
              </label>
              <label className="rolehint" style={{ display: "flex", gap: 7, alignItems: "center" }}>
                <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Reminder aktif
              </label>
            </div>
          </div>
        </div>

        <div style={{ fontWeight: 700, fontSize: 13.5, margin: "8px 0 8px" }}>Override per Koordinator (opsional)</div>
        <div className="tablewrap">
          <table>
            <thead><tr><th>Koordinator</th><th>Batas follow-up (hari)</th></tr></thead>
            <tbody>
              {data.coordinators.map((c) => (
                <tr key={c.id}>
                  <td>{c.name}</td>
                  <td>
                    <input style={{ width: 90 }} placeholder={`default ${days}`} value={ov[c.id] ?? ""}
                      onChange={(e) => setOv((m) => ({ ...m, [c.id]: e.target.value }))} inputMode="numeric" />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div style={{ marginTop: 12 }}>
          <button className="btn" onClick={save} disabled={busy}>{busy ? "Menyimpan…" : "Simpan pengaturan reminder"}</button>
        </div>
      </div></div>

      <div className="card"><div className="pad">
        <h3>Perlu di-follow-up sekarang ({data.overdue.length})</h3>
        <p className="rolehint" style={{ marginBottom: 10 }}>Customer yang sudah lewat batas waktu &amp; belum di-follow-up.</p>
        <div className="tablewrap">
          <table>
            <thead><tr><th>Customer</th><th>Koor</th><th>Sales</th><th>Cluster</th><th style={{ textAlign: "right" }}>Telat</th></tr></thead>
            <tbody>
              {data.overdue.length === 0 && <tr><td colSpan={5} style={{ color: "var(--sub)" }}>Semua customer sudah di-follow-up tepat waktu 🎉</td></tr>}
              {data.overdue.map((o, i) => (
                <tr key={i}>
                  <td><b>{o.nama}</b></td><td>{o.koor}</td><td>{o.sales}</td><td>{o.cluster}</td>
                  <td style={{ textAlign: "right" }}><span className="badge b-red">{o.days} hari</span></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div></div>
    </>
  );
}

function TargetPanel({ data }: { data: TargetData }) {
  const router = useRouter();
  const [annualSpr, setAnnualSpr] = useState(String(data.annualSpr));
  const [monthlyUnit, setMonthlyUnit] = useState(String(data.monthlyUnit));
  const [teams, setTeams] = useState(data.teams.map((t) => ({ ...t, target: String(t.target) })));
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    const res = await fetch("/api/management/target", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        annualSpr: Number(annualSpr) || 0,
        monthlyUnit: Number(monthlyUnit) || 0,
        teams: teams.map((t) => ({ id: t.id, value: Number(t.target) || 0 })),
      }),
    });
    setBusy(false);
    if (res.ok) { toast("✓ Target disimpan"); router.refresh(); }
    else toast((await res.json().catch(() => ({}))).error ?? "Gagal");
  }

  return (
    <div className="card"><div className="pad">
      <h3>Target</h3>
      <p className="rolehint" style={{ marginBottom: 14 }}>Langsung kepakai di Dashboard &amp; Executive Summary. Periode bulan: {data.monthKey}.</p>
      <div className="frow">
        <div className="field"><label>Target SPR 2026 (tahunan)</label>
          <input value={annualSpr} onChange={(e) => setAnnualSpr(e.target.value)} inputMode="numeric" /></div>
        <div className="field"><label>Target UTJ bulan ini</label>
          <input value={monthlyUnit} onChange={(e) => setMonthlyUnit(e.target.value)} inputMode="numeric" /></div>
      </div>
      <div style={{ fontWeight: 700, fontSize: 13.5, margin: "6px 0 8px" }}>Target per Tim (bulan ini)</div>
      {teams.length === 0 ? (
        <p className="rolehint">Belum ada tim. Bikin tim dulu di Master Data.</p>
      ) : (
        <div className="tablewrap"><table>
          <thead><tr><th>Tim</th><th>Target UTJ</th></tr></thead>
          <tbody>
            {teams.map((t, i) => (
              <tr key={t.id}><td>{t.name}</td><td>
                <input style={{ width: 90 }} value={t.target} inputMode="numeric"
                  onChange={(e) => setTeams((ts) => ts.map((x, j) => (j === i ? { ...x, target: e.target.value } : x)))} />
              </td></tr>
            ))}
          </tbody>
        </table></div>
      )}
      <div style={{ marginTop: 12 }}><button className="btn" onClick={save} disabled={busy}>{busy ? "Menyimpan…" : "Simpan target"}</button></div>
    </div></div>
  );
}

function UserPanel({ users, selfId }: { users: UserRow[]; selfId: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState<string | null>(null);

  async function setRole(id: string, role: string) {
    setBusy(id);
    const res = await fetch("/api/management/user", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, role }),
    });
    setBusy(null);
    if (res.ok) { toast("✓ Role diperbarui"); router.refresh(); }
    else toast((await res.json().catch(() => ({}))).error ?? "Gagal");
  }

  return (
    <div className="card"><div className="pad">
      <h3>User &amp; Role</h3>
      <p className="rolehint" style={{ marginBottom: 12 }}>Atur role tiap akun. (Tambah akun baru menyusul.)</p>
      <div className="tablewrap"><table>
        <thead><tr><th>Nama</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
          {users.map((u) => (
            <tr key={u.id}>
              <td><b>{u.name}</b></td>
              <td>{u.email}</td>
              <td>
                <select value={u.role} disabled={busy === u.id} onChange={(e) => setRole(u.id, e.target.value)}>
                  {(["SALES", "COLLECTION", "MANAGEMENT", "KPR"] as const).map((r) => (
                    <option key={r} value={r}>{ROLE_LABEL[r]}</option>
                  ))}
                </select>
              </td>
            </tr>
          ))}
        </tbody>
      </table></div>
    </div></div>
  );
}
