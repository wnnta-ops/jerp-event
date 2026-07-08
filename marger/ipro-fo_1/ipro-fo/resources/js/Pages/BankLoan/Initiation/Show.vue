<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { ArrowLeft, ArrowRight, Check, Lock, ChevronRight, Download, User, Users, CalendarDays, MapPin, Megaphone, Wallet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    init: any;
    contractInfo: any;
    stageDefs: Record<string, string>;
    banks: Array<any>;
    bookings: Array<any>;
    // Matriks aspek Analisa dari sub-modul "Kategori Analisa Berkas" (fallback ke default bila kosong).
    analisaCfg?: { aspects: Array<{ key: string; no: number; label: string; scope: string; options: Array<{ level: string; desc: string; hint?: string | null; score?: number; bank?: number[] }> }>; banks: string[] };
}>();

const init0 = props.init;              // snapshot awal — hanya untuk seed nilai form
const s = init0.stages || {};
const a = computed(() => props.init);  // SELALU props terbaru → tabel & OCR ikut update saat verifikasi

const stageList = computed(() => Object.entries(props.stageDefs).map(([key, label], idx) => ({ key, label: label as string, idx })));
// Label ringkas utk stepper horizontal (alur modul, spt Account Payable).
const stepShort: Record<string, string> = {
    kuitansi: 'TTS', sprs: 'SPRS', bi_checking: 'BI Checking', collect_berkas: 'Berkas',
    analisa: 'Analisa', profiling_berkas: 'Prof. Berkas', profiling: 'Profiling', kpr_application: 'Proses KPR',
};
const activeIdx = computed(() => Math.max(0, Math.min(8, (a.value.current_stage ?? 1)) - 1));
const openStage = ref(activeIdx.value);
watch(activeIdx, (v) => { openStage.value = v; }); // auto-buka tahap aktif setelah tahap maju

function done(key: string) { return !!(a.value.stages?.[key]?.done); }
function locked(idx: number) { return idx > activeIdx.value; }
function toggle(idx: number) { if (!locked(idx)) openStage.value = openStage.value === idx ? -1 : idx; }
// Tahap dianggap "selesai" (boleh Simpan & Lanjut) bila field utamanya terisi — cegah maju tanpa data.
function stageComplete(key: string): boolean {
    if (key === 'sprs') {
        if (!f.sprs.marital_status) return false; // wajib pilih status pernikahan dulu
        const base = f.sprs.ktp_pemohon_received && f.sprs.npwp_pemohon_received;
        return f.sprs.marital_status === 'sudah_menikah' ? base && f.sprs.ktp_pasangan_received && f.sprs.npwp_pasangan_received : base;
    }
    if (key === 'bi_checking') return biAllFilled.value && f.bi_checking.permintaan === 'lanjut_spr';
    if (key === 'analisa') return !!f.analisa.result && analisaAllScored.value;
    if (key === 'profiling_berkas') return !!f.profiling_berkas.tanggal_lahir && !!f.profiling_berkas.domisili_pekerjaan && !!f.profiling_berkas.jabatan;
    if (key === 'profiling') return decidedBanks.value.length >= 1;
    return true;
}

