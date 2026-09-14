"use client";

import { useMemo, useState } from "react";
import type { LostItem } from "@/lib/lost";

export function LostClient({ items }: { items: LostItem[] }) {
  const [q, setQ] = useState("");
  const [cat, setCat] = useState("all");
  const [alasan, setAlasan] = useState("all");
  const [cluster, setCluster] = useState("all");
  const [visible, setVisible] = useState(10); // rows tall the scroll window shows

  const alasanOpts = useMemo(
    () => [...new Set(items.map((i) => i.alasanLabel))].sort(),
    [items],
  );
  const clusterOpts = useMemo(
    () => [...new Set(items.map((i) => i.cluster))].sort(),
    [items],
  );

  const rows = useMemo(() => {
    const s = q.trim().toLowerCase();
    return items.filter((i) => {
      if (cat !== "all" && i.category !== cat) return false;
      if (alasan !== "all" && i.alasanLabel !== alasan) return false;
      if (cluster !== "all" && i.cluster !== cluster) return false;
      if (s && ![i.nama, i.sales, i.koor, i.bendera, i.cluster, i.type, i.alasanRaw ?? ""].join(" ").toLowerCase().includes(s))
        return false;
      return true;
    });
  }, [items, q, cat, alasan, cluster]);

  return (
    <div className="card">
      <div className="pad">
        <div className="actions">
          <input
            className="search"
            placeholder="🔎 Cari nama / sales / bendera / sebab"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <select value={cat} onChange={(e) => setCat(e.target.value)}>
            <option value="all">Semua Kategori</option>
            <option value="BATAL">Batal</option>
            <option value="MANDEK">Pasif</option>
          </select>
          <select value={alasan} onChange={(e) => setAlasan(e.target.value)}>
            <option value="all">Semua Sebab</option>
            {alasanOpts.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>
          <select value={cluster} onChange={(e) => setCluster(e.target.value)}>
            <option value="all">Semua Cluster</option>
            {clusterOpts.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
          <span className="rolehint">{rows.length} data</span>
          <label className="rolehint" style={{ marginLeft: "auto", display: "inline-flex", gap: 6, alignItems: "center" }}>
            Tampil:
            <select value={visible} onChange={(e) => setVisible(Number(e.target.value))}>
              <option value={5}>5 baris</option>
              <option value={10}>10 baris</option>
              <option value={15}>15 baris</option>
            </select>
          </label>
        </div>

        <div className="tablewrap scrollwin" style={{ maxHeight: visible * 60 }}>
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Kategori</th>
                <th>Sebab</th>
                <th>Tahap</th>
                <th>Cluster/Type</th>
                <th>Sales</th>
                <th>Koor</th>
                <th>Bendera</th>
                <th>Channel</th>
                <th>Terakhir aktif</th>
                <th>Strategi appeal</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr>
                  <td colSpan={11} style={{ color: "var(--sub)" }}>
                    Tidak ada data untuk filter ini.
                  </td>
                </tr>
              )}
              {rows.map((i) => (
                <tr key={i.id}>
                  <td>
                    <b>{i.nama}</b>
                  </td>
                  <td>
                    <span className={`badge ${i.category === "BATAL" ? "b-red" : "b-amber"}`}>
                      {i.category === "BATAL" ? "Batal" : "Pasif"}
                    </span>
                  </td>
                  <td>
                    {i.alasanLabel}
                    {i.alasanRaw && i.alasanRaw.toLowerCase() !== i.alasanLabel.toLowerCase() && (
                      <div className="rolehint" style={{ fontSize: 10.5 }}>
                        {i.alasanRaw}
                      </div>
                    )}
                  </td>
                  <td>{i.stageLabel}</td>
                  <td>
                    {i.cluster} · {i.type}
                  </td>
                  <td>{i.sales}</td>
                  <td>{i.koor}</td>
                  <td>{i.bendera}</td>
                  <td>{i.channel}</td>
                  <td style={{ whiteSpace: "nowrap" }}>{i.lastActivity}</td>
                  <td style={{ minWidth: 240, fontSize: 11.5, color: "var(--sub)" }}>{i.appeal}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
