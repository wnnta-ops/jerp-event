"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createUser, updateUser, deleteUser, resetPassword } from "@/app/users/actions";

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

type Row = { id: string; name: string; email: string; role: string; department: string | null; isActive: boolean };
type RoleOpt = { value: string; label: string };

export function UsersAdmin({
  users, roles, actingId, canSeeSysadmin,
}: { users: Row[]; roles: RoleOpt[]; actingId: string; canSeeSysadmin: boolean }) {
  const router = useRouter();
  const [editing, setEditing] = useState<Row | "new" | null>(null);
  const [resetting, setResetting] = useState<Row | null>(null);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [pending, start] = useTransition();

  const roleLabel = (v: string) => roles.find((r) => r.value === v)?.label ?? v;

  function onDelete(u: Row) {
    if (!confirm(`Hapus user "${u.name}" (${u.email})?`)) return;
    start(async () => {
      const r = await deleteUser(u.id);
      setMsg(r.ok ? { kind: "ok", text: "User dihapus." } : { kind: "err", text: r.error });
      router.refresh();
    });
  }

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold" style={{ color: "var(--color-text-primary)" }}>Manajemen User</h1>
          <p className="text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>
            Tambah, ubah, hapus user & atur perannya (peran menentukan hak akses modul).
            {!canSeeSysadmin && " Akun Sysadmin tidak ditampilkan."}
          </p>
        </div>
        <button
          onClick={() => { setMsg(null); setEditing("new"); }}
          className="rounded-md px-3 py-1.5 text-[13px] font-medium text-white"
          style={{ background: "var(--color-text-info)" }}
        >+ Tambah User</button>
      </div>

      {msg && (
        <div className="mb-3 rounded-md px-3 py-2 text-[13px]"
          style={{
            background: msg.kind === "ok" ? "var(--color-background-success, #ecfdf5)" : "var(--color-background-danger, #fef2f2)",
            color: msg.kind === "ok" ? "var(--color-text-success, #047857)" : "var(--color-text-danger, #b91c1c)",
          }}>{msg.text}</div>
      )}

      <div className="overflow-x-auto rounded-lg" style={{ border: "0.5px solid var(--color-border-tertiary)" }}>
        <table className="w-full text-[13px]">
          <thead>
            <tr style={{ background: "var(--color-background-secondary, #f8fafc)", color: "var(--color-text-secondary)" }}>
              <th className="px-3 py-2 text-left font-medium">Nama</th>
              <th className="px-3 py-2 text-left font-medium">Email</th>
              <th className="px-3 py-2 text-left font-medium">Peran</th>
              <th className="px-3 py-2 text-left font-medium">Departemen</th>
              <th className="px-3 py-2 text-left font-medium">Status</th>
              <th className="px-3 py-2 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} style={{ borderTop: "0.5px solid var(--color-border-tertiary)" }}>
                <td className="px-3 py-2">{u.name}{u.id === actingId && <span className="ml-1 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>(Anda)</span>}</td>
                <td className="px-3 py-2" style={{ color: "var(--color-text-secondary)" }}>{u.email}</td>
                <td className="px-3 py-2">{roleLabel(u.role)}</td>
                <td className="px-3 py-2" style={{ color: "var(--color-text-secondary)" }}>{u.department || "—"}</td>
                <td className="px-3 py-2">
                  <span className="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    style={u.isActive
                      ? { background: "var(--color-background-success, #ecfdf5)", color: "var(--color-text-success, #047857)" }
                      : { background: "var(--color-background-tertiary, #f1f5f9)", color: "var(--color-text-tertiary)" }}>
                    {u.isActive ? "Aktif" : "Nonaktif"}
                  </span>
                </td>
                <td className="px-3 py-2 text-right whitespace-nowrap">
                  <button onClick={() => { setMsg(null); setEditing(u); }} className="mr-2 text-[13px]" style={{ color: "var(--color-text-info)" }}>Edit</button>
                  <button onClick={() => { setMsg(null); setResetting(u); }} className="mr-2 text-[13px]" style={{ color: "var(--color-text-warning, #b45309)" }}>Reset PW</button>
                  <button onClick={() => onDelete(u)} disabled={pending} className="text-[13px]" style={{ color: "var(--color-text-danger, #b91c1c)" }}>Hapus</button>
                </td>
              </tr>
            ))}
            {users.length === 0 && (
              <tr><td colSpan={6} className="px-3 py-8 text-center" style={{ color: "var(--color-text-tertiary)" }}>Belum ada user.</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {editing && (
        <UserModal
          key={editing === "new" ? "new" : editing.id}
          row={editing === "new" ? null : editing}
          roles={roles}
          onClose={() => setEditing(null)}
          onDone={(text) => { setEditing(null); setMsg({ kind: "ok", text }); router.refresh(); }}
          onError={(text) => setMsg({ kind: "err", text })}
        />
      )}

      {resetting && (
        <ResetPwModal
          key={resetting.id}
          row={resetting}
          onClose={() => setResetting(null)}
          onDone={(text) => { setResetting(null); setMsg({ kind: "ok", text }); }}
        />
      )}
    </div>
  );
}

