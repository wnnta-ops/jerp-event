"use client";

import { useState } from "react";

type Row = { id: string; name: string; email: string; role: string };
type RoleOpt = { value: string; label: string };

// Password acak yang mudah dibaca untuk reset (user diminta ganti sendiri nanti).
// RNG kriptografis (crypto.getRandomValues) — bukan Math.random.
function randInt(max: number): number {
  const buf = new Uint32Array(1);
  crypto.getRandomValues(buf);
  return buf[0] % max;
}
function genPassword(): string {
  const a = "ABCDEFGHJKLMNPQRSTUVWXYZ", b = "abcdefghijkmnpqrstuvwxyz", d = "23456789", s = "!@#$%*";
  const pick = (set: string, n: number) => Array.from({ length: n }, () => set[randInt(set.length)]);
  const chars = [...pick(a, 2), ...pick(b, 4), ...pick(d, 3), ...pick(s, 1)];
  for (let i = chars.length - 1; i > 0; i--) { const j = randInt(i + 1); [chars[i], chars[j]] = [chars[j], chars[i]]; } // Fisher–Yates
  return chars.join("");
}

export function UsersAdmin({
  initialUsers, roles, actingId, canSeeSysadmin,
}: { initialUsers: Row[]; roles: RoleOpt[]; actingId: string; canSeeSysadmin: boolean }) {
  const [users, setUsers] = useState<Row[]>(initialUsers);
  const [editing, setEditing] = useState<Row | "new" | null>(null);
  const [resetting, setResetting] = useState<Row | null>(null);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const roleLabel = (v: string) => roles.find((r) => r.value === v)?.label ?? v;

  async function reload() {
    const r = await fetch("/sales/api/users", { credentials: "include" });
    if (r.ok) setUsers((await r.json()).users);
  }

  async function onDelete(u: Row) {
    if (!confirm(`Hapus user "${u.name}" (${u.email})?`)) return;
    setBusy(true);
    const r = await fetch(`/sales/api/users/${u.id}`, { method: "DELETE", credentials: "include" });
    const data = await r.json().catch(() => ({}));
    setBusy(false);
    if (r.ok) { setMsg({ kind: "ok", text: "User dihapus." }); reload(); }
    else setMsg({ kind: "err", text: data.error ?? "Gagal menghapus." });
  }

  return (
    <div style={{ maxWidth: 1000 }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, marginBottom: 14 }}>
        <div>
          <div style={{ fontSize: 13, color: "var(--muted, #64748b)" }}>
            Tambah, ubah, hapus user & atur perannya (peran menentukan hak akses modul).
            {!canSeeSysadmin && " Akun Sysadmin tidak ditampilkan."}
          </div>
        </div>
        <button className="btn primary sm" onClick={() => { setMsg(null); setEditing("new"); }}>+ Tambah User</button>
      </div>

      {msg && (
        <div className="notice" style={{ marginBottom: 12, color: msg.kind === "ok" ? "#047857" : "#b91c1c" }}>
          <span>{msg.kind === "ok" ? "✅" : "⚠️"}</span><div>{msg.text}</div>
        </div>
      )}

      <div className="card" style={{ overflowX: "auto" }}>
        <table style={{ width: "100%", fontSize: 13, borderCollapse: "collapse" }}>
          <thead>
            <tr style={{ textAlign: "left", color: "var(--muted, #64748b)" }}>
              <th style={{ padding: "10px 12px" }}>Nama</th>
              <th style={{ padding: "10px 12px" }}>Email</th>
              <th style={{ padding: "10px 12px" }}>Peran (hak akses)</th>
              <th style={{ padding: "10px 12px", textAlign: "right" }}>Aksi</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} style={{ borderTop: "1px solid var(--line, #e5e7eb)" }}>
                <td style={{ padding: "10px 12px" }}>{u.name}{u.id === actingId && <span style={{ marginLeft: 6, fontSize: 11, color: "var(--muted, #94a3b8)" }}>(Anda)</span>}</td>
                <td style={{ padding: "10px 12px", color: "var(--muted, #64748b)" }}>{u.email}</td>
                <td style={{ padding: "10px 12px" }}><span className="pill">{roleLabel(u.role)}</span></td>
                <td style={{ padding: "10px 12px", textAlign: "right", whiteSpace: "nowrap" }}>
                  <button className="btn ghost sm" onClick={() => { setMsg(null); setEditing(u); }}>Edit</button>{" "}
                  <button className="btn ghost sm" style={{ color: "#b45309" }} onClick={() => { setMsg(null); setResetting(u); }}>Reset PW</button>{" "}
                  <button className="btn ghost sm" style={{ color: "#b91c1c" }} disabled={busy} onClick={() => onDelete(u)}>Hapus</button>
                </td>
              </tr>
            ))}
            {users.length === 0 && <tr><td colSpan={4} style={{ padding: "28px 12px", textAlign: "center", color: "var(--muted, #94a3b8)" }}>Belum ada user.</td></tr>}
          </tbody>
        </table>
      </div>

      {editing && (
        <UserModal
          key={editing === "new" ? "new" : editing.id}
          row={editing === "new" ? null : editing}
          roles={roles}
          onClose={() => setEditing(null)}
          onSaved={(t) => { setEditing(null); setMsg({ kind: "ok", text: t }); reload(); }}
        />
      )}

      {resetting && (
        <ResetPwModal
          key={resetting.id}
          row={resetting}
          onClose={() => setResetting(null)}
          onDone={(t) => { setResetting(null); setMsg({ kind: "ok", text: t }); }}
        />
      )}
    </div>
  );
}

