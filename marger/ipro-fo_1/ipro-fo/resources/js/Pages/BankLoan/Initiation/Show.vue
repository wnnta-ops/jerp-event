<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { ArrowLeft, ArrowRight, Check, Lock, ChevronRight, Upload, Download } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    init: any;
    stageDefs: Record<string, string>;
    banks: Array<any>;
    bookings: Array<any>;
}>();

const init0 = props.init;              // snapshot awal — hanya untuk seed nilai form
const s = init0.stages || {};
const a = computed(() => props.init);  // SELALU props terbaru → tabel & OCR ikut update saat verifikasi

const stageList = computed(() => Object.entries(props.stageDefs).map(([key, label], idx) => ({ key, label: label as string, idx })));
// Label ringkas utk stepper horizontal (alur modul, spt Account Payable).
const stepShort: Record<string, string> = {
    kuitansi: 'TTS', sprs: 'SPRS', bi_checking: 'BI Checking', collect_berkas: 'Berkas',
    analisa: 'Analisa', profiling: 'Profiling', kpr_application: 'Proses KPR',
};
const activeIdx = computed(() => Math.max(0, Math.min(7, (a.value.current_stage ?? 1)) - 1));
const openStage = ref(activeIdx.value);
watch(activeIdx, (v) => { openStage.value = v; }); // auto-buka tahap aktif setelah tahap maju

function done(key: string) { return !!(a.value.stages?.[key]?.done); }
function locked(idx: number) { return idx > activeIdx.value; }
function toggle(idx: number) { if (!locked(idx)) openStage.value = openStage.value === idx ? -1 : idx; }
// Tahap dianggap "selesai" (boleh Simpan & Lanjut) bila field utamanya terisi — cegah maju tanpa data.
function stageComplete(key: string): boolean {
    if (key === 'sprs') return f.sprs.ktp_pemohon_received && f.sprs.npwp_pemohon_received && f.sprs.ktp_pasangan_received && f.sprs.npwp_pasangan_received;
    if (key === 'bi_checking') return !!f.bi_checking.result;
    if (key === 'analisa') return !!f.analisa.result && !!f.analisa.indikator;
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
    // No.SPRS & Tanggal SPRS auto-isi dari booking (SPRS) yg dibawa TTS bila belum disimpan.
    sprs:        { number: s.sprs?.number ?? (init0.booking?.booking_number ?? ''), date: s.sprs?.date ?? String(init0.booking?.booking_date ?? '').slice(0, 10), notes: s.sprs?.notes ?? '',
                   ktp_pemohon_received: !!s.sprs?.ktp_pemohon_received, npwp_pemohon_received: !!s.sprs?.npwp_pemohon_received,
                   ktp_pasangan_received: !!s.sprs?.ktp_pasangan_received, npwp_pasangan_received: !!s.sprs?.npwp_pasangan_received },
    bi_checking: { result: s.bi_checking?.result ?? '', permintaan: s.bi_checking?.permintaan ?? '', notes: s.bi_checking?.notes ?? '' },
    collect_berkas: {
        category: s.collect_berkas?.category ?? '',
        items: Object.fromEntries(Object.entries(s.collect_berkas?.items ?? {}).map(([k, v]: [string, any]) => [k, { status: v?.status ?? '', note: v?.note ?? '' }])) as Record<string, { status: string; note: string }>,
        notes: s.collect_berkas?.notes ?? '',
    },
    analisa: {
        result: s.analisa?.result ?? '',
        // Dipindah dari Profiling (seed dari data lama bila ada) + field analisa baru.
        pekerjaan: s.analisa?.pekerjaan ?? s.profiling?.pekerjaan ?? '',
        status_pekerjaan: s.analisa?.status_pekerjaan ?? '',
        penghasilan: s.analisa?.penghasilan ?? s.profiling?.penghasilan ?? '',
        lama_kerja: s.analisa?.lama_kerja ?? s.profiling?.lama_kerja ?? '',
        estimasi_bayar: s.analisa?.estimasi_bayar ?? '',
        umur: s.analisa?.umur ?? '',
        // R4 — indikator analisa berkas dikategorikan HIGH/MEDIUM/LOW (muncul di Profiling).
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
    kpr_application: { booking_id: init0.booking_id ?? '' },
});

