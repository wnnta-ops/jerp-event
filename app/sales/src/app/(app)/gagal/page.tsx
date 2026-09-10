import { getLostAnalysis, getLostProfiles, getAppealNotes, defaultTeamStrategy, STALE_DAYS } from "@/lib/lost";
import { hasAnthropicKey } from "@/lib/ai";
import { BarList } from "@/components/BarList";
import { LostClient } from "@/components/lost/LostClient";
import { LostProfile } from "@/components/lost/LostProfile";
import { AppealSlots, type ReasonSlot } from "@/components/lost/AppealSlots";
import { getSession } from "@/lib/auth";
import { formatIdDateLong } from "@/lib/wib";

export const dynamic = "force-dynamic";

export default async function GagalPage() {
  const session = (await getSession())!;
  const [a, profiles, notes] = await Promise.all([
    getLostAnalysis(),
    getLostProfiles(),
    getAppealNotes(),
  ]);
  const aiReady = hasAnthropicKey();

  // Build the 2-slot appeal data for the dominant reasons.
  const reasons: ReasonSlot[] = a.byAlasan.slice(0, 6).map((s) => {
    const sample = a.items.find((i) => i.alasanLabel === s.label);
    const key = sample?.alasanKey ?? s.label;
    const note = notes.get(key);
    const team = note?.teamStrategy ?? null;
    return {
      alasanKey: key,
      label: s.label,
      n: s.n,
      teamStrategy: team ?? defaultTeamStrategy(key),
      teamIsDefault: !team,
      aiText: note?.aiText ?? null,
      aiGeneratedAt: note?.aiGeneratedAt ? formatIdDateLong(note.aiGeneratedAt) : null,
      customers: a.items
        .filter((i) => i.alasanLabel === s.label)
        .map((i) => ({
          nama: i.nama,
          cluster: i.cluster,
          type: i.type,
          sales: i.sales,
          bendera: i.bendera,
          stage: i.stageLabel,
          when: i.lastActivity,
          category: i.category,
        })),
    };
  });

  // "Semua alasan" summary card — AI gives an executive summary + cross-segment ranking.
  const allNote = notes.get("ALL");
  const allSlot: ReasonSlot = {
    alasanKey: "ALL",
    label: "Semua alasan (ringkasan)",
    n: a.total,
    teamStrategy:
      allNote?.teamStrategy ??
      "Ringkasan lintas-alasan: prioritaskan segmen recoverable dengan volume terbesar dulu.",
    teamIsDefault: !allNote?.teamStrategy,
    aiText: allNote?.aiText ?? null,
    aiGeneratedAt: allNote?.aiGeneratedAt ? formatIdDateLong(allNote.aiGeneratedAt) : null,
    customers: [],
    isAll: true,
  };
  const reasonCards = [allSlot, ...reasons];

  const demoCoverage = a.items.filter(
    (i) => i.ageBand || i.pekerjaan || i.domisili || i.penghasilan,
  ).length;

  return (
    <>
      <div className="h2">
        Pipeline Gagal <span className="ft">Analisa Lost</span>
      </div>
      <div className="lead">
        Semua deal yang gagal — <b>batal</b> maupun <b>pasif</b> (tak bergerak &gt; {STALE_DAYS}{" "}
        hari) — dikumpulkan &amp; diolah agar terlihat pola &amp; profilnya, buat nyusun strategi
        biar next time mereka follow-through.
      </div>

      <div className="grid g4" style={{ marginBottom: 16 }}>
        <div className="card kpi tint k-coral">
          <div className="lab">Total Gagal</div>
          <div className="val">{a.total}</div>
          <div className="meta">batal + pasif</div>
        </div>
        <div className="card kpi">
          <div className="lab">Batal</div>
          <div className="val">{a.batal}</div>
          <div className="meta">dibatalkan</div>
        </div>
        <div className="card kpi">
          <div className="lab">Pasif</div>
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

      {/* detailed customer list (scrollable window) */}
      <div style={{ marginBottom: 20 }}>
        <h3 style={{ fontSize: 14, fontWeight: 700, marginBottom: 8 }}>
          📋 Daftar customer gagal
        </h3>
        <LostClient items={a.items} />
      </div>

      {/* interactive demographic profile — click a reason to see its profile */}
      <div style={{ marginBottom: 20 }}>
        <h3 style={{ fontSize: 14, fontWeight: 700, marginBottom: 4 }}>
          🧩 Profil customer per alasan
        </h3>
        <p className="lead" style={{ marginBottom: 12 }}>
          Klik alasan di bawah buat lihat profil customer-nya — sebaran cluster, tipe, channel,
          bank, cara bayar (+ demografik kalau udah tersambung). Kartu <b>📊 Semua alasan</b> =
          gabungan semua.
        </p>
        <LostProfile profiles={profiles} />
      </div>

      {/* appeal strategy — 2 slots: team playbook + AI analysis */}
      <div style={{ marginBottom: 8 }}>
        <h3 style={{ fontSize: 14, fontWeight: 700, marginBottom: 4 }}>
          🎯 Strategi appeal per sebab (+ ringkasan semua alasan)
        </h3>
        <p className="lead" style={{ marginBottom: 12 }}>
          Dua slot per alasan: <b>Strategi Tim</b> (ditulis manajemen) &amp; <b>Analisa AI</b> —
          AI ngasih <b>Diagnosa</b>, <b>Strategi</b>, dan <b>Prioritas</b> (recoverable vs lost
          cause) dari data agregat/anonim. Kartu <b>📊 Semua alasan</b> ngasih ringkasan &amp;
          ranking prioritas lintas-segmen.
          {!aiReady && (
            <>
              {" "}
              <span style={{ color: "var(--red)" }}>
                AI belum aktif — set <code>ANTHROPIC_API_KEY</code> di server untuk mengaktifkan slot AI.
              </span>
            </>
          )}
        </p>
        <div className="notice" style={{ marginBottom: 12 }}>
          <span>{demoCoverage > 0 ? "✅" : "🔌"}</span>
          <div>
            <b>Data demografik (umur/pekerjaan/domisili/penghasilan):</b>{" "}
            {demoCoverage > 0 ? (
              <>
                tersambung — <b>{demoCoverage}</b> dari {a.total} customer sudah punya data; AI
                otomatis pakai buat analisa lebih dalam.
              </>
            ) : (
              <>
                belum tersambung. Pathway siap: software eksternal tinggal push ke{" "}
                <code>POST /api/demographic/sync</code>. Sementara AI analisa dari proksi internal
                (cluster/tipe/channel/bank/cara bayar).
              </>
            )}
          </div>
        </div>
        <AppealSlots reasons={reasonCards} role={session.user.role} />
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="pad">
          <h3>Tren batal per bulan</h3>
          <BarList data={a.byMonth} color="var(--red)" showPct={false} />
        </div>
      </div>
    </>
  );
}