function ResetPwModal({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: (t: string) => void }) {
  const [pw, setPw] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setErr(null);
    if (pw.length < 8) { setErr("Kata sandi minimal 8 karakter."); return; }
    setBusy(true);
    const r = await fetch(`/sales/api/users/${row.id}/reset-password`, {
      method: "POST", credentials: "include",
      headers: { "Content-Type": "application/json" }, body: JSON.stringify({ password: pw }),
    });
    const data = await r.json().catch(() => ({}));
    setBusy(false);
    if (r.ok) onDone(`Password ${row.name} berhasil di-reset.`);
    else setErr(data.error ?? "Gagal mereset password.");
  }

  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 50, display: "grid", placeItems: "center", padding: 16, background: "rgba(15,23,42,.5)" }} onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="card pad" style={{ maxWidth: 440, width: "100%" }}>
        <h3 style={{ margin: "0 0 4px", fontSize: 15 }}>Reset Password</h3>
        <div style={{ fontSize: 12, color: "var(--muted, #64748b)", marginBottom: 12 }}>{row.name} · {row.email}</div>

        <div className="field"><label>Password baru</label>
          <div style={{ display: "flex", gap: 8 }}>
            <input type="text" value={pw} onChange={(e) => { setPw(e.target.value); setCopied(false); }} placeholder="min. 8 karakter" autoFocus style={{ flex: 1 }} />
            <button type="button" className="btn ghost sm" style={{ whiteSpace: "nowrap" }} onClick={() => { setPw(genPassword()); setCopied(false); }}>Buat otomatis</button>
          </div>
        </div>
        {pw && (
          <button type="button" className="btn ghost sm" style={{ color: "#2563eb", padding: 0, marginTop: -4 }}
            onClick={() => { navigator.clipboard?.writeText(pw).then(() => setCopied(true)).catch(() => {}); }}>
            {copied ? "✓ Tersalin — berikan ke user" : "Salin password"}
          </button>
        )}

        {err && <div className="notice" style={{ margin: "8px 0", color: "#b91c1c" }}><span>⚠️</span><div>{err}</div></div>}

        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 8 }}>
          <button type="button" className="btn ghost sm" onClick={onClose}>Batal</button>
          <button type="submit" className="btn primary sm" disabled={busy}>{busy ? "Menyimpan…" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}

function UserModal({ row, roles, onClose, onSaved }: { row: Row | null; roles: RoleOpt[]; onClose: () => void; onSaved: (t: string) => void }) {
  const isNew = row === null;
  const [name, setName] = useState(row?.name ?? "");
  const [email, setEmail] = useState(row?.email ?? "");
  const [roleVal, setRoleVal] = useState(row?.role ?? roles[0]?.value ?? "SALES");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setErr(null); setBusy(true);
    const payload: Record<string, string> = { name, email, role: roleVal };
    if (password) payload.password = password;
    const url = isNew ? "/sales/api/users" : `/sales/api/users/${row!.id}`;
    const method = isNew ? "POST" : "PATCH";
    const r = await fetch(url, { method, credentials: "include", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
    const data = await r.json().catch(() => ({}));
    setBusy(false);
    if (r.ok) onSaved(isNew ? "User dibuat." : "Perubahan disimpan.");
    else setErr(data.error ?? "Gagal menyimpan.");
  }

  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 50, display: "grid", placeItems: "center", padding: 16, background: "rgba(15,23,42,.5)" }} onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="card pad" style={{ maxWidth: 440, width: "100%" }}>
        <h3 style={{ margin: "0 0 12px", fontSize: 15 }}>{isNew ? "Tambah User" : "Edit User"}</h3>

        <div className="field"><label>Nama</label>
          <input value={name} onChange={(e) => setName(e.target.value)} required /></div>
        <div className="field"><label>Email</label>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required /></div>
        <div className="field"><label>Peran (hak akses modul)</label>
          <select value={roleVal} onChange={(e) => setRoleVal(e.target.value)}>
            {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select></div>
        <div className="field"><label>Kata sandi {isNew ? "" : "(kosongkan bila tak diubah)"}</label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder={isNew ? "min. 8 karakter" : "••••••••"} /></div>

        {err && <div className="notice" style={{ margin: "8px 0", color: "#b91c1c" }}><span>⚠️</span><div>{err}</div></div>}

        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 8 }}>
          <button type="button" className="btn ghost sm" onClick={onClose}>Batal</button>
          <button type="submit" className="btn primary sm" disabled={busy}>{busy ? "Menyimpan…" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}
