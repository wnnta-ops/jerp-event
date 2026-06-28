<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, ShieldX, Printer, Check, X, Receipt, FileSearch, Landmark } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ init: any; stages: Record<string, any> }>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v ?? 0); }
function fmtDateTime(iso: string | null | undefined) {
    return iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
}
const biLabelMap: Record<string, string> = { clear: 'Clear', kol1: 'Kol 1', kol2: 'Kol 2', kol3: 'Kol 3', kol4: 'Kol 4', kol5: 'Kol 5', caution: 'Perlu Perhatian', reject: 'Ditolak' };
function biLabel(r: string | null) { return r ? (biLabelMap[r] ?? r) : '—'; }
const permintaanMap: Record<string, string> = { lanjut_spr: 'Lanjut Pemberkasan SPR', mundur: 'Mundur / Reject' };

const sprs = computed(() => props.stages?.sprs ?? {});
const bi = computed(() => props.stages?.bi_checking ?? {});
const ttsStage = computed(() => props.stages?.kuitansi ?? {});

const sprsChecks = computed(() => [
    { label: 'KTP Pemohon diterima', ok: !!sprs.value.ktp_pemohon_received },
    { label: 'NPWP Pemohon diterima', ok: !!sprs.value.npwp_pemohon_received },
    { label: 'KTP Pasangan diterima', ok: !!sprs.value.ktp_pasangan_received },
    { label: 'NPWP Pasangan diterima', ok: !!sprs.value.npwp_pasangan_received },
]);

function doPrint() { window.print(); }
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex items-center justify-between print:hidden">
            <Link :href="route('kpr.database.reject-bi')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Reject BI Checking</Link>
            <button @click="doPrint" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Cetak</button>
        </div>

        <!-- Header report -->
        <div class="card p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-xl font-bold"><ShieldX class="h-6 w-6 text-danger-600" /> Report Inisiasi KPR — Reject BI Checking</h1>
                    <p class="mt-0.5 text-sm text-neutral-500">{{ init.customer }} · <span class="font-mono">{{ init.number }}</span></p>
                </div>
                <Badge variant="danger" dot>Ditolak (BI Checking)</Badge>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-neutral-500">No. TTS</dt><dd class="font-mono">{{ init.tts ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Unit</dt><dd>{{ init.unit ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Booking</dt><dd class="font-mono">{{ init.booking ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Plafon Diajukan</dt><dd class="font-mono">{{ fmtRp(init.plafon) }}</dd></div>
                <div><dt class="text-neutral-500">Telp</dt><dd>{{ init.phone ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Inisiasi dibuat</dt><dd>{{ fmtDateTime(init.created_at) }}</dd></div>
            </dl>
        </div>

        <!-- Ringkasan proses: TTS → SPRS → BI Checking -->
        <div class="card p-5 space-y-4">
            <h2 class="text-lg font-bold">Ringkasan Proses</h2>

            <!-- 1. TTS -->
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-2 font-semibold"><Receipt class="h-4 w-4 text-primary-600" /> 1. TTS (Sumber)</p>
                    <span class="text-xs text-neutral-400">{{ fmtDateTime(ttsStage.at) }}</span>
                </div>
                <p class="mt-1 text-sm text-neutral-500">TTS {{ init.tts ?? '—' }} dikonfirmasi sebagai sumber inisiasi.</p>
            </div>

            <!-- 2. SPRS -->
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-2 font-semibold"><FileSearch class="h-4 w-4 text-primary-600" /> 2. SPRS</p>
                    <span class="text-xs text-neutral-400">{{ fmtDateTime(sprs.at) }}</span>
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <div><dt class="text-neutral-500">No. SPRS</dt><dd>{{ sprs.number || '—' }}</dd></div>
                    <div><dt class="text-neutral-500">Tanggal SPRS</dt><dd>{{ sprs.date || '—' }}</dd></div>
                </dl>
                <div class="mt-2 grid grid-cols-2 gap-1 text-sm">
                    <div v-for="(c, i) in sprsChecks" :key="i" class="flex items-center gap-1.5">
                        <Check v-if="c.ok" class="h-4 w-4 text-success-500" /><X v-else class="h-4 w-4 text-neutral-300" />
                        <span :class="c.ok ? '' : 'text-neutral-400'">{{ c.label }}</span>
                    </div>
                </div>
                <p v-if="sprs.notes" class="mt-1 text-xs text-neutral-500">Catatan: {{ sprs.notes }}</p>
                <p v-if="sprs.docs?.verified" class="mt-1 text-xs"><Badge :variant="sprs.docs.verdict === 'match' ? 'success' : 'danger'" size="sm">OCR: {{ sprs.docs.verdict === 'match' ? 'nama sesuai' : 'nama tidak sesuai' }}</Badge></p>
            </div>

            <!-- 3. BI Checking (REJECT) -->
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-900/20">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-2 font-semibold text-danger-700 dark:text-danger-300"><Landmark class="h-4 w-4" /> 3. BI Checking — DITOLAK</p>
                    <span class="text-xs text-neutral-400">{{ fmtDateTime(bi.rejected_at) }}</span>
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <div><dt class="text-neutral-500">Hasil BI Checking</dt><dd><Badge :variant="bi.result ? 'warning' : 'neutral'" size="sm">{{ biLabel(bi.result) }}</Badge></dd></div>
                    <div><dt class="text-neutral-500">Permintaan Customer</dt><dd>{{ permintaanMap[bi.permintaan] ?? bi.permintaan ?? '—' }}</dd></div>
                </dl>
                <p class="mt-2 text-sm"><span class="text-neutral-500">Alasan penolakan:</span> <span class="font-medium text-danger-700 dark:text-danger-300">{{ bi.reject_reason || '— tanpa alasan —' }}</span></p>
                <p v-if="bi.notes" class="mt-1 text-xs text-neutral-500">Catatan BI: {{ bi.notes }}</p>
                <p class="mt-1 text-[11px] text-neutral-500">Ditolak oleh {{ bi.rejected_by_name ?? '—' }} · {{ fmtDateTime(bi.rejected_at) }}</p>
            </div>
        </div>
    </div>
</template>