function ResetPwModal({ row, onClose, onDone }: { row: Row; onClose: () => void; onDone: (t: string) => void }) {
  const [pending, start] = useTransition();
  const [pw, setPw] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setErr(null);
    if (pw.length < 8) { setErr("Kata sandi minimal 8 karakter."); return; }
    const fd = new FormData();
    fd.set("id", row.id);
    fd.set("password", pw);
    start(async () => {
      const r = await resetPassword(fd);
      if (r.ok) onDone(`Password ${row.name} berhasil di-reset.`);
      else setErr(r.error);
    });
  }

  const inputStyle = "w-full rounded-md px-3 py-2 text-[13px]";
  const inputCss = { border: "0.5px solid var(--color-border-secondary, #cbd5e1)", background: "var(--color-background-primary)" };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(15,23,42,.45)" }} onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit}
        className="w-full max-w-[440px] rounded-xl p-5"
        style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <h2 className="mb-1 text-[15px] font-semibold" style={{ color: "var(--color-text-primary)" }}>Reset Password</h2>
        <p className="mb-3 text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>{row.name} · {row.email}</p>

        <label className="mb-1 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Password baru</label>
        <div className="flex gap-2">
          <input value={pw} onChange={(e) => { setPw(e.target.value); setCopied(false); }} type="text" placeholder="min. 8 karakter"
            className={inputStyle} style={inputCss} autoFocus />
          <button type="button" onClick={() => { setPw(genPassword()); setCopied(false); }}
            className="whitespace-nowrap rounded-md px-3 py-2 text-[12px] font-medium"
            style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-secondary)" }}>Buat otomatis</button>
        </div>
        {pw && (
          <button type="button"
            onClick={() => { navigator.clipboard?.writeText(pw).then(() => setCopied(true)).catch(() => {}); }}
            className="mt-2 text-[12px]" style={{ color: "var(--color-text-info)" }}>
            {copied ? "✓ Tersalin — berikan ke user" : "Salin password"}
          </button>
        )}

        {err && <div className="mt-3 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger, #fef2f2)", color: "var(--color-text-danger, #b91c1c)" }}>{err}</div>}

        <div className="mt-4 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-md px-3 py-1.5 text-[13px]" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-secondary)" }}>Batal</button>
          <button type="submit" disabled={pending} className="rounded-md px-3 py-1.5 text-[13px] font-medium text-white" style={{ background: "var(--color-text-info)", opacity: pending ? 0.6 : 1 }}>{pending ? "Menyimpan…" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}

function UserModal({
  row, roles, onClose, onDone, onError,
}: { row: Row | null; roles: RoleOpt[]; onClose: () => void; onDone: (t: string) => void; onError: (t: string) => void }) {
  const isNew = row === null;
  const [pending, start] = useTransition();
  const [localErr, setLocalErr] = useState<string | null>(null);

  function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLocalErr(null);
    const fd = new FormData(e.currentTarget);
    if (!isNew && row) fd.set("id", row.id);
    start(async () => {
      const r = isNew ? await createUser(fd) : await updateUser(fd);
      if (r.ok) onDone(isNew ? "User dibuat." : "Perubahan disimpan.");
      else { setLocalErr(r.error); onError(r.error); }
    });
  }

  const inputStyle = "w-full rounded-md px-3 py-2 text-[13px]";
  const inputCss = { border: "0.5px solid var(--color-border-secondary, #cbd5e1)", background: "var(--color-background-primary)" };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: "rgba(15,23,42,.45)" }} onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit}
        className="w-full max-w-[460px] rounded-xl p-5"
        style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <h2 className="mb-3 text-[15px] font-semibold" style={{ color: "var(--color-text-primary)" }}>{isNew ? "Tambah User" : "Edit User"}</h2>

        <label className="mb-1 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Nama</label>
        <input name="name" defaultValue={row?.name ?? ""} required className={inputStyle} style={inputCss} />

        <label className="mb-1 mt-3 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Email</label>
        <input name="email" type="email" defaultValue={row?.email ?? ""} required className={inputStyle} style={inputCss} />

        <div className="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label className="mb-1 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Peran (hak akses)</label>
            <select name="role" defaultValue={row?.role ?? roles[0]?.value} className={inputStyle} style={inputCss}>
              {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>Departemen</label>
            <input name="department" defaultValue={row?.department ?? ""} className={inputStyle} style={inputCss} />
          </div>
        </div>

        <label className="mb-1 mt-3 block text-[12px] font-medium" style={{ color: "var(--color-text-secondary)" }}>
          Kata sandi {isNew ? "" : "(kosongkan bila tak diubah)"}
        </label>
        <input name="password" type="password" placeholder={isNew ? "min. 8 karakter" : "••••••••"} className={inputStyle} style={inputCss} />

        {!isNew && (
          <label className="mt-3 flex items-center gap-2 text-[13px]" style={{ color: "var(--color-text-secondary)" }}>
            <input type="checkbox" name="isActive" value="1" defaultChecked={row?.isActive} /> Akun aktif
          </label>
        )}

        {localErr && <div className="mt-3 rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger, #fef2f2)", color: "var(--color-text-danger, #b91c1c)" }}>{localErr}</div>}

        <div className="mt-4 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-md px-3 py-1.5 text-[13px]" style={{ border: "0.5px solid var(--color-border-tertiary)", color: "var(--color-text-secondary)" }}>Batal</button>
          <button type="submit" disabled={pending} className="rounded-md px-3 py-1.5 text-[13px] font-medium text-white" style={{ background: "var(--color-text-info)", opacity: pending ? 0.6 : 1 }}>{pending ? "Menyimpan…" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}
