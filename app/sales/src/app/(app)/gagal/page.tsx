import { getLostAnalysis, STALE_DAYS } from "@/lib/lost";
import { BarList } from "@/components/BarList";
import { LostClient } from "@/components/lost/LostClient";

export const dynamic = "force-dynamic";

export default async function GagalPage() {
  const a = await getLostAnalysis();

  // top appeal strategies for the dominant reasons
  const topAppeals = a.byAlasan.slice(0, 4).map((s) => {
    const sample = a.items.find((i) => i.alasanLabel === s.label);
    return { label: s.label, n: s.n, appeal: sample?.appeal ?? "" };
  });

  return (
    <>
      <div className="h2">
        Pipeline Gagal <span className="ft">Analisa Lost</span>
      </div>
      <div className="lead">
        Semua deal yang gagal — <b>batal</b> maupun <b>mandek</b> (tak bergerak &gt; {STALE_DAYS}{" "}
        hari) — dikumpulkan &amp; diolah agar terlihat pola &amp; profilnya, buat nyusun strategi
        biar next time mereka follow-through.
      </div>

      <div className="grid g4" style={{ marginBottom: 16 }}>
        <div className="card kpi tint k-coral">
          <div className="lab">Total Gagal</div>
          <div className="val">{a.total}</div>
          <div className="meta">batal + mandek</div>
        </div>
        <div className="card kpi">
          <div className="lab">Batal</div>
          <div className="val">{a.batal}</div>
          <div className="meta">dibatalkan</div>
        </div>
        <div className="card kpi">
          <div className="lab">Mandek</div>
          <div className="val">{a.mandek}</div>
          <div className="meta">&gt; {STALE_DAYS} hari diam</div>
        </div>
        <div className="card kpi">
          <div className="lab">Sebab teratas</div>
          <div className="val" style={{ fontSize: 17 }}>
            {a.topReason}
          </div>
        </div>
      </div>

      <div className="notice info">
        <span>🔎</span>
        <div>
          <b>Profil:</b> {a.profil} Dimensi di bawah (cluster, type, channel, bendera, cara bayar)
          jadi proksi &ldquo;demografik&rdquo; dari data yang ada — field demografik detail
          (umur/pekerjaan/domisili) bisa ditambah nanti untuk analisa lebih dalam.
        </div>
      </div>

      {/* appeal strategy */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="pad">
          <h3>🎯 Strategi appeal per sebab teratas</h3>
          <div className="grid g2">
            {topAppeals.map((t) => (
              <div key={t.label} className="notice" style={{ marginBottom: 0, alignItems: "flex-start" }}>
                <span>💡</span>
                <div>
                  <b>
                    {t.label} ({t.n})
                  </b>
                  <div style={{ marginTop: 3 }}>{t.appeal}</div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* breakdowns */}
      <div className="grid g2" style={{ marginBottom: 16 }}>
        <div className="card">
          <div className="pad">
            <h3>Sebab kegagalan</h3>
            <BarList data={a.byAlasan} color="var(--red)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Gagal di tahap</h3>
            <BarList data={a.byStage} color="var(--purple)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Cluster</h3>
            <BarList data={a.byCluster} color="var(--teal)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Type (top 8)</h3>
            <BarList data={a.byType} color="var(--teal)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Bendera (top 8)</h3>
            <BarList data={a.byBendera} color="var(--amber)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Koordinator</h3>
            <BarList data={a.byKoor} color="var(--amber)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Channel WI</h3>
            <BarList data={a.byChannel} color="var(--blue)" />
          </div>
        </div>
        <div className="card">
          <div className="pad">
            <h3>Per Cara Bayar</h3>
            <BarList data={a.byCaraBayar} color="var(--blue)" />
          </div>
        </div>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="pad">
          <h3>Tren batal per bulan</h3>
          <BarList data={a.byMonth} color="var(--red)" showPct={false} />
        </div>
      </div>

      <LostClient items={a.items} />
    </>
  );
}