// R4/R5 — indikator analisa muncul di Profiling sebagai keterangan HIGH/MEDIUM/LOW.
const indikatorLabel: Record<string, string> = { high: 'HIGH', medium: 'MEDIUM', low: 'LOW' };
// R5 — opsi rekomendasi bank (daftar tetap sesuai permintaan).
const BANK_REKOM = ['BTN', 'BSN', 'BRI', 'BNI', 'MANDIRI'];
const BANK_REKOM_CUSTOMER = [...BANK_REKOM, 'Tanpa Preferensi'];
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
        { key: 'spt_tahunan', label: 'SPT Tahunan' },
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
        { key: 'spt_tahunan', label: 'SPT Tahunan' },
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
        if (!f.collect_berkas.items[k]) f.collect_berkas.items[k] = { status: '', note: '' };
    }
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

// R4 — Analisa menampilkan kolom yang sama spt Collect Berkas (read-only) + kategori Karyawan/Wirausaha.
const analisaBerkas = computed(() => {
    const cb = a.value?.stages?.collect_berkas;
    const cat = cb?.category;
    if (!cat) return { category: null as string | null, label: null as string | null, items: [] as Array<{ label: string; optional?: boolean; status: string }> };
    const items = (berkasCategories[cat]?.items ?? []).map((it) => ({
        label: it.label,
        optional: it.optional,
        status: cb?.items?.[cat + '__' + it.key]?.status ?? '',
    }));
    return { category: cat, label: berkasCategories[cat]?.label ?? cat, items };
});

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

const saving = ref(false);
function save(key: string, data: Record<string, any> = {}) {
    saving.value = true;
    router.post(route('kpr.initiation.stage', a.value.id), { stage: key, data }, {
        preserveScroll: true,
        onFinish: () => (saving.value = false),
    });
}

