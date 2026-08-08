"use client";

import { useState } from "react";
import { useFormState, useFormStatus } from "react-dom";
import Link from "next/link";
import { registerAction } from "../login/actions";

const input: React.CSSProperties = {
  border: "0.5px solid var(--color-border-secondary)", borderRadius: 8, padding: "9px 11px",
  fontSize: 14, background: "var(--color-background-primary)", color: "var(--color-text-primary)", width: "100%",
};
const label: React.CSSProperties = { fontSize: 12, fontWeight: 500, color: "var(--color-text-secondary)", display: "block", marginBottom: 5 };

function SubmitBtn() {
  const { pending } = useFormStatus();
  return (
    <button type="submit" disabled={pending} className="inline-flex w-full items-center justify-center gap-1.5 rounded-md px-4 py-2.5 text-[14px] font-medium" style={{ background: "var(--color-background-info)", color: "var(--color-text-info)", border: "0.5px solid var(--color-border-secondary)", opacity: pending ? 0.6 : 1 }}>
      {pending && <i className="ti ti-loader-2 animate-spin" />} Daftar
    </button>
  );
}

export default function RegisterPage() {
  const [state, action] = useFormState(registerAction, {});
  const [showPw, setShowPw] = useState(false);
  return (
    <div className="flex min-h-[80vh] items-center justify-center px-4">
      <form action={action} className="w-full max-w-[380px] rounded-lg p-6" style={{ background: "var(--color-background-primary)", border: "0.5px solid var(--color-border-tertiary)" }}>
        <div className="mb-5 flex items-center gap-2">
          <span className="flex h-8 w-8 items-center justify-center rounded-md text-sm font-semibold" style={{ background: "var(--color-background-info)", color: "var(--color-text-info)" }}><i className="ti ti-scale" /></span>
          <div>
            <div className="text-[15px] font-medium">Legal · MUP</div>
            <div className="text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Buat akun pemohon</div>
          </div>
        </div>
        <h1 className="mb-1 text-[16px] font-medium">Daftar dengan email Anda</h1>
        <p className="mb-4 text-[11px]" style={{ color: "var(--color-text-tertiary)" }}>Akun baru mendapat peran Pemohon. Peran lain diatur oleh admin Legal.</p>
        <div className="flex flex-col gap-3">
          <div>
            <label style={label}>Nama lengkap</label>
            <input style={input} name="name" placeholder="Nama Anda" autoComplete="name" required />
          </div>
          <div>
            <label style={label}>Email</label>
            <input style={input} type="email" name="email" placeholder="nama@email.com" autoComplete="email" required />
          </div>
          <div>
            <label style={label}>Departemen <span style={{ color: "var(--color-text-tertiary)", fontWeight: 400 }}>(opsional)</span></label>
            <input style={input} name="department" placeholder="cth. Sales" />
          </div>
          <div>
            <label style={label}>Kata sandi <span style={{ color: "var(--color-text-tertiary)", fontWeight: 400 }}>(min. 8 karakter)</span></label>
            <div style={{ position: "relative" }}>
              <input style={{ ...input, paddingRight: 40 }} type={showPw ? "text" : "password"} name="password" placeholder="••••••••" autoComplete="new-password" required />
              <button type="button" onClick={() => setShowPw((v) => !v)} aria-label={showPw ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"} style={{ position: "absolute", right: 6, top: "50%", transform: "translateY(-50%)", display: "flex", padding: 6, color: "var(--color-text-tertiary)" }}>
                <i className={showPw ? "ti ti-eye-off" : "ti ti-eye"} style={{ fontSize: 16 }} />
              </button>
            </div>
          </div>
          {state?.error && <div className="rounded-md px-3 py-2 text-[12px]" style={{ background: "var(--color-background-danger)", color: "var(--color-text-danger)" }}>{state.error}</div>}
          <SubmitBtn />
        </div>
        <p className="mt-4 text-center text-[12px]" style={{ color: "var(--color-text-tertiary)" }}>
          Sudah punya akun? <Link href="/login" style={{ color: "var(--color-text-info)" }}>Masuk</Link>
        </p>
      </form>
    </div>
  );
}
