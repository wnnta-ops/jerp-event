"use client";

import { signIn } from "next-auth/react";
import { useRouter } from "next/navigation";
import { useState } from "react";

const DEMO = [
  { role: "Management", email: "admin@javasaleshub.test" },
  { role: "Sales / Koordinator", email: "sales@javasaleshub.test" },
  { role: "Collection", email: "collection@javasaleshub.test" },
];

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("admin@javasaleshub.test");
  const [password, setPassword] = useState("password");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError("");
    const res = await signIn("credentials", { email, password, redirect: false });
    setLoading(false);
    if (res?.error) setError("Email atau password salah.");
    else router.push("/");
  }

  return (
    <div style={{ display: "grid", placeItems: "center", minHeight: "100vh", padding: 20 }}>
      <div className="card" style={{ maxWidth: 400, width: "100%" }}>
        <div className="pad">
          <div className="brand" style={{ padding: "4px 0 14px" }}>
            <span className="dot" />
            <div>
              Java Sales Hub
              <small>Internal system</small>
            </div>
          </div>
          <form onSubmit={submit}>
            <div className="field">
              <label>Email</label>
              <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required />
            </div>
            <div className="field">
              <label>Password</label>
              <input
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                type="password"
                required
              />
            </div>
            {error && (
              <div className="notice" style={{ marginBottom: 12 }}>
                <span>⚠️</span>
                <div>{error}</div>
              </div>
            )}
            <button className="btn" style={{ width: "100%", justifyContent: "center" }} disabled={loading}>
              {loading ? "Masuk…" : "Masuk"}
            </button>
          </form>
          <div className="rolehint" style={{ marginTop: 14 }}>
            Akun demo (password: <b>password</b>):
            {DEMO.map((d) => (
              <div key={d.email} style={{ marginTop: 4 }}>
                <button
                  className="pill"
                  style={{ cursor: "pointer" }}
                  onClick={() => {
                    setEmail(d.email);
                    setPassword("password");
                  }}
                >
                  {d.role} — {d.email}
                </button>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