/* Tolak inisiasi pada tahap BI Checking → status 'rejected' (muncul di Database KPR → Reject BI Checking). */
function reject() {
    if (!confirm('Tolak inisiasi KPR ini pada tahap BI Checking? Status menjadi Ditolak dan proses berhenti.')) return;
    const reason = window.prompt('Alasan penolakan BI Checking (opsional):', '');
    if (reason === null) return; // dibatalkan
    saving.value = true;
    // Sertakan hasil BI Checking yang sudah diisi agar tampil di Database Reject BI Checking.
    router.post(route('kpr.initiation.reject', a.value.id),
        { reason, result: f.bi_checking.result, permintaan: f.bi_checking.permintaan, notes: f.bi_checking.notes },
        { preserveScroll: true, onFinish: () => (saving.value = false) });
}


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
            <p class="mt-1 text-xs text-neutral-400">Tahap {{ Math.min(a.current_stage, 7) }} dari 7</p>
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

        <!-- Stepper 7 tahap -->
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

                    <!-- 2. SPRS — checklist KTP/NPWP diterima (scan/OCR di menu "Cek Scan KTP & NPWP") -->
                    <div v-else-if="st.key === 'sprs'" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Input v-model="f.sprs.number" label="No. SPRS" />
                            <Input v-model="f.sprs.date" type="date" label="Tanggal SPRS" />
                        </div>
                        <Input v-model="f.sprs.notes" label="Catatan" />

                        <!-- Konfirmasi penerimaan dokumen (Pemohon + Pasangan) -->
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700 space-y-2">
                            <p class="text-sm font-semibold">Konfirmasi Penerimaan Dokumen</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="f.sprs.ktp_pemohon_received" class="h-4 w-4 rounded border-neutral-300" /> KTP Pemohon sudah diterima</label>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="f.sprs.npwp_pemohon_received" class="h-4 w-4 rounded border-neutral-300" /> NPWP Pemohon sudah diterima</label>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="f.sprs.ktp_pasangan_received" class="h-4 w-4 rounded border-neutral-300" /> KTP Pasangan sudah diterima</label>
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="f.sprs.npwp_pasangan_received" class="h-4 w-4 rounded border-neutral-300" /> NPWP Pasangan sudah diterima</label>
                            </div>
                        </div>

                        <p v-if="!stageComplete('sprs')" class="text-xs text-warning-600">Centang keempat dokumen (KTP/NPWP Pemohon &amp; Pasangan) sudah diterima untuk lanjut ke BI Checking.</p>
                        <Button :loading="saving" :disabled="!stageComplete('sprs')" @click="save('sprs', f.sprs)">Simpan &amp; Lanjut → BI Checking</Button>
                    </div>

                    <!-- 3. BI CHECKING -->
                    <form v-else-if="st.key === 'bi_checking'" @submit.prevent="save('bi_checking', f.bi_checking)" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label-base">Hasil BI Checking</label>
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
                            <p class="font-semibold text-danger-700 dark:text-danger-300">✗ BI Checking di-REJECT — proses dibatalkan.</p>
                            <p v-if="a.stages.bi_checking.reject_reason" class="mt-0.5 text-xs text-danger-600">Alasan: {{ a.stages.bi_checking.reject_reason }}</p>
                            <p v-if="a.stages.bi_checking.rejected_by_name" class="mt-0.5 text-[11px] text-neutral-500">oleh {{ a.stages.bi_checking.rejected_by_name }} · {{ fmtDateTime(a.stages.bi_checking.rejected_at) }}</p>
                        </div>
                        <template v-else>
                            <p v-if="!stageComplete('bi_checking')" class="text-xs text-warning-600">Pilih Hasil BI Checking untuk lanjut, atau Reject BI Checking untuk membatalkan proses.</p>
                            <div class="flex flex-wrap gap-2">
                                <Button type="submit" :loading="saving" :disabled="!stageComplete('bi_checking')">Simpan &amp; Lanjut →</Button>
                                <Button type="button" variant="danger" :loading="saving" @click="reject">REJECT BI CHECKING</Button>
                            </div>
                            <p class="text-[11px] text-neutral-400">Simpan &amp; Lanjut dan Reject BI Checking bersifat pilihan — yang sudah di-Reject prosesnya batal &amp; tidak bisa dilanjutkan.</p>
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
                            <p class="text-xs text-neutral-500">Tandai tiap berkas <b>Lengkap</b> / <b>Tidak Lengkap</b>. Untuk yang Tidak Lengkap, isi catatan (di sebelah kanan). Unggah file (maks 10 MB) bila ada — tanggal &amp; jam unggah tercatat otomatis.</p>
                            <div class="space-y-2">
                                <div v-for="(it, i) in currentItems" :key="bkey(it.key)" class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        <div class="min-w-[180px] flex-1 text-sm font-medium">{{ i + 1 }}. {{ it.label }}</div>
                                        <!-- Status lengkap / tidak lengkap -->
                                        <div class="flex gap-1.5">
                                            <button type="button" @click="f.collect_berkas.items[bkey(it.key)].status = 'lengkap'"
                                                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                                    :class="f.collect_berkas.items[bkey(it.key)]?.status === 'lengkap' ? 'border-success-500 bg-success-500 text-white' : 'border-success-300 text-success-700 hover:bg-success-50 dark:border-success-700'">✓ Lengkap</button>
                                            <button type="button" @click="f.collect_berkas.items[bkey(it.key)].status = 'tidak_lengkap'"
                                                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                                    :class="f.collect_berkas.items[bkey(it.key)]?.status === 'tidak_lengkap' ? 'border-danger-500 bg-danger-500 text-white' : 'border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700'">✗ Tidak Lengkap</button>
                                        </div>
                                        <!-- Catatan: aktif hanya bila Tidak Lengkap -->
                                        <input v-model="f.collect_berkas.items[bkey(it.key)].note"
                                               :disabled="f.collect_berkas.items[bkey(it.key)]?.status !== 'tidak_lengkap'"
                                               :placeholder="f.collect_berkas.items[bkey(it.key)]?.status === 'tidak_lengkap' ? 'Catatan: kenapa belum lengkap…' : 'Catatan (aktif bila Tidak Lengkap)'"
                                               class="h-8 min-w-[160px] flex-1 rounded-lg border border-neutral-300 px-2 text-xs disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900 dark:disabled:bg-neutral-800" />
                                        <!-- Upload -->
                                        <button type="button" @click="triggerUpload(bkey(it.key))" :disabled="uploadingKey === bkey(it.key)"
                                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 disabled:opacity-60 dark:border-neutral-700 dark:text-neutral-300">
                                            <Upload class="h-3.5 w-3.5" /> {{ uploadingKey === bkey(it.key) ? 'Mengunggah…' : (itemFile(bkey(it.key))?.uploaded_at ? 'Ganti File' : 'Upload') }}
                                        </button>
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

                    <!-- 5. ANALISA -->
                    <form v-else-if="st.key === 'analisa'" @submit.prevent="save('analisa', f.analisa)" class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Input v-model="f.analisa.pekerjaan" label="Pekerjaan" />
                            <div>
                                <label class="label-base">Status Pekerjaan</label>
                                <select v-model="f.analisa.status_pekerjaan" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option value="tetap">Karyawan Tetap</option>
                                    <option value="kontrak">Karyawan Kontrak</option>
                                    <option value="wirausaha">Wirausaha</option>
                                    <option value="profesional">Profesional</option>
                                    <option value="lainnya">Lainnya</option>
                                </select>
                            </div>
                            <Input v-model="f.analisa.penghasilan" type="number" label="Penghasilan / bln (Rp)" />
                            <Input v-model="f.analisa.lama_kerja" label="Lama Bekerja" />
                            <Input v-model="f.analisa.estimasi_bayar" type="number" label="Estimasi Kemampuan Membayar / bln (Rp)" />
                            <Input v-model="f.analisa.umur" type="number" label="Umur Customer Saat Ini (tahun)" />
                            <div class="sm:col-span-2">
                                <label class="label-base">Hasil Analisa</label>
                                <select v-model="f.analisa.result" class="input-base">
                                    <option value="">— pilih —</option>
                                    <option value="layak">Layak</option>
                                    <option value="tidak_layak">Tidak Layak</option>
                                </select>
                            </div>
                        </div>

                        <!-- R4 — Kolom berkas (sama spt Collect Berkas) + kategori Karyawan/Wirausaha (read-only) -->
                        <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700 space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold">Analisa Berkas</p>
                                <Badge v-if="analisaBerkas.category" variant="info" size="sm">{{ analisaBerkas.label }}</Badge>
                            </div>
                            <p v-if="!analisaBerkas.category" class="text-xs text-warning-600">Kategori &amp; berkas belum diisi pada tahap Collect Berkas.</p>
                            <div v-else class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs uppercase text-neutral-500"><tr>
                                        <th class="py-1.5 pr-3">Berkas</th><th class="py-1.5 text-center">Status</th>
                                    </tr></thead>
                                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        <tr v-for="(it, i) in analisaBerkas.items" :key="i">
                                            <td class="py-1.5 pr-3">{{ i + 1 }}. {{ it.label }}</td>
                                            <td class="py-1.5 text-center">
                                                <Badge v-if="it.status === 'lengkap'" variant="success" size="sm">Lengkap</Badge>
                                                <Badge v-else-if="it.status === 'tidak_lengkap'" variant="danger" size="sm">Tidak Lengkap</Badge>
                                                <span v-else class="text-xs text-neutral-400">—{{ it.optional ? ' (opsional)' : '' }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- R4 — Indikator dikategorikan HIGH / MEDIUM / LOW (muncul di Profiling) -->
                        <div>
                            <label class="label-base">Indikator Analisa <span class="text-danger-500">*</span></label>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="ind in ['high','medium','low']" :key="ind" type="button" @click="f.analisa.indikator = ind"
                                        class="rounded-lg border px-4 py-2 text-sm font-bold uppercase transition"
                                        :class="f.analisa.indikator === ind
                                            ? (ind === 'high' ? 'border-success-500 bg-success-500 text-white' : ind === 'medium' ? 'border-warning-500 bg-warning-500 text-white' : 'border-danger-500 bg-danger-500 text-white')
                                            : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300'">
                                    {{ indikatorLabel[ind] }}
                                </button>
                            </div>
                        </div>

                        <Input v-model="f.analisa.notes" label="Catatan Analisa" />
                        <p v-if="!stageComplete('analisa')" class="text-xs text-warning-600">Pilih Hasil Analisa &amp; Indikator (HIGH/MEDIUM/LOW) untuk lanjut.</p>
                        <Button type="submit" :loading="saving" :disabled="!stageComplete('analisa')">Simpan &amp; Lanjut →</Button>
                    </form>

                    <!-- 6. PROFILING (R5) — indikator + rekomendasi bank + keputusan 2 bank + SPR -->
                    <form v-else-if="st.key === 'profiling'" @submit.prevent="save('profiling', f.profiling)" class="space-y-4">
                        <!-- Keterangan indikator HIGH/MEDIUM/LOW (dari Analisa) -->
                        <div class="rounded-lg border border-neutral-200 px-4 py-3 dark:border-neutral-700">
                            <span class="text-sm text-neutral-500">Indikator Analisa Berkas: </span>
                            <Badge v-if="a.stages?.analisa?.indikator" :variant="a.stages.analisa.indikator === 'high' ? 'success' : a.stages.analisa.indikator === 'medium' ? 'warning' : 'danger'">{{ indikatorLabel[a.stages.analisa.indikator] }}</Badge>
                            <span v-else class="text-sm text-warning-600">— belum ada (isi di tahap Analisa) —</span>
                        </div>

                        <!-- Rekomendasi Bank oleh Customer (×2) -->
                        <div>
                            <label class="label-base">Rekomendasi Bank oleh Customer</label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 1</label><select v-model="f.profiling.bank_customer_1" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM_CUSTOMER" :key="b" :value="b">{{ b }}</option></select></div>
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 2</label><select v-model="f.profiling.bank_customer_2" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM_CUSTOMER" :key="b" :value="b">{{ b }}</option></select></div>
                            </div>
                        </div>

                        <!-- Rekomendasi Bank dari Analisa Berkas (×2) -->
                        <div>
                            <label class="label-base">Rekomendasi Bank dari Analisa Berkas</label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 1</label><select v-model="f.profiling.bank_analisa_1" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM" :key="b" :value="b">{{ b }}</option></select></div>
                                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pilihan 2</label><select v-model="f.profiling.bank_analisa_2" class="input-base"><option value="">— pilih bank —</option><option v-for="b in BANK_REKOM" :key="b" :value="b">{{ b }}</option></select></div>
                            </div>
                        </div>

                        <!-- Keputusan: 2 bank yang dilanjutkan Proses KPR + checklist SPR sudah terbit -->
                        <div class="rounded-lg border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-900/40 dark:bg-primary-900/10 space-y-3">
                            <p class="text-sm font-semibold">Keputusan — Bank yang Dilanjutkan Proses KPR (maks 2)</p>
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