// Laporan durasi proses: timestamp tiap tahap selesai + selisih dari tahap sebelumnya (& dari inisiasi dibuat).
const prosesLaporan = computed(() => {
    const stages = a.value.stages ?? {};
    const rows: Array<{ key: string; label: string; at: string; durMs: number | null }> = [];
    const start = a.value.created_at ? new Date(a.value.created_at).getTime() : null;
    let prev: number | null = start;
    for (const key of Object.keys(props.stageDefs)) {
        const stg = (stages as any)[key];
        if (!stg?.done || !stg?.at) continue;
        const t = new Date(stg.at).getTime();
        rows.push({ key, label: (props.stageDefs as any)[key], at: stg.at, durMs: prev !== null ? (t - prev) : null });
        prev = t;
    }
    const totalMs = (rows.length && start !== null) ? (new Date(rows[rows.length - 1].at).getTime() - start) : null;
    return { rows, totalMs, start: a.value.created_at };
});
function fmtDuration(ms: number | null): string {
    if (ms === null || ms < 0) return '—';
    const s = Math.floor(ms / 1000);
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d} hari ${h} jam`;
    if (h > 0) return `${h} jam ${m} mnt`;
    if (m > 0) return `${m} mnt`;
    return `${s} dtk`;
}

/* Form state tiap tahap (terisi dari data tersimpan bila ada). */
const f = reactive({
    // R2 — SPRS: konfirmasi penerimaan 4 dokumen (Pemohon + Pasangan). OCR di menu "Cek Scan KTP & NPWP".
    // Info SPRS (No.Kontrak…PPN) auto-tampil dari Marketing & Sales (prop contractInfo) — tidak diinput di sini.
    // *_received_at: stempel jam & tanggal saat checklist masing-masing dokumen dicentang.
    // marital_status: 'belum_menikah' (2 dok) | 'sudah_menikah' (4 dok). Data lama (semua dok pasangan tercentang) → default 'sudah_menikah'.
    sprs:        { marital_status: s.sprs?.marital_status ?? ((s.sprs?.ktp_pasangan_received || s.sprs?.npwp_pasangan_received) ? 'sudah_menikah' : ''),
                   ktp_pemohon_received: !!s.sprs?.ktp_pemohon_received, npwp_pemohon_received: !!s.sprs?.npwp_pemohon_received,
                   ktp_pasangan_received: !!s.sprs?.ktp_pasangan_received, npwp_pasangan_received: !!s.sprs?.npwp_pasangan_received,
                   ktp_pemohon_received_at: s.sprs?.ktp_pemohon_received_at ?? null, npwp_pemohon_received_at: s.sprs?.npwp_pemohon_received_at ?? null,
                   ktp_pasangan_received_at: s.sprs?.ktp_pasangan_received_at ?? null, npwp_pasangan_received_at: s.sprs?.npwp_pasangan_received_at ?? null },
    bi_checking: { result: s.bi_checking?.result ?? '', result_pasangan: s.bi_checking?.result_pasangan ?? '', permintaan: s.bi_checking?.permintaan ?? '', notes: s.bi_checking?.notes ?? '' },
    collect_berkas: {
        category: s.collect_berkas?.category ?? '',
        items: Object.fromEntries(Object.entries(s.collect_berkas?.items ?? {}).map(([k, v]: [string, any]) => [k, { status: v?.status ?? '', note: v?.note ?? '', status_at: v?.status_at ?? null }])) as Record<string, { status: string; note: string; status_at: string | null }>,
        notes: s.collect_berkas?.notes ?? '',
    },
    analisa: {
        result: s.analisa?.result ?? '',
        // R5 — skor indikator per-aspek (High/Medium/Low) sesuai matriks "Indikator Analisa BI Checking & Berkas".
        indikator_items: { ...(s.analisa?.indikator_items ?? {}) } as Record<string, 'high' | 'medium' | 'low'>,
        // Indikator keseluruhan diturunkan otomatis dari rata-rata skor aspek (dipakai di Profiling).
        indikator: s.analisa?.indikator ?? '',
        notes: s.analisa?.notes ?? '',
    },
    // R5 — Profiling: rekomendasi bank (customer ×2, analisa ×2) + keputusan 2 bank + SPR terbit.
    profiling: {
        kategori: s.profiling?.kategori ?? '',
        bank_customer_1: s.profiling?.bank_customer_1 ?? '',
        bank_customer_2: s.profiling?.bank_customer_2 ?? '',
        bank_analisa_1: s.profiling?.bank_analisa_1 ?? '',
        bank_analisa_2: s.profiling?.bank_analisa_2 ?? '',
        decision_1: s.profiling?.decision_1 ?? '',
        decision_1_spr: !!s.profiling?.decision_1_spr,
        decision_2: s.profiling?.decision_2 ?? '',
        decision_2_spr: !!s.profiling?.decision_2_spr,
        notes: s.profiling?.notes ?? '',
    },
    // Profiling Berkas (tahap baru): input tanggal lahir (→ umur), domisili pekerjaan, jabatan.
    // Prefill tanggal lahir dari Customer.dob; jabatan default dari kategori Collect Berkas.
    profiling_berkas: {
        tanggal_lahir: (s.profiling_berkas?.tanggal_lahir ?? init0.customer?.dob ?? '').slice(0, 10),
        domisili_pekerjaan: s.profiling_berkas?.domisili_pekerjaan ?? '',
        jabatan: s.profiling_berkas?.jabatan ?? (s.collect_berkas?.category === 'wirausaha' ? 'Wiraswasta' : (s.collect_berkas?.category === 'karyawan' ? 'Karyawan Swasta' : '')),
    },
    kpr_application: { booking_id: init0.booking_id ?? '' },
});

// R4/R5 — indikator analisa muncul di Profiling sebagai keterangan HIGH/MEDIUM/LOW.
const indikatorLabel: Record<string, string> = { high: 'HIGH', medium: 'MEDIUM', low: 'LOW' };

/* ── Analisa BI Checking & Berkas: matriks indikator per aspek (sumber: file "Indikator Analisa BI Checking & Berkas.xlsx").
   Aspek "all" berlaku utk semua; "karyawan"/"wirausaha" muncul sesuai kategori pemohon (dari Collect Berkas). */
type AspekLevel = 'high' | 'medium' | 'low';
const ANALISA_ASPEK_FALLBACK: Array<{ key: string; no: number; label: string; scope: 'all' | 'karyawan' | 'wirausaha'; options: Array<{ level: AspekLevel; desc: string; hint?: string }> }> = [
    { key: 'bi_pemohon', no: 1, label: 'Hasil BI Checking KTP & NPWP Pemohon', scope: 'all',
      options: [{ level: 'high', desc: 'Kol 1' }, { level: 'medium', desc: 'Kol 2' }, { level: 'low', desc: 'Kol 3–5' }] },
    { key: 'bi_pasangan', no: 2, label: 'Hasil BI Checking KTP & NPWP Pasangan', scope: 'all',
      options: [{ level: 'high', desc: 'Kol 1' }, { level: 'medium', desc: 'Kol 2' }, { level: 'low', desc: 'Kol 3–5' }] },
    { key: 'pref_customer', no: 3, label: 'Karakteristik Customer', scope: 'all',
      options: [{ level: 'high', desc: 'Customer kooperatif, dokumen lengkap, respon cepat' }, { level: 'medium', desc: 'Masih mencari perbandingan bank, kelengkapan dokumen masih kurang' }, { level: 'low', desc: 'Tidak kooperatif, respon lambat, menunda proses, belum menentukan cara bayar' }] },
    { key: 'pref_developer', no: 4, label: 'Preferensi Developer', scope: 'all',
      options: [{ level: 'high', desc: 'Platinum' }, { level: 'medium', desc: 'Gold / Tier 3' }, { level: 'low', desc: 'Bronze' }] },
    { key: 'angsuran', no: 5, label: 'Pertimbangan Angsuran per Bulan', scope: 'all',
      options: [{ level: 'high', desc: 'Rp 3.000.000', hint: 'Angsuran ringan' }, { level: 'medium', desc: 'Rp 4.000.000', hint: 'Angsuran sedang' }, { level: 'low', desc: 'Rp 5.000.000', hint: 'Angsuran berat' }] },
    { key: 'status_kerja', no: 6, label: 'Status Karyawan / Pekerjaan', scope: 'karyawan',
      options: [{ level: 'high', desc: 'Karyawan tetap, masa kerja > 3 tahun di perusahaan Tbk' }, { level: 'medium', desc: 'Karyawan tetap masa kerja < 3 tahun, P3K' }, { level: 'low', desc: 'Karyawan kontrak/outsourcing, masa kerja < 1 tahun' }] },
    { key: 'slip_gaji', no: 7, label: 'Slip Gaji & Rekening Koran 3 Bulan (RPC)', scope: 'karyawan',
      options: [{ level: 'high', desc: 'Rp 10.000.000', hint: 'Penghasilan tinggi' }, { level: 'medium', desc: 'Rp 7.000.000', hint: 'Penghasilan menengah' }, { level: 'low', desc: 'Rp 5.000.000', hint: 'Penghasilan minimum' }] },
    { key: 'tt_gaji_tunai', no: 8, label: 'Tanda Terima Gaji Tunai 3 Bulan (RPC Tambahan)', scope: 'karyawan',
      options: [{ level: 'high', desc: 'Rp 5.000.000', hint: 'Penghasilan tinggi' }, { level: 'medium', desc: 'Rp 3.500.000', hint: 'Penghasilan menengah' }, { level: 'low', desc: 'Rp 2.500.000', hint: 'Penghasilan minimum' }] },
    { key: 'lap_keuangan', no: 9, label: 'Laporan Keuangan & Rekening Koran 6 Bulan (RPC)', scope: 'wirausaha',
      options: [{ level: 'high', desc: 'Rp 50.000.000', hint: 'Omzet tinggi' }, { level: 'medium', desc: 'Rp 35.000.000', hint: 'Omzet menengah' }, { level: 'low', desc: 'Rp 25.000.000', hint: 'Omzet minimum' }] },
    { key: 'foto_usaha', no: 10, label: 'Foto, Denah Lokasi & Shareloc Tempat Usaha', scope: 'wirausaha',
      options: [{ level: 'high', desc: 'Foto usaha lengkap & jelas, lokasi jelas, shareloc valid, usaha mudah diverifikasi & aktif' }, { level: 'medium', desc: 'Foto usaha lengkap, lokasi ditemukan namun ada ketidaksesuaian (pemilik, lama usaha, dll)' }, { level: 'low', desc: 'Foto usaha tidak lengkap, lokasi & shareloc tidak valid, usaha tidak dapat diverifikasi' }] },
];
// Sumber aspek: konfigurasi DB (Kategori Analisa Berkas) bila ada, else fallback hardcoded di atas.
const cfgAspects = computed<Array<any>>(() => (props.analisaCfg?.aspects?.length ? props.analisaCfg.aspects : ANALISA_ASPEK_FALLBACK) as Array<any>);
// Skor indikator per aspek×level (bebas diubah, default 3/2/1) — untuk preview indikator sisi klien.
const scoreMap = computed<Record<string, Record<string, number>>>(() => {
    const m: Record<string, Record<string, number>> = {};
    const def: Record<string, number> = { high: 3, medium: 2, low: 1 };
    for (const x of cfgAspects.value) {
        m[x.key] = {};
        for (const o of x.options) m[x.key][o.level] = o.score ?? def[o.level] ?? 0;
    }
    return m;
});
// Skor rekomendasi bank per aspek×level → [BTN,BSN,BRI,BNI,MANDIRI] (fallback matriks default).
const bankMap = computed<Record<string, Record<string, number[]>>>(() => {
    const m: Record<string, Record<string, number[]>> = {};
    for (const x of cfgAspects.value) {
        const has = (x.options || []).some((o: any) => Array.isArray(o.bank));
        if (has) { m[x.key] = {}; for (const o of x.options) m[x.key][o.level] = o.bank ?? []; }
        else if (BANK_SCORE[x.key]) m[x.key] = BANK_SCORE[x.key] as any;
    }
    return m;
});
const bankList = computed<string[]>(() => (props.analisaCfg?.banks?.length ? props.analisaCfg.banks : BANK_REKOM));
// Kategori pemohon diambil dari tahap Collect Berkas (karyawan / wirausaha).
const analisaCategory = computed<string>(() => a.value.stages?.collect_berkas?.category ?? '');
// Aspek yang tampil = umum + spesifik kategori.
const analisaAspek = computed(() => cfgAspects.value.filter((x) => x.scope === 'all' || x.scope === analisaCategory.value));
const analisaTotal = computed(() => analisaAspek.value.length);
const analisaScored = computed(() => analisaAspek.value.filter((x) => !!f.analisa.indikator_items[x.key]).length);
const analisaAllScored = computed(() => analisaTotal.value > 0 && analisaScored.value === analisaTotal.value);
// Aspek Analisa yang diisi OTOMATIS dari hasil BI Checking (tidak bisa diklik manual).
const AUTO_ASPEKS = ['bi_pemohon', 'bi_pasangan'];
function isAutoAspek(key: string) { return AUTO_ASPEKS.includes(key); }
function setAspek(key: string, level: AspekLevel) { if (isAutoAspek(key)) return; f.analisa.indikator_items[key] = level; }
function lvlCount(level: AspekLevel) { return analisaAspek.value.filter((x) => f.analisa.indikator_items[x.key] === level).length; }
function lvlActiveClass(level: string) {
    return level === 'high' ? 'border-success-500 bg-success-500 text-white'
        : level === 'medium' ? 'border-warning-500 bg-warning-500 text-white'
        : 'border-danger-500 bg-danger-500 text-white';
}
// Indikator keseluruhan = rata-rata skor tiap aspek dinormalisasi ke [0,1] (skor bebas diubah) → HIGH ≥0.75, MEDIUM ≥0.25.
const indikatorDerived = computed<string>(() => {
    const vals: number[] = [];
    for (const x of analisaAspek.value) {
        const lvl = f.analisa.indikator_items[x.key];
        if (!lvl) continue;
        const sc = scoreMap.value[x.key] ?? { high: 3, medium: 2, low: 1 };
        const arr = Object.values(sc);
        const min = Math.min(...arr), max = Math.max(...arr);
        vals.push(max > min ? ((sc[lvl] ?? min) - min) / (max - min) : 0.5);
    }
    if (!vals.length) return '';
    const avg = vals.reduce((s, v) => s + v, 0) / vals.length;
    return avg >= 0.75 ? 'high' : avg >= 0.25 ? 'medium' : 'low';
});
// Sinkronkan indikator turunan ke form agar tersimpan & terbaca di Profiling.
watch(indikatorDerived, (v) => { f.analisa.indikator = v; }, { immediate: true });
// Aspek bernilai Low → ditonjolkan di ringkasan agar tidak "tersamar" oleh rata-rata.
const lowAspek = computed(() => analisaAspek.value.filter((x) => f.analisa.indikator_items[x.key] === 'low').map((x) => x.label));
// Peringatan bila keputusan analis berbeda dari indikator otomatis (dorong pencatatan alasan).
const resultContradiction = computed<string>(() => {
    if (!f.analisa.result || !indikatorDerived.value) return '';
    if (f.analisa.result === 'layak' && indikatorDerived.value === 'low') return 'Keputusan LAYAK berbeda dari indikator otomatis (LOW).';
    if (f.analisa.result === 'tidak_layak' && indikatorDerived.value === 'high') return 'Keputusan TIDAK LAYAK berbeda dari indikator otomatis (HIGH).';
    return '';
});
// Bar tepi-kiri (traffic light) untuk opsi belum terpilih — tetap terbaca saat menumpuk di mobile.
function lvlEdgeClass(level: string) {
    return level === 'high' ? 'border-l-success-400' : level === 'medium' ? 'border-l-warning-400' : 'border-l-danger-400';
}
// Pil label level (High/Medium/Low) — solid berwarna saat belum terpilih, transparan saat terpilih.
function lvlPillClass(level: string, selected: boolean) {
    if (selected) return 'bg-white/20 text-white';
    return level === 'high' ? 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300'
        : level === 'medium' ? 'bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300'
        : 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300';
}
// R5 — opsi rekomendasi bank (daftar tetap sesuai permintaan).
const BANK_REKOM = ['BTN', 'BSN', 'BRI', 'BNI', 'MANDIRI'];
const BANK_REKOM_CUSTOMER = [...BANK_REKOM, 'Tanpa Preferensi'];

// ── Rekomendasi Bank (Profiling) dihitung OTOMATIS dari skor tiap aspek Analisa.
//    Matriks skor aspek×level → [BTN, BSN, BRI, BNI, MANDIRI] (file "Profiling Customer - Rekomendasi Bank.xlsx").
const BANK_SCORE: Record<string, Record<AspekLevel, number[]>> = {
    bi_pemohon:     { high: [1, 1, 1, 1, 1], medium: [2, 2, 0, 0, 0], low: [0, 3, 0, 0, 0] },
    bi_pasangan:    { high: [1, 1, 0, 1, 1], medium: [2, 2, 0, 0, 0], low: [0, 3, 0, 0, 0] },
    pref_customer:  { high: [1, 1, 1, 1, 1], medium: [2, 2, 2, 2, 0], low: [0, 0, 0, 0, 0] },
    pref_developer: { high: [3, 3, 0, 0, 0], medium: [0, 0, 2, 2, 2], low: [0, 0, 0, 0, 0] },
    angsuran:       { high: [1, 1, 1, 0, 0], medium: [2, 2, 1, 1, 1], low: [0, 0, 0, 0, 0] },
    status_kerja:   { high: [3, 3, 3, 3, 3], medium: [2, 2, 2, 0, 0], low: [1, 1, 0, 0, 0] },
    slip_gaji:      { high: [1, 1, 1, 1, 1], medium: [1, 2, 1, 0, 0], low: [0, 0, 0, 0, 0] },
    tt_gaji_tunai:  { high: [1, 1, 1, 1, 1], medium: [1, 1, 1, 1, 1], low: [0, 0, 0, 0, 0] },
    lap_keuangan:   { high: [1, 1, 1, 1, 1], medium: [2, 2, 0, 0, 0], low: [0, 0, 0, 0, 0] },
    foto_usaha:     { high: [1, 1, 1, 1, 1], medium: [2, 2, 2, 1, 1], low: [0, 0, 0, 0, 0] },
};
// Total skor tiap bank dari aspek yang dinilai → urut skor desc; teratas = Rekom 1, ke-2 = Rekom 2.
function computeBankRekom(items: Record<string, string>) {
    const banks = bankList.value;
    const totals = banks.map(() => 0);
    for (const [key, level] of Object.entries(items || {})) {
        const row = bankMap.value[key]?.[level];
        if (row) row.forEach((v, i) => { if (i < totals.length) totals[i] += v; });
    }
    return banks.map((name, i) => ({ name, score: totals[i] }))
        .sort((x, y) => y.score - x.score)
        .map((b, idx) => ({ ...b, rekom: idx === 0 ? 1 : idx === 1 ? 2 : 0 }));
}
const analisaItemsSaved = computed<Record<string, string>>(() => a.value.stages?.analisa?.indikator_items ?? {});
// Live (preview di tahap Analisa): HANYA aspek yang berlaku utk kategori saat ini → cocok dgn hasil server
// (server memangkas key aspek "basi" saat simpan, mis. kategori berganti setelah menilai).
const analisaItemsLive = computed<Record<string, string>>(() => Object.fromEntries(
    analisaAspek.value.map((x) => [x.key, f.analisa.indikator_items[x.key]] as const).filter(([, v]) => !!v),
) as Record<string, string>);
const bankRekom = computed(() => computeBankRekom(analisaItemsSaved.value));       // Profiling (data tersimpan)
const bankRekomLive = computed(() => computeBankRekom(analisaItemsLive.value));     // preview di tahap Analisa
const bankRekom1 = computed(() => bankRekom.value.find((b) => b.rekom === 1) ?? null);
const bankRekom2 = computed(() => bankRekom.value.find((b) => b.rekom === 2) ?? null);
// Panjang bar "jujur": skor maksimum teoretis = jumlah skor tertinggi tiap aspek yang dinilai (bukan skor bank teratas).
const bankTheoreticalMax = computed(() => {
    let m = 0;
    for (const key of Object.keys(analisaItemsSaved.value)) {
        const lv = bankMap.value[key];
        if (lv) m += Math.max(...Object.values(lv).flat());
    }
    return Math.max(1, m);
});
// Indeks tahap Analisa (untuk tombol lompat dari Profiling).
const analisaStageIdx = computed(() => stageList.value.findIndex((s) => s.key === 'analisa'));
// Sinkronkan 2 rekomendasi teratas ke form Profiling agar ikut tersimpan.
watch(bankRekom, (v) => {
    f.profiling.bank_analisa_1 = v.find((b) => b.rekom === 1)?.name ?? '';
    f.profiling.bank_analisa_2 = v.find((b) => b.rekom === 2)?.name ?? '';
}, { immediate: true });
// Bank yang DIPUTUS dilanjutkan (≤2) — sumber draft di stage Proses KPR.
const decidedBanks = computed(() => {
    const out: Array<{ bank_partner_id: any; spr_issued: boolean }> = [];
    for (const i of [1, 2] as const) {
        const bid = (f.profiling as any)['decision_' + i];
        if (bid) out.push({ bank_partner_id: bid, spr_issued: !!(f.profiling as any)['decision_' + i + '_spr'] });
    }
    return out;
});
function bankName(id: any): string { return props.banks.find((b: any) => String(b.id) === String(id))?.name ?? '—'; }
// Stage 7 — state per bank yang diputus (checklist lanjut + plafon).
const stage7 = reactive<{ rows: Record<string, { checked: boolean; plafon: any }> }>({ rows: {} });
watch(decidedBanks, (banks) => {
    for (const b of banks) {
        const k = String(b.bank_partner_id);
        if (!stage7.rows[k]) stage7.rows[k] = { checked: true, plafon: init0.plafon_requested || '' };
    }
}, { immediate: true });
function submitKprApp() {
    const banks = decidedBanks.value
        .filter((b) => stage7.rows[String(b.bank_partner_id)]?.checked)
        .map((b) => ({ bank_partner_id: b.bank_partner_id, plafon_requested: Number(stage7.rows[String(b.bank_partner_id)].plafon) || 0, spr_issued: b.spr_issued }));
    save('kpr_application', { booking_id: f.kpr_application.booking_id, banks });
}
const kprAppValid = computed(() => {
    if (!f.kpr_application.booking_id) return false;
    const chosen = decidedBanks.value.filter((b) => stage7.rows[String(b.bank_partner_id)]?.checked);
    return chosen.length >= 1 && chosen.every((b) => Number(stage7.rows[String(b.bank_partner_id)].plafon) > 0);
});

// Collect Berkas — dua kategori dgn daftar berkas masing-masing. `optional` = tidak wajib (tak menghalangi lanjut).
type BerkasItem = { key: string; label: string; optional?: boolean };
const berkasCategories: Record<string, { label: string; items: BerkasItem[] }> = {
    karyawan: { label: 'Karyawan / Fixed Income', items: [
        { key: 'ktp_pemohon', label: 'KTP Pemohon' },
        { key: 'ktp_pasangan', label: 'KTP Pasangan' },
        { key: 'kk', label: 'Kartu Keluarga (KK)' },
        { key: 'npwp_pemohon', label: 'NPWP Pemohon' },
        { key: 'npwp_pasangan', label: 'NPWP Pasangan' },
        { key: 'surat_nikah', label: 'Surat Nikah/SKBM/SKBML' },
        { key: 'sk_kerja', label: 'SK Kerja' },
        { key: 'slip_gaji_3bln', label: 'Slip Gaji 3 Bulan' },
        { key: 'rekening_koran_3bln', label: 'Rekening Koran 3 Bulan' },
        { key: 'tanda_terima_gaji_3bln', label: 'Tanda Terima Gaji Tunai 3 Bulan (Apabila Ada)', optional: true },
        { key: 'spt_tahunan', label: 'SPT Tahunan (Apabila Ada)', optional: true },
    ]},
    wirausaha: { label: 'Wirausaha', items: [
        { key: 'ktp_pemohon', label: 'KTP Pemohon' },
        { key: 'ktp_pasangan', label: 'KTP Pasangan' },
        { key: 'kk', label: 'Kartu Keluarga (KK)' },
        { key: 'npwp_pemohon', label: 'NPWP Pemohon' },
        { key: 'npwp_pasangan', label: 'NPWP Pasangan' },
        { key: 'surat_nikah', label: 'Surat Nikah/SKBM/SKBML' },
        { key: 'laporan_keuangan_6bln', label: 'Laporan Keuangan 6 Bulan' },
        { key: 'rekening_koran_usaha_6bln', label: 'Rekening Koran Usaha 6 Bulan' },
        { key: 'foto_denah_shareloc', label: 'Foto-Foto, Denah Lokasi Manual, Shareloc Tempat Usaha' },
        { key: 'spt_tahunan', label: 'SPT Tahunan (Apabila Ada)', optional: true },
    ]},
};
const currentItems = computed<BerkasItem[]>(() => berkasCategories[f.collect_berkas.category]?.items ?? []);
// Kunci penyimpanan di-namespace per kategori (karyawan__ / wirausaha__) agar Karyawan & Wirausaha
// TIDAK saling memengaruhi meski sebagian key dasar sama (ktp_pemohon, kk, npwp_*, dst).
function bkey(base: string): string { return f.collect_berkas.category + '__' + base; }
function setCategory(ck: string) {
    f.collect_berkas.category = ck;
    for (const it of (berkasCategories[ck]?.items ?? [])) {
        const k = ck + '__' + it.key;
        if (!f.collect_berkas.items[k]) f.collect_berkas.items[k] = { status: '', note: '', status_at: null };
    }
}
// Tandai status berkas + stempel jam & tanggal saat tombol Lengkap/Tidak Lengkap diklik (per item, per kategori).
function setBerkasStatus(key: string, status: 'lengkap' | 'tidak_lengkap') {
    const it = f.collect_berkas.items[key] ?? (f.collect_berkas.items[key] = { status: '', note: '', status_at: null });
    it.status = status;
    it.status_at = new Date().toISOString();
}
// File & waktu unggah dibaca dari props server (reaktif); status/catatan dari form (editable).
function itemFile(key: string): any { return a.value.stages?.collect_berkas?.items?.[key] ?? null; }
const allBerkasComplete = computed(() => {
    const cat = f.collect_berkas.category;
    if (!cat) return false;
    const req = (berkasCategories[cat]?.items ?? []).filter((it) => !it.optional);
    return req.length > 0 && req.every((it) => f.collect_berkas.items[cat + '__' + it.key]?.status === 'lengkap');
});
if (f.collect_berkas.category) setCategory(f.collect_berkas.category); // seed entri item utk kategori tersimpan


// Upload berkas — 1 input file tersembunyi dipakai bergiliran per item.
const fileInput = ref<HTMLInputElement | null>(null);
const pendingKey = ref('');
const uploadingKey = ref('');
function triggerUpload(key: string) { pendingKey.value = key; fileInput.value?.click(); }
function onFileChosen(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || !pendingKey.value) return;
    if (file.size > 10 * 1024 * 1024) { alert('Ukuran file melebihi 10 MB.'); return; }
    const key = pendingKey.value;
    uploadingKey.value = key;
    router.post(route('kpr.initiation.berkas-upload', a.value.id), { item: key, file }, {
        forceFormData: true, preserveScroll: true, preserveState: true,
        onFinish: () => { uploadingKey.value = ''; pendingKey.value = ''; },
    });
}
function fmtDateTime(iso: string | null | undefined) {
    return iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
}
const bankOptions = computed(() => props.banks.map((b) => ({ id: b.id, code: b.code, name: b.name })));
const bookingOptions = computed(() => props.bookings.map((b) => ({ id: b.id, label: `${b.booking_number} · ${b.customer?.full_name ?? '—'}` })));

// SPRS: stempel jam & tanggal saat dokumen dicentang (kosongkan saat di-uncheck).
function markSprsDoc(key: 'ktp_pemohon' | 'npwp_pemohon' | 'ktp_pasangan' | 'npwp_pasangan') {
    const checked = (f.sprs as any)[key + '_received'];
    (f.sprs as any)[key + '_received_at'] = checked ? new Date().toISOString() : null;
}
// Status pernikahan menentukan jumlah dokumen wajib (belum: 2, sudah: 4). Pindah ke "belum" → bersihkan data pasangan.
function setSprsMarital(status: 'belum_menikah' | 'sudah_menikah') {
    f.sprs.marital_status = status;
    if (status === 'belum_menikah') {
        f.sprs.ktp_pasangan_received = false;
        f.sprs.npwp_pasangan_received = false;
        f.sprs.ktp_pasangan_received_at = null;
        f.sprs.npwp_pasangan_received_at = null;
    }
}
// Petunjuk status pernikahan dari data customer (tidak mengunci pilihan operator).
const custMaritalLabel = computed<string>(() => (({ menikah: 'Menikah', belum_menikah: 'Belum Menikah', cerai: 'Cerai', lain_lain: 'Lain-lain' } as Record<string, string>)[a.value.customer?.marital_status ?? ''] ?? ''));
// Pesan pemandu tombol Simpan sesuai status pernikahan & kelengkapan checklist.
const sprsHint = computed<string>(() => {
    if (!f.sprs.marital_status) return 'Pilih status pernikahan pemohon (Belum / Sudah Menikah) terlebih dahulu.';
    if (f.sprs.marital_status === 'belum_menikah') return 'Centang KTP & NPWP Pemohon sudah diterima untuk lanjut ke BI Checking.';
    return 'Centang keempat dokumen (KTP/NPWP Pemohon & Pasangan) sudah diterima untuk lanjut ke BI Checking.';
});

// ── Profiling Berkas: data turunan yang HANYA ditampilkan (umur / domisili / sumber / penghasilan) ──
function ageFromDob(dob: string): { years: number; months: number } | null {
    if (!dob) return null;
    const b = new Date(dob); if (isNaN(b.getTime())) return null;
    const n = new Date();
    let months = (n.getFullYear() - b.getFullYear()) * 12 + (n.getMonth() - b.getMonth());
    if (n.getDate() < b.getDate()) months--;
    if (months < 0) return null;
    return { years: Math.floor(months / 12), months: months % 12 };
}
const pbUmur = computed(() => ageFromDob(f.profiling_berkas.tanggal_lahir));
// Domisili/tempat tinggal — dirakit dari alamat Customer (diisi saat NUP/pendaftaran).
const pbDomisili = computed<string>(() => {
    const c: any = a.value.customer || {};
    return [c.address_line, c.address_rt_rw ? 'RT/RW ' + c.address_rt_rw : '', c.kelurahan ? 'Kel. ' + c.kelurahan : '', c.kecamatan ? 'Kec. ' + c.kecamatan : '', c.city, c.province, c.postal_code].filter(Boolean).join(', ');
});
// Sumber informasi — pilihan "Sumber Data" pada NUP (tersimpan di Customer.data_source).
const pbSumber = computed<string>(() => a.value.customer?.data_source?.name ?? '');
// Penghasilan (RPC) dari tahap Analisa: slip_gaji (karyawan) / lap_keuangan (wirausaha) + tt_gaji_tunai tambahan.
const pbPenghasilan = computed<Array<{ label: string; level: string; amount: string; hint: string }>>(() => {
    const cat = a.value.stages?.collect_berkas?.category ?? '';
    const items: Record<string, string> = a.value.stages?.analisa?.indikator_items ?? {};
    const keys = cat === 'wirausaha' ? ['lap_keuangan'] : ['slip_gaji', 'tt_gaji_tunai'];
    const rows: Array<{ label: string; level: string; amount: string; hint: string }> = [];
    for (const k of keys) {
        const lvl = items[k];
        if (!lvl) continue;
        const asp: any = cfgAspects.value.find((x: any) => x.key === k);
        const opt: any = asp?.options?.find((o: any) => o.level === lvl);
        if (asp && opt) rows.push({ label: asp.label, level: lvl, amount: opt.desc, hint: opt.hint ?? '' });
    }
    return rows;
});
const pbMonthlyIncome = computed<number>(() => Number(a.value.customer?.monthly_income ?? 0));
const JABATAN_OPTS = ['PNS', 'Karyawan Swasta', 'Wiraswasta'];

const saving = ref(false);
function save(key: string, data: Record<string, any> = {}) {
    saving.value = true;
    router.post(route('kpr.initiation.stage', a.value.id), { stage: key, data }, {
        preserveScroll: true,
        onFinish: () => (saving.value = false),
    });
}

// Hasil BI: "bersih" = Clear/Kol 1; "bermasalah" = Kol 2–5.
const BI_CLEAN = ['clear', 'kol1'];
const BI_BAD = ['kol2', 'kol3', 'kol4', 'kol5'];
// Pasangan hanya relevan bila pemohon "Sudah Menikah" (dari SPRS). Belum menikah → BI pasangan tak diperlukan.
const biNeedPasangan = computed(() => a.value.stages?.sprs?.marital_status !== 'belum_menikah');
const biPemohonFilled = computed(() => !!f.bi_checking.result);
const biPasanganFilled = computed(() => !biNeedPasangan.value || !!f.bi_checking.result_pasangan);
const biAllFilled = computed(() => biPemohonFilled.value && biPasanganFilled.value);
// Semua hasil bersih (Clear/Kol 1) → customer mundur = pembatalan sepihak. Ada Kol 2–5 → reject BI Checking.
const biBothClean = computed(() => BI_CLEAN.includes(f.bi_checking.result) && (!biNeedPasangan.value || BI_CLEAN.includes(f.bi_checking.result_pasangan)));
const biAnyBad = computed(() => BI_BAD.includes(f.bi_checking.result) || (biNeedPasangan.value && BI_BAD.includes(f.bi_checking.result_pasangan)));
const isMundur = computed(() => f.bi_checking.permintaan === 'mundur');
// Reject Batal Sepihak: customer mundur padahal BI bersih (Clear/Kol 1 semua).
const canBatalSepihak = computed(() => isMundur.value && biAllFilled.value && biBothClean.value);
// Reject BI Checking: customer mundur & ada hasil bermasalah (Kol 2–5).
const canRejectBi = computed(() => isMundur.value && biAllFilled.value && biAnyBad.value);

/* Tolak inisiasi pada tahap BI Checking → status 'rejected' (muncul di Database KPR → Reject BI Checking).
   type: 'bi_checking' (hasil Kol 2–5) | 'batal_sepihak' (BI bersih tapi customer mundur). */
function reject(type: 'bi_checking' | 'batal_sepihak') {
    const allowed = type === 'batal_sepihak' ? canBatalSepihak.value : canRejectBi.value;
    if (!allowed) return;
    const isB = type === 'batal_sepihak';
    const confirmMsg = isB
        ? 'Reject Batal Sepihak — customer mundur padahal BI Checking bersih. Transaksi ditolak & masuk Database Reject BI Checking. Lanjutkan?'
        : 'Reject BI Checking — hasil bermasalah (Kol 2–5) & customer mundur. Transaksi ditolak & proses berhenti. Lanjutkan?';
    if (!confirm(confirmMsg)) return;
    const reason = window.prompt((isB ? 'Alasan Reject Batal Sepihak' : 'Alasan Reject BI Checking') + ' (opsional):', '');
    if (reason === null) return; // dibatalkan
    saving.value = true;
    // Sertakan hasil BI Checking yang sudah diisi agar tampil di Database Reject BI Checking.
    router.post(route('kpr.initiation.reject', a.value.id),
        { reason, reject_type: type, result: f.bi_checking.result, result_pasangan: f.bi_checking.result_pasangan, permintaan: f.bi_checking.permintaan, notes: f.bi_checking.notes },
        { preserveScroll: true, onFinish: () => (saving.value = false) });
}

// Aspek bi_pemohon & bi_pasangan Analisa → OTOMATIS dari hasil BI Checking (Clear/Kol 1 = High, Kol 2 = Medium, Kol 3–5 = Low).
const BI_AUTO_MAP: Record<string, AspekLevel> = { clear: 'high', kol1: 'high', kol2: 'medium', kol3: 'low', kol4: 'low', kol5: 'low' };
const biStored = computed<any>(() => a.value.stages?.bi_checking ?? {});
const biPemohonAuto = computed<AspekLevel | ''>(() => BI_AUTO_MAP[biStored.value.result ?? f.bi_checking.result] ?? '');
// Pasangan: bila tak ada hasil pasangan (belum menikah / data lama), ikut hasil pemohon agar penilaian tetap lengkap.
const biPasanganAuto = computed<AspekLevel | ''>(() => {
    const pas = biNeedPasangan.value ? (biStored.value.result_pasangan ?? f.bi_checking.result_pasangan) : '';
    const src = pas || (biStored.value.result ?? f.bi_checking.result);
    return BI_AUTO_MAP[src] ?? '';
});
// Sinkronkan nilai otomatis ke matriks Analisa (aspek ini tak bisa diubah manual).
watch([biPemohonAuto, biPasanganAuto], ([p, s]) => {
    if (p) f.analisa.indikator_items['bi_pemohon'] = p;
    if (s) f.analisa.indikator_items['bi_pasangan'] = s;
}, { immediate: true });


function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v ?? 0); }
const biLabel: Record<string, string> = { clear: 'Clear', caution: 'Perlu Perhatian', reject: 'Ditolak' };
const analisaLabel: Record<string, string> = { layak: 'Layak', tidak_layak: 'Tidak Layak' };
</script>

<template>
    <div class="max-w-7xl space-y-4">
        <!-- Input file tersembunyi global (dipakai tombol Upload pada Collect Berkas; ditaruh di luar v-for agar ref valid) -->
        <input ref="fileInput" type="file" hidden accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" @change="onFileChosen" />
        <Link :href="route('kpr.initiation.index')" class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-primary-600"><ArrowLeft class="h-4 w-4" /> Kembali ke daftar</Link>

        <!-- Header -->
        <div class="card p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold">Inisiasi KPR <span class="font-mono text-sm text-neutral-400">{{ a.number }}</span></h1>
                    <p class="text-sm text-neutral-500 mt-0.5">{{ a.customer?.full_name }} · TTS {{ a.tts?.tts_number ?? '—' }}</p>
                </div>
                <Badge :variant="a.status === 'completed' ? 'success' : (a.status === 'rejected' ? 'danger' : 'warning')" dot>{{ a.status === 'completed' ? 'Selesai' : (a.status === 'rejected' ? 'Ditolak' : 'Berproses') }}</Badge>
            </div>
            <p class="mt-1 text-xs text-neutral-400">Tahap {{ Math.min(a.current_stage, 8) }} dari 8</p>
        </div>

        <!-- Alur modul — stepper 1 baris penuh-lebar (seperti Account Payable) -->
        <div class="card px-6 py-5">
            <div class="flex flex-nowrap items-center justify-between gap-3 overflow-x-auto">
                <template v-for="st in stageList" :key="st.key">
                    <button type="button" @click="toggle(st.idx)" :disabled="locked(st.idx)"
                            :title="locked(st.idx) ? 'Selesaikan & simpan tahap sebelumnya dulu' : ''"
                            class="flex shrink-0 items-center gap-3 whitespace-nowrap rounded-xl px-4 py-2.5 text-lg font-semibold transition-colors"
                            :class="[
                                done(st.key) ? 'text-success-600' : (st.idx === activeIdx ? 'bg-primary-600 text-white' : 'text-neutral-400'),
                                locked(st.idx) ? 'cursor-not-allowed' : 'cursor-pointer hover:opacity-80',
                            ]">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base"
                              :class="done(st.key) ? 'bg-success-100 dark:bg-success-900/40' : (st.idx === activeIdx ? 'bg-white/20' : 'bg-neutral-100 dark:bg-neutral-800')">
                            <Check v-if="done(st.key)" class="h-5 w-5" />
                            <Lock v-else-if="locked(st.idx)" class="h-4 w-4" />
                            <span v-else>{{ st.idx + 1 }}</span>
                        </span>
                        {{ stepShort[st.key] ?? st.label }}
                    </button>
                    <ArrowRight v-if="st.idx < stageList.length - 1" :stroke-width="2.5"
                                :class="['h-6 w-6 shrink-0', done(st.key) ? 'text-success-600' : 'text-neutral-400']" />
                </template>
            </div>
        </div>

        <!-- Stepper 8 tahap -->
        <div class="space-y-3">
            <div v-for="st in stageList" :key="st.key" class="card overflow-hidden"
                 :class="locked(st.idx) ? 'opacity-60' : ''">
                <!-- Header tahap -->
                <button type="button" class="flex w-full items-center justify-between gap-3 p-4 text-left"
                        :class="!locked(st.idx) ? 'hover:bg-neutral-50 dark:hover:bg-neutral-800/50' : 'cursor-not-allowed'"
                        :title="locked(st.idx) ? 'Terkunci — selesaikan & simpan tahap sebelumnya dulu' : ''"
                        @click="toggle(st.idx)">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold"
                              :class="done(st.key) ? 'bg-success-500 text-white' : (st.idx === activeIdx ? 'bg-primary-600 text-white' : 'bg-neutral-200 text-neutral-500 dark:bg-neutral-700')">
                            <Check v-if="done(st.key)" class="h-4 w-4" />
                            <Lock v-else-if="locked(st.idx)" class="h-3.5 w-3.5" />
                            <span v-else>{{ st.idx + 1 }}</span>
                        </span>
                        <div>
                            <p class="font-semibold">{{ st.label }}</p>
                            <p v-if="done(st.key)" class="text-xs text-success-600">Selesai</p>
                            <p v-else-if="st.idx === activeIdx" class="text-xs text-primary-600">Tahap aktif</p>
                            <p v-else-if="locked(st.idx)" class="text-xs text-neutral-400">Terkunci</p>
                        </div>
                    </div>
                    <ChevronRight class="h-4 w-4 text-neutral-400 transition-transform" :class="openStage === st.idx ? 'rotate-90' : ''" />
                </button>

                <!-- Body tahap -->
                <div v-if="openStage === st.idx && !locked(st.idx)" class="border-t border-neutral-100 px-4 py-4 dark:border-neutral-800">

                    <!-- 1. TTS (sumber) -->
                    <div v-if="st.key === 'kuitansi'" class="space-y-3">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div><dt class="text-neutral-500">No. TTS</dt><dd class="font-mono">{{ a.tts?.tts_number ?? '—' }}</dd></div>
                            <div><dt class="text-neutral-500">Nominal</dt><dd class="font-mono">{{ fmtRp(a.tts?.amount ?? 0) }}</dd></div>
                            <div><dt class="text-neutral-500">Customer</dt><dd class="font-medium">{{ a.customer?.full_name ?? '—' }}</dd></div>
                            <div><dt class="text-neutral-500">Booking</dt><dd class="font-mono">{{ a.booking?.booking_number ?? '—' }}</dd></div>
                        </dl>
                        <p class="text-xs text-neutral-500">Sumber data berasal dari modul Finance → TTS (Tanda Terima Setoran).</p>
                        <Button :loading="saving" @click="save('kuitansi')">Konfirmasi &amp; Lanjut →</Button>
                    </div>

                    <!-- 2. SPRS — info kontrak auto dari Marketing & Sales + checklist KTP/NPWP diterima -->
                    <div v-else-if="st.key === 'sprs'" class="space-y-4">
                        <!-- Informasi SPRS (No. Kontrak … PPN) — otomatis dari modul Marketing & Sales (SPRS/Kontrak) -->
                        <div v-if="contractInfo" class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-700 dark:bg-neutral-800/40">
                            <p class="mb-3 text-sm font-semibold">Informasi SPRS</p>
                            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <div><dt class="text-neutral-500">No. Kontrak</dt><dd class="font-mono">{{ contractInfo.contract_number ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Tanggal</dt><dd>{{ contractInfo.date ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Customer</dt><dd class="font-medium">{{ contractInfo.customer ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ contractInfo.unit ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Sales</dt><dd>{{ contractInfo.sales ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Skema Bayar</dt><dd>{{ contractInfo.scheme ?? '—' }}</dd></div>
                                <div><dt class="text-neutral-500">Price List (Gross)</dt><dd class="font-mono">{{ fmtRp(contractInfo.price_list_gross) }}</dd></div>
                                <div><dt class="text-neutral-500">Diskon</dt><dd class="font-mono">{{ fmtRp(contractInfo.discount) }}</dd></div>
                                <div><dt class="text-neutral-500">DPP</dt><dd class="font-mono">{{ fmtRp(contractInfo.dpp) }}</dd></div>
                                <div><dt class="text-neutral-500">PPN</dt><dd class="font-mono">{{ fmtRp(contractInfo.ppn) }}</dd></div>
                                <div><dt class="text-neutral-500">Nilai Kontrak</dt><dd class="font-mono font-semibold">{{ fmtRp(contractInfo.contract_value) }}</dd></div>
                            </dl>
                            <p class="mt-3 text-xs text-neutral-500">Sumber data berasal dari modul Marketing &amp; Sales → SPRS (otomatis).</p>
                        </div>

                        <!-- Status pernikahan pemohon — WAJIB dipilih dulu; menentukan 2 vs 4 dokumen wajib -->
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold">Status Pernikahan Pemohon</p>
                                <span v-if="custMaritalLabel" class="text-xs text-neutral-500">Data customer: <span class="font-medium text-neutral-700 dark:text-neutral-300">{{ custMaritalLabel }}</span></span>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <button type="button" @click="setSprsMarital('belum_menikah')"
                                    class="flex items-center gap-3 rounded-lg border p-3 text-left transition"
                                    :class="f.sprs.marital_status === 'belum_menikah' ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-900/20' : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700'">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full" :class="f.sprs.marital_status === 'belum_menikah' ? 'bg-primary-500 text-white' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'"><User class="h-5 w-5" /></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold">Belum Menikah</span>
                                        <span class="block text-xs text-neutral-500">2 dokumen: KTP &amp; NPWP Pemohon</span>
                                    </span>
                                    <Check v-if="f.sprs.marital_status === 'belum_menikah'" class="ml-auto h-5 w-5 shrink-0 text-primary-600" />
                                </button>
                                <button type="button" @click="setSprsMarital('sudah_menikah')"
                                    class="flex items-center gap-3 rounded-lg border p-3 text-left transition"
                                    :class="f.sprs.marital_status === 'sudah_menikah' ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-900/20' : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-700'">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full" :class="f.sprs.marital_status === 'sudah_menikah' ? 'bg-primary-500 text-white' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'"><Users class="h-5 w-5" /></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold">Sudah Menikah</span>
                                        <span class="block text-xs text-neutral-500">4 dokumen: + KTP &amp; NPWP Pasangan</span>
                                    </span>
                                    <Check v-if="f.sprs.marital_status === 'sudah_menikah'" class="ml-auto h-5 w-5 shrink-0 text-primary-600" />
                                </button>
                            </div>
                        </div>

                        <!-- Konfirmasi penerimaan dokumen — aktif setelah status pernikahan dipilih; jam & tanggal saat dicentang -->
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700 space-y-2" :class="!f.sprs.marital_status ? 'opacity-60' : ''">
                            <p class="text-sm font-semibold">Konfirmasi Penerimaan Dokumen</p>
                            <p v-if="!f.sprs.marital_status" class="text-xs text-warning-600">Pilih status pernikahan pemohon di atas untuk mengaktifkan checklist.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="flex items-start gap-2 text-sm" :class="!f.sprs.marital_status ? 'cursor-not-allowed opacity-60' : ''">
                                    <input type="checkbox" :disabled="!f.sprs.marital_status" v-model="f.sprs.ktp_pemohon_received" @change="markSprsDoc('ktp_pemohon')" class="mt-0.5 h-4 w-4 rounded border-neutral-300 disabled:cursor-not-allowed" />
                                    <span>KTP Pemohon sudah diterima
                                        <span v-if="f.sprs.ktp_pemohon_received && f.sprs.ktp_pemohon_received_at" class="block text-[11px] text-success-600">✓ Dicentang {{ fmtDateTime(f.sprs.ktp_pemohon_received_at) }}</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2 text-sm" :class="!f.sprs.marital_status ? 'cursor-not-allowed opacity-60' : ''">
                                    <input type="checkbox" :disabled="!f.sprs.marital_status" v-model="f.sprs.npwp_pemohon_received" @change="markSprsDoc('npwp_pemohon')" class="mt-0.5 h-4 w-4 rounded border-neutral-300 disabled:cursor-not-allowed" />
                                    <span>NPWP Pemohon sudah diterima
                                        <span v-if="f.sprs.npwp_pemohon_received && f.sprs.npwp_pemohon_received_at" class="block text-[11px] text-success-600">✓ Dicentang {{ fmtDateTime(f.sprs.npwp_pemohon_received_at) }}</span>
                                    </span>
                                </label>
                                <label v-if="f.sprs.marital_status === 'sudah_menikah'" class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" v-model="f.sprs.ktp_pasangan_received" @change="markSprsDoc('ktp_pasangan')" class="mt-0.5 h-4 w-4 rounded border-neutral-300" />
                                    <span>KTP Pasangan sudah diterima
                                        <span v-if="f.sprs.ktp_pasangan_received && f.sprs.ktp_pasangan_received_at" class="block text-[11px] text-success-600">✓ Dicentang {{ fmtDateTime(f.sprs.ktp_pasangan_received_at) }}</span>
                                    </span>
                                </label>
                                <label v-if="f.sprs.marital_status === 'sudah_menikah'" class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" v-model="f.sprs.npwp_pasangan_received" @change="markSprsDoc('npwp_pasangan')" class="mt-0.5 h-4 w-4 rounded border-neutral-300" />
                                    <span>NPWP Pasangan sudah diterima
                                        <span v-if="f.sprs.npwp_pasangan_received && f.sprs.npwp_pasangan_received_at" class="block text-[11px] text-success-600">✓ Dicentang {{ fmtDateTime(f.sprs.npwp_pasangan_received_at) }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <p v-if="!stageComplete('sprs')" class="text-xs text-warning-600">{{ sprsHint }}</p>
                        <Button :loading="saving" :disabled="!stageComplete('sprs')" @click="save('sprs', f.sprs)">Simpan &amp; Lanjut → BI Checking</Button>
                    </div>

                    <!-- 3. BI CHECKING -->
                    <form v-else-if="st.key === 'bi_checking'" @submit.prevent="save('bi_checking', f.bi_checking)" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label-base">Hasil BI Checking Pemohon</label>
                                <select v-model="f.bi_checking.result" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option value="clear">Clear</option>
                                    <option value="kol1">Kol 1</option>
                                    <option value="kol2">Kol 2</option>
                                    <option value="kol3">Kol 3</option>
                                    <option value="kol4">Kol 4</option>
                                    <option value="kol5">Kol 5</option>
                                </select>
                            </div>
                            <div v-if="biNeedPasangan">
                                <label class="label-base">Hasil BI Checking Pasangan</label>
                                <select v-model="f.bi_checking.result_pasangan" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option value="clear">Clear</option>
                                    <option value="kol1">Kol 1</option>
                                    <option value="kol2">Kol 2</option>
                                    <option value="kol3">Kol 3</option>
                                    <option value="kol4">Kol 4</option>
                                    <option value="kol5">Kol 5</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-base">Permintaan Customer</label>
                                <select v-model="f.bi_checking.permintaan" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option value="lanjut_spr">Lanjut Pemberkasan SPR</option>
                                    <option value="mundur">Mundur / Reject</option>
                                </select>
                            </div>
                        </div>
                        <Input v-model="f.bi_checking.notes" label="Catatan" />

                        <!-- R3 — Reject bersifat TERMINAL: bila sudah di-Reject, proses batal & tak bisa Simpan & Lanjut -->
                        <div v-if="a.stages?.bi_checking?.rejected" class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm dark:border-danger-800 dark:bg-danger-900/30">
                            <p class="font-semibold text-danger-700 dark:text-danger-300">✗ {{ a.stages.bi_checking.reject_type === 'batal_sepihak' ? 'Ditolak — Reject Batal Sepihak (customer mundur, BI bersih).' : 'BI Checking di-REJECT — proses dibatalkan.' }}</p>
                            <p v-if="a.stages.bi_checking.reject_reason" class="mt-0.5 text-xs text-danger-600">Alasan: {{ a.stages.bi_checking.reject_reason }}</p>
                            <p v-if="a.stages.bi_checking.rejected_by_name" class="mt-0.5 text-[11px] text-neutral-500">oleh {{ a.stages.bi_checking.rejected_by_name }} · {{ fmtDateTime(a.stages.bi_checking.rejected_at) }}</p>
                        </div>
                        <template v-else>
                            <p class="text-xs text-warning-600">
                                <span v-if="canBatalSepihak">BI Checking bersih (Clear/Kol 1) &amp; customer mundur — gunakan <b>Reject Batal Sepihak</b> untuk membatalkan (pembatalan dari pihak customer).</span>
                                <span v-else-if="canRejectBi">Ada hasil bermasalah (Kol 2–5) &amp; customer mundur — gunakan <b>Reject BI Checking</b> untuk membatalkan proses.</span>
                                <span v-else-if="stageComplete('bi_checking')">Permintaan customer lanjut — klik <b>Simpan &amp; Lanjut</b> ke Collect Berkas.</span>
                                <span v-else><b>Simpan &amp; Lanjut</b> aktif bila Permintaan Customer = "Lanjut Pemberkasan SPR". Saat Permintaan = "Mundur / Reject": <b>Reject Batal Sepihak</b> muncul bila BI bersih, <b>Reject BI Checking</b> bila ada Kol 2–5.</span>
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button type="submit" :loading="saving" :disabled="!stageComplete('bi_checking')">Simpan &amp; Lanjut →</Button>
                                <Button v-if="canBatalSepihak" type="button" variant="danger" :loading="saving" @click="reject('batal_sepihak')">REJECT BATAL SEPIHAK</Button>
                                <Button v-else type="button" variant="danger" :loading="saving" :disabled="!canRejectBi" @click="reject('bi_checking')">REJECT BI CHECKING</Button>
                            </div>
                            <p class="text-[11px] text-neutral-400">Simpan &amp; Lanjut, Reject BI Checking, dan Reject Batal Sepihak bersifat pilihan — yang sudah di-Reject prosesnya batal &amp; tidak bisa dilanjutkan.</p>
                        </template>
                    </form>

                    <!-- 4. COLLECT BERKAS -->
                    <div v-else-if="st.key === 'collect_berkas'" class="space-y-4">
                        <!-- Berkas Customer -->
                        <div>
                            <label class="label-base">Berkas Customer</label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" v-for="(cat, ck) in berkasCategories" :key="ck" @click="setCategory(ck)"
                                        class="rounded-lg border px-4 py-2 text-sm font-semibold transition"
                                        :class="f.collect_berkas.category === ck ? 'border-primary-600 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300'">
                                    {{ cat.label }}
                                </button>
                            </div>
                        </div>

                        <p v-if="!f.collect_berkas.category" class="text-sm text-warning-600">Pilih kategori pemohon (Karyawan / Wirausaha) untuk menampilkan checklist berkas.</p>

                        <template v-else>
                            <p class="text-xs text-neutral-500">Tandai tiap berkas <b>Lengkap</b> / <b>Tidak Lengkap</b>. Untuk yang Tidak Lengkap, isi catatan (di sebelah kanan).</p>
                            <div class="space-y-2">
                                <div v-for="(it, i) in currentItems" :key="bkey(it.key)" class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <div class="min-w-[180px] flex-1 text-sm font-medium">{{ i + 1 }}. {{ it.label }}</div>
                                        <!-- Status lengkap / tidak lengkap -->
                                        <div class="flex gap-1.5">
                                            <button type="button" @click="setBerkasStatus(bkey(it.key), 'lengkap')"
                                                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                                    :class="f.collect_berkas.items[bkey(it.key)]?.status === 'lengkap' ? 'border-success-500 bg-success-500 text-white' : 'border-success-300 text-success-700 hover:bg-success-50 dark:border-success-700'">✓ Lengkap</button>
                                            <button type="button" @click="setBerkasStatus(bkey(it.key), 'tidak_lengkap')"
                                                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                                    :class="f.collect_berkas.items[bkey(it.key)]?.status === 'tidak_lengkap' ? 'border-danger-500 bg-danger-500 text-white' : 'border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700'">✗ Tidak Lengkap</button>
                                        </div>
                                        <!-- Catatan: aktif hanya bila Tidak Lengkap -->
                                        <input v-model="f.collect_berkas.items[bkey(it.key)].note"
                                               :disabled="f.collect_berkas.items[bkey(it.key)]?.status !== 'tidak_lengkap'"
                                               :placeholder="f.collect_berkas.items[bkey(it.key)]?.status === 'tidak_lengkap' ? 'Catatan: kenapa belum lengkap…' : 'Catatan (aktif bila Tidak Lengkap)'"
                                               class="h-8 min-w-[160px] flex-1 rounded-lg border border-neutral-300 px-2 text-xs disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900 dark:disabled:bg-neutral-800" />
                                    </div>
                                    <!-- Stempel jam & tanggal saat status (Lengkap / Tidak Lengkap) ditandai -->
                                    <div v-if="f.collect_berkas.items[bkey(it.key)]?.status && f.collect_berkas.items[bkey(it.key)]?.status_at"
                                         class="mt-2 text-[11px]"
                                         :class="f.collect_berkas.items[bkey(it.key)]?.status === 'lengkap' ? 'text-success-600' : 'text-danger-600'">
                                        {{ f.collect_berkas.items[bkey(it.key)]?.status === 'lengkap' ? '✓ Ditandai Lengkap' : '✗ Ditandai Tidak Lengkap' }} · {{ fmtDateTime(f.collect_berkas.items[bkey(it.key)]?.status_at) }}
                                    </div>
                                    <!-- Info file terunggah -->
                                    <div v-if="itemFile(bkey(it.key))?.uploaded_at" class="mt-2 flex flex-wrap items-center gap-x-2 text-xs text-neutral-500">
                                        <a :href="route('kpr.initiation.berkas-doc', [a.id, bkey(it.key)])" class="inline-flex items-center gap-1 text-primary-600 hover:underline dark:text-primary-400"><Download class="h-3.5 w-3.5" />{{ itemFile(bkey(it.key))?.file_name ?? 'file' }}</a>
                                        <span>· Diunggah {{ fmtDateTime(itemFile(bkey(it.key))?.uploaded_at) }}</span>
                                    </div>
                                </div>
                            </div>

                            <Input v-model="f.collect_berkas.notes" label="Catatan Umum" />

                            <p class="text-xs font-medium" :class="allBerkasComplete ? 'text-success-600' : 'text-warning-600'">
                                {{ allBerkasComplete ? '✓ Semua berkas wajib sudah Lengkap — siap lanjut ke Analisa.' : 'Tandai semua berkas wajib "Lengkap" untuk bisa Simpan & Lanjut.' }}
                            </p>
                            <Button type="button" :loading="saving" @click="save('collect_berkas', f.collect_berkas)">{{ allBerkasComplete ? 'Simpan & Lanjut →' : 'Simpan' }}</Button>
                        </template>
                    </div>

                    <!-- 5. ANALISA BI CHECKING & BERKAS — matriks indikator per aspek (High/Medium/Low) -->
                    <form v-else-if="st.key === 'analisa'" @submit.prevent="save('analisa', f.analisa)" class="space-y-4">
                        <!-- Header: kategori pemohon + progres penilaian -->
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-semibold">Penilaian Indikator</p>
                                <Badge v-if="analisaCategory" variant="info" size="sm">{{ analisaCategory === 'wirausaha' ? 'Wirausaha' : 'Karyawan / Fixed Income' }}</Badge>
                            </div>
                            <span class="text-xs font-medium" :class="analisaAllScored ? 'text-success-600' : 'text-neutral-500'">{{ analisaScored }} / {{ analisaTotal }} aspek dinilai</span>
                        </div>
                        <p v-if="!analisaCategory" class="text-sm text-warning-600">Kategori pemohon belum diisi pada tahap Collect Berkas — lengkapi dahulu.</p>

                        <!-- Matriks aspek: pilih salah satu High / Medium / Low per aspek -->
                        <div class="space-y-2.5">
                            <div v-for="asp in analisaAspek" :key="asp.key"
                                 class="rounded-xl border p-3 transition"
                                 :class="f.analisa.indikator_items[asp.key] ? 'border-neutral-200 dark:border-neutral-700' : 'border-dashed border-neutral-300 dark:border-neutral-600'">
                                <div class="mb-2 flex flex-wrap items-center gap-2 text-sm font-medium">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-xs font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ asp.no }}</span>
                                    <span>{{ asp.label }}</span>
                                    <Badge v-if="isAutoAspek(asp.key)" variant="info" size="sm">otomatis dari BI Checking</Badge>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <button v-for="opt in asp.options" :key="opt.level" type="button" :disabled="isAutoAspek(asp.key)" @click="setAspek(asp.key, opt.level)"
                                            class="flex flex-col items-start gap-1 rounded-lg border p-2.5 text-left transition"
                                            :class="f.analisa.indikator_items[asp.key] === opt.level ? lvlActiveClass(opt.level) : ('border-neutral-200 border-l-4 ' + lvlEdgeClass(opt.level) + (isAutoAspek(asp.key) ? ' cursor-not-allowed opacity-50 dark:border-neutral-700' : ' bg-neutral-50/60 hover:bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800/40 dark:hover:bg-neutral-800'))">
                                        <span class="rounded px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide" :class="lvlPillClass(opt.level, f.analisa.indikator_items[asp.key] === opt.level)">{{ indikatorLabel[opt.level] }}</span>
                                        <span class="text-xs font-medium leading-snug" :class="f.analisa.indikator_items[asp.key] === opt.level ? 'text-white' : 'text-neutral-800 dark:text-neutral-200'">{{ opt.desc }}</span>
                                        <span v-if="opt.hint" class="text-[11px] leading-snug" :class="f.analisa.indikator_items[asp.key] === opt.level ? 'text-white/80' : 'text-neutral-500'">{{ opt.hint }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Ringkasan: distribusi skor + indikator keseluruhan (otomatis) -->
                        <div class="rounded-xl border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-900/40 dark:bg-primary-900/10">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs text-neutral-500">Indikator Analisa Keseluruhan <span class="text-[10px]">(otomatis dari rata-rata skor aspek)</span></p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <Badge v-if="indikatorDerived" :variant="indikatorDerived === 'high' ? 'success' : indikatorDerived === 'medium' ? 'warning' : 'danger'">{{ indikatorLabel[indikatorDerived] }}</Badge>
                                        <span v-else class="text-sm text-warning-600">— nilai semua aspek dahulu —</span>
                                    </div>
                                </div>
                                <div class="flex gap-3 text-xs">
                                    <span class="text-success-600">High: <b>{{ lvlCount('high') }}</b></span>
                                    <span class="text-warning-600">Medium: <b>{{ lvlCount('medium') }}</b></span>
                                    <span class="text-danger-600">Low: <b>{{ lvlCount('low') }}</b></span>
                                </div>
                            </div>
                            <p v-if="lowAspek.length" class="mt-2 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-900/20 dark:text-danger-300">⚠ Ada {{ lowAspek.length }} aspek berisiko (Low): <b>{{ lowAspek.join(', ') }}</b>. Pertimbangkan baik-baik sebelum memutuskan Layak.</p>
                            <p v-if="analisaScored > 0" class="mt-2 border-t border-primary-200/60 pt-2 text-xs text-neutral-600 dark:border-primary-900/40 dark:text-neutral-300">→ Rekomendasi bank (muncul di <b>Profiling</b>): <b class="text-success-700 dark:text-success-300">{{ bankRekomLive.find((b) => b.rekom === 1)?.name }}</b> &amp; <b class="text-primary-700 dark:text-primary-300">{{ bankRekomLive.find((b) => b.rekom === 2)?.name }}</b><span v-if="!analisaAllScored" class="text-warning-600"> · sementara — nilai {{ analisaTotal - analisaScored }} aspek lagi untuk final</span></p>
                        </div>

                        <!-- Hasil Analisa (keputusan analis) -->
                        <div>
                            <label class="label-base">Hasil Analisa <span class="text-danger-500">*</span></label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="f.analisa.result = 'layak'"
                                        class="rounded-lg border px-5 py-2 text-sm font-semibold transition"
                                        :class="f.analisa.result === 'layak' ? 'border-success-500 bg-success-500 text-white' : 'border-success-300 text-success-700 hover:bg-success-50 dark:border-success-700'">✓ Layak</button>
                                <button type="button" @click="f.analisa.result = 'tidak_layak'"
                                        class="rounded-lg border px-5 py-2 text-sm font-semibold transition"
                                        :class="f.analisa.result === 'tidak_layak' ? 'border-danger-500 bg-danger-500 text-white' : 'border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700'">✗ Tidak Layak</button>
                            </div>
                            <p v-if="indikatorDerived && !f.analisa.result" class="mt-1 text-[11px] text-neutral-500">Saran: indikator <b>{{ indikatorLabel[indikatorDerived] }}</b> → {{ indikatorDerived === 'low' ? 'pertimbangkan Tidak Layak / review lebih lanjut' : 'cenderung Layak' }}.</p>
                            <p v-if="resultContradiction" class="mt-1 text-[11px] text-warning-600">⚠ {{ resultContradiction }} Pastikan alasan tercatat di Catatan Analisa.</p>
                        </div>

                        <Input v-model="f.analisa.notes" label="Catatan Analisa" />
                        <p v-if="!stageComplete('analisa')" class="text-xs text-warning-600">Nilai <b>semua</b> aspek indikator &amp; pilih Hasil Analisa (Layak / Tidak Layak) untuk lanjut.</p>
                        <Button type="submit" :loading="saving" :disabled="!stageComplete('analisa')">Simpan &amp; Lanjut →</Button>
                    </form>

                    <!-- 6. PROFILING BERKAS — rangkuman profil pemohon (umur/domisili/sumber/penghasilan) + pekerjaan -->
                    <form v-else-if="st.key === 'profiling_berkas'" @submit.prevent="save('profiling_berkas', f.profiling_berkas)" class="space-y-4">
                        <p class="rounded-lg bg-neutral-100 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                            Rangkuman profil berkas pemohon — sebagian data ditarik otomatis dari <b>NUP / Pendaftaran</b> &amp; <b>Analisa BI Checking &amp; Berkas</b>. Lengkapi <b>tanggal lahir</b>, <b>domisili pekerjaan</b>, dan <b>jabatan</b> untuk lanjut.
                        </p>

                        <div class="grid gap-4 md:grid-cols-2">
                            <!-- 1. Umur -->
                            <div class="rounded-xl border border-sky-200 bg-sky-50/50 p-4 dark:border-sky-900/40 dark:bg-sky-900/10">
                                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-sky-700 dark:text-sky-300"><CalendarDays class="h-4 w-4" /> Umur Pemohon</div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Lahir <span class="text-danger-500">*</span></label>
                                <input v-model="f.profiling_berkas.tanggal_lahir" type="date" class="input-base" />
                                <div class="mt-2 text-sm">
                                    <span v-if="pbUmur" class="font-semibold text-sky-700 dark:text-sky-300">{{ pbUmur.years }} tahun {{ pbUmur.months }} bulan</span>
                                    <span v-else class="text-neutral-400">— isi tanggal lahir untuk menghitung umur —</span>
                                </div>
                            </div>

                            <!-- 2. Tempat tinggal / domisili -->
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 dark:border-emerald-900/40 dark:bg-emerald-900/10">
                                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300"><MapPin class="h-4 w-4" /> Tempat Tinggal / Domisili</div>
                                <p v-if="pbDomisili" class="text-sm leading-relaxed text-neutral-700 dark:text-neutral-200">{{ pbDomisili }}</p>
                                <p v-else class="text-sm text-neutral-400">— data domisili belum tersedia dari NUP/pendaftaran —</p>
                                <p class="mt-2 text-[11px] text-neutral-400">Sumber: alamat NUP / pendaftaran customer</p>
                            </div>

                            <!-- 3. Sumber informasi -->
                            <div class="rounded-xl border border-violet-200 bg-violet-50/50 p-4 dark:border-violet-900/40 dark:bg-violet-900/10">
                                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-violet-700 dark:text-violet-300"><Megaphone class="h-4 w-4" /> Sumber Informasi</div>
                                <span v-if="pbSumber" class="inline-flex rounded-full bg-violet-100 px-3 py-1 text-sm font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-200">{{ pbSumber }}</span>
                                <span v-else class="text-sm text-neutral-400">— belum ada sumber data —</span>
                                <p class="mt-2 text-[11px] text-neutral-400">Sumber: pilihan "Sumber Data" saat NUP / pendaftaran</p>
                            </div>

                            <!-- 4. Data penghasilan (RPC) -->
                            <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900/40 dark:bg-amber-900/10">
                                <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300"><Wallet class="h-4 w-4" /> Data Penghasilan (RPC)</div>
                                <div v-if="pbPenghasilan.length" class="space-y-1.5">
                                    <div v-for="row in pbPenghasilan" :key="row.label" class="flex items-center justify-between gap-2 text-sm">
                                        <span class="text-neutral-600 dark:text-neutral-300">{{ row.hint || row.label }}</span>
                                        <span class="flex items-center gap-2"><Badge size="sm" :variant="row.level === 'high' ? 'success' : (row.level === 'medium' ? 'warning' : 'danger')">{{ indikatorLabel[row.level] }}</Badge><span class="font-mono font-semibold">{{ row.amount }}</span></span>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-neutral-400">— belum ada penilaian penghasilan di tahap Analisa —</p>
                                <p v-if="pbMonthlyIncome > 0" class="mt-2 border-t border-amber-200/60 pt-2 text-xs text-neutral-500 dark:border-amber-900/40">Penghasilan tercatat: <b class="font-mono">{{ fmtRp(pbMonthlyIncome) }}</b> / bln</p>
                                <p class="mt-1 text-[11px] text-neutral-400">Sumber: slip gaji / RPC pada Analisa BI Checking &amp; Berkas</p>
                            </div>
                        </div>

                        <!-- 5. Domisili pekerjaan (free text) + 6. Jabatan / pekerjaan -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="label-base">Domisili Pekerjaan <span class="text-danger-500">*</span></label>
                                <input v-model="f.profiling_berkas.domisili_pekerjaan" type="text" class="input-base" placeholder="Alamat / lokasi tempat bekerja…" />
                            </div>
                            <div>
                                <label class="label-base">Jabatan / Pekerjaan <span class="text-danger-500">*</span></label>
                                <select v-model="f.profiling_berkas.jabatan" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option v-for="j in JABATAN_OPTS" :key="j" :value="j">{{ j }}</option>
                                </select>
                            </div>
                        </div>

                        <p v-if="!stageComplete('profiling_berkas')" class="text-xs text-warning-600">Lengkapi tanggal lahir, domisili pekerjaan, dan jabatan untuk lanjut ke Profiling Customer.</p>
                        <Button type="submit" :loading="saving" :disabled="!stageComplete('profiling_berkas')">Simpan &amp; Lanjut →</Button>
                    </form>

                    <!-- 7. PROFILING CUSTOMER (R5) — indikator + rekomendasi bank + keputusan 2 bank + SPR -->
                    <form v-else-if="st.key === 'profiling'" @submit.prevent="save('profiling', f.profiling)" class="space-y-4">
                        <p class="rounded-lg bg-neutral-100 px-3 py-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">Alur: <b>Sistem menyarankan</b> (dari Analisa) → <b>Customer meminta</b> (preferensi) → <b>Anda memutuskan</b> (Keputusan bank yang dilanjutkan Proses KPR).</p>

                        <!-- Rekomendasi Bank — OTOMATIS dari skor Analisa BI Checking & Berkas -->
                        <div class="rounded-xl border border-primary-200 bg-primary-50/40 p-4 dark:border-primary-900/40 dark:bg-primary-900/10">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-semibold">Rekomendasi Bank — dari Analisa BI Checking &amp; Berkas</p>
                                    <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">🔒 Otomatis</span>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-neutral-500">
                                    <span>Indikator:</span>
                                    <Badge v-if="a.stages?.analisa?.indikator" :variant="a.stages.analisa.indikator === 'high' ? 'success' : a.stages.analisa.indikator === 'medium' ? 'warning' : 'danger'" size="sm">{{ indikatorLabel[a.stages.analisa.indikator] }}</Badge>
                                    <span v-else class="text-warning-600">— belum ada —</span>
                                </div>
                            </div>

                            <div v-if="!Object.keys(analisaItemsSaved).length" class="flex flex-wrap items-center gap-2 text-sm text-warning-600">
                                <span>Belum ada penilaian aspek di tahap Analisa.</span>
                                <button type="button" @click="openStage = analisaStageIdx" class="rounded-lg border border-primary-300 px-2.5 py-1 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300">Buka tahap Analisa →</button>
                            </div>
                            <template v-else>
                                <!-- 2 rekomendasi teratas -->
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-lg border border-success-300 bg-success-50 p-3 dark:border-success-700 dark:bg-success-900/20">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-300">🥇 Rekomendasi 1</span>
                                            <Badge variant="success" size="sm">Skor {{ bankRekom1?.score ?? 0 }}</Badge>
                                        </div>
                                        <p class="mt-1 text-xl font-bold">{{ bankRekom1?.name ?? '—' }}</p>
                                    </div>
                                    <div class="rounded-lg border border-primary-300 bg-white p-3 dark:border-primary-700 dark:bg-neutral-900/30">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">🥈 Rekomendasi 2</span>
                                            <Badge variant="info" size="sm">Skor {{ bankRekom2?.score ?? 0 }}</Badge>
                                        </div>
                                        <p class="mt-1 text-xl font-bold">{{ bankRekom2?.name ?? '—' }}</p>
                                    </div>
                                </div>

                                <!-- Rincian skor semua bank -->
                                <div class="mt-3 space-y-1.5">
                                    <div v-for="b in bankRekom" :key="b.name" class="flex items-center gap-2 text-xs">
                                        <span class="w-16 shrink-0 font-semibold">{{ b.name }}</span>
                                        <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700">
                                            <div class="h-full rounded-full transition-all" :class="b.rekom === 1 ? 'bg-success-500' : b.rekom === 2 ? 'bg-primary-500' : 'bg-neutral-400'" :style="{ width: Math.round(b.score / bankTheoreticalMax * 100) + '%' }"></div>
                                        </div>
                                        <span class="w-7 shrink-0 text-right font-mono">{{ b.score }}</span>
                                        <span class="w-20 shrink-0 text-right">
                                            <Badge v-if="b.rekom" :variant="b.rekom === 1 ? 'success' : 'info'" size="sm">Rekom {{ b.rekom }}</Badge>
                                            <span v-else class="text-neutral-400">—</span>
                                        </span>
                                    </div>
                                </div>
                                <p class="mt-2 text-[11px] text-neutral-500">Dihitung otomatis dari skor tiap aspek Analisa — semakin cocok profil customer dengan kriteria bank, semakin tinggi skornya.</p>
                            </template>
                        </div>

                        <!-- Rekomendasi Bank oleh Customer (×2) -->
                        <div>
                            <label class="label-base">Rekomendasi Bank oleh Customer</label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 1</label><select v-model="f.profiling.bank_customer_1" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM_CUSTOMER" :key="b" :value="b">{{ b }}</option></select></div>
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 2</label><select v-model="f.profiling.bank_customer_2" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM_CUSTOMER" :key="b" :value="b">{{ b }}</option></select></div>
                            </div>
                        </div>

                        <!-- Keputusan: 2 bank yang dilanjutkan Proses KPR + checklist SPR sudah terbit -->
                        <div class="rounded-lg border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-900/40 dark:bg-primary-900/10 space-y-3">
                            <p class="text-sm font-semibold">Keputusan — Bank yang Dilanjutkan Proses KPR (maks 2)</p>
                            <p class="text-xs text-neutral-500">Saran sistem: <b class="text-neutral-700 dark:text-neutral-200">{{ bankRekom1?.name ?? '—' }}</b>, <b class="text-neutral-700 dark:text-neutral-200">{{ bankRekom2?.name ?? '—' }}</b> · Preferensi customer: <b class="text-neutral-700 dark:text-neutral-200">{{ f.profiling.bank_customer_1 || '—' }}</b><span v-if="f.profiling.bank_customer_2">, <b class="text-neutral-700 dark:text-neutral-200">{{ f.profiling.bank_customer_2 }}</b></span></p>
                            <div class="grid items-end gap-2 sm:grid-cols-[1fr,auto]">
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 1</label><SearchSelect v-model="f.profiling.decision_1" :options="bankOptions" placeholder="Pilih bank keputusan 1…" /></div>
                                <label class="flex items-center gap-2 whitespace-nowrap pb-2 text-sm"><input type="checkbox" v-model="f.profiling.decision_1_spr" :disabled="!f.profiling.decision_1" class="h-4 w-4 rounded border-neutral-300" /> SPR sudah terbit</label>
                            </div>
                            <div class="grid items-end gap-2 sm:grid-cols-[1fr,auto]">
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 2 (opsional)</label><SearchSelect v-model="f.profiling.decision_2" :options="bankOptions" placeholder="Pilih bank keputusan 2…" /></div>
                                <label class="flex items-center gap-2 whitespace-nowrap pb-2 text-sm"><input type="checkbox" v-model="f.profiling.decision_2_spr" :disabled="!f.profiling.decision_2" class="h-4 w-4 rounded border-neutral-300" /> SPR sudah terbit</label>
                            </div>
                        </div>

                        <Input v-model="f.profiling.notes" label="Catatan Profiling" />
                        <p v-if="!stageComplete('profiling')" class="text-xs text-warning-600">Pilih minimal 1 bank keputusan untuk lanjut ke pembuatan Draft Proses KPR.</p>
                        <Button type="submit" :loading="saving" :disabled="!stageComplete('profiling')">Simpan &amp; Lanjut →</Button>
                    </form>

                    <!-- 7. PROSES KPR (R5) — checklist bank yang dibuatkan Draft -->
                    <div v-else-if="st.key === 'kpr_application'" class="space-y-3">
                        <div v-if="a.kpr" class="rounded-lg border border-success-300 bg-success-50 px-4 py-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-900/30 dark:text-success-300">
                            ✓ Draft Proses KPR sudah dibuat dari inisiasi ini.
                            <Link :href="route('kpr.show', a.kpr.id)" class="ml-1 font-semibold underline">Buka Proses KPR →</Link>
                            <span v-if="(a.stages?.kpr_application?.application_ids?.length ?? 0) > 1"> ({{ a.stages.kpr_application.application_ids.length }} bank)</span>
                        </div>
                        <form v-else @submit.prevent="submitKprApp" class="space-y-3">
                            <p class="text-xs text-neutral-500">Tahap akhir — checklist bank yang akan dibuatkan <strong>Draft Proses KPR</strong> (dari keputusan Profiling). Tiap bank dapat dilanjutkan/dibatalkan di modul Proses KPR.</p>
                            <div>
                                <label class="label-base">Booking <span class="text-danger-500">*</span></label>
                                <SearchSelect v-model="f.kpr_application.booking_id" :options="bookingOptions" placeholder="Pilih booking…" />
                            </div>
                            <p v-if="!decidedBanks.length" class="text-sm text-warning-600">Belum ada bank keputusan. Tentukan bank pada tahap Profiling dulu.</p>
                            <div v-else class="space-y-2">
                                <label class="label-base">Bank yang Dibuatkan Draft</label>
                                <div v-for="b in decidedBanks" :key="b.bank_partner_id" class="flex flex-wrap items-center gap-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                                    <label class="flex flex-1 items-center gap-2 text-sm font-medium"><input type="checkbox" v-model="stage7.rows[String(b.bank_partner_id)].checked" class="h-4 w-4 rounded border-neutral-300" /> {{ bankName(b.bank_partner_id) }}<Badge v-if="b.spr_issued" variant="success" size="sm" class="ml-1">SPR terbit</Badge></label>
                                    <div class="w-48"><input v-model="stage7.rows[String(b.bank_partner_id)].plafon" type="number" placeholder="Plafon (Rp)" class="input-base" /></div>
                                </div>
                            </div>
                            <Button type="submit" size="lg" :loading="saving" :disabled="!kprAppValid">Buat Draft Proses KPR →</Button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

        <!-- LAPORAN DURASI PROSES -->
        <div v-if="prosesLaporan.rows.length" class="card p-5">
            <h2 class="mb-1 text-lg font-bold">Laporan Durasi Proses</h2>
            <p class="mb-3 text-xs text-neutral-500">Waktu penyelesaian tiap tahap &amp; lama dari satu proses ke proses berikutnya.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-2">Tahap</th>
                        <th class="px-3 py-2">Selesai pada</th>
                        <th class="px-3 py-2 text-right">Durasi dari tahap sebelumnya</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-if="prosesLaporan.start" class="text-neutral-500">
                            <td class="px-3 py-2 italic">Mulai — Inisiasi dibuat</td>
                            <td class="px-3 py-2">{{ fmtDateTime(prosesLaporan.start) }}</td>
                            <td class="px-3 py-2 text-right text-neutral-300">—</td>
                        </tr>
                        <tr v-for="row in prosesLaporan.rows" :key="row.key">
                            <td class="px-3 py-2 font-medium">{{ row.label }}</td>
                            <td class="px-3 py-2">{{ fmtDateTime(row.at) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtDuration(row.durMs) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="prosesLaporan.totalMs !== null" class="border-t-2 border-neutral-300 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <tr class="font-bold">
                            <td class="px-3 py-2.5" colspan="2">Total durasi proses</td>
                            <td class="px-3 py-2.5 text-right font-mono text-primary-600">{{ fmtDuration(prosesLaporan.totalMs) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</template>
