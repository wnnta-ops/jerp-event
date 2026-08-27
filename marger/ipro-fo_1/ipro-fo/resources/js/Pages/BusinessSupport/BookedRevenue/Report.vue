<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart, Home, CheckCircle2, Stamp, Receipt, FileCheck, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    filters: { search?: string; status?: string };
    summary: { units: number; with_bast: number; with_ajb: number; with_fp: number; br_total: number; retensi_total: number; utjdp_total: number; fp_total: number };
    statuses: string[];
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtRpc(v: number) { return v ? 'Rp ' + Number(v).toLocaleString('id-ID') : '—'; }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function statusMeta(s: string): { label: string; variant: 'warning' | 'success' | 'neutral' } {
    return ({ booked: { label: 'Booked', variant: 'warning' }, recognized: { label: 'Diakui', variant: 'success' }, cancelled: { label: 'Batal', variant: 'neutral' } } as Record<string, any>)[s] ?? { label: s, variant: 'neutral' };
}
function pct(a: number, b: number) { return b > 0 ? Math.round((a / b) * 100) : 0; }

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('bs.booked-revenue.report'), { search: val.search || undefined, status: val.status || undefined }, { preserveState: true, replace: true }), 300);
});
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const g1 = 'bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300';
const g2 = 'bg-sky-50 text-sky-700 dark:bg-sky-900/20 dark:text-sky-300';
const g3 = 'bg-cyan-50 text-cyan-700 dark:bg-cyan-900/20 dark:text-cyan-300';
const g4 = 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300';
const g5 = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Report / Query Booked Revenue</h1>
                <p class="text-sm text-neutral-500">Rekap milestone per unit — BAST · AJB · Retensi · UTJ &amp; DP · Faktur Pajak.</p>
            </div>
            <a :href="route('bs.booked-revenue.report.export', { search: filters.search, status: filters.status })" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
        </div>

        <!-- Ringkasan -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Home class="h-4 w-4" /> Total Unit</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ summary.units }}</div>
                <div class="mt-1 flex flex-wrap gap-x-3 text-[11px] text-neutral-500">
                    <span><FileCheck class="mr-0.5 inline h-3 w-3 text-sky-500" />BAST {{ summary.with_bast }} <span class="text-neutral-400">({{ pct(summary.with_bast, summary.units) }}%)</span></span>
                    <span><Stamp class="mr-0.5 inline h-3 w-3 text-info-500" />AJB {{ summary.with_ajb }} <span class="text-neutral-400">({{ pct(summary.with_ajb, summary.units) }}%)</span></span>
                    <span><Receipt class="mr-0.5 inline h-3 w-3 text-success-500" />FP {{ summary.with_fp }} <span class="text-neutral-400">({{ pct(summary.with_fp, summary.units) }}%)</span></span>
                </div>
            </div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><CheckCircle2 class="h-4 w-4" /> Nilai Booked Revenue</div><div class="mt-1 text-xl font-bold">{{ fmtRp(summary.br_total) }}</div></div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-400">Retensi &amp; UTJ &amp; DP</div>
                <div class="mt-1 text-xl font-bold text-info-600">{{ fmtRp(summary.retensi_total + summary.utjdp_total) }}</div>
                <div class="mt-1 text-[11px] text-neutral-500">Retensi {{ fmtRp(summary.retensi_total) }} · UTJ&amp;DP {{ fmtRp(summary.utjdp_total) }}</div>
            </div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Receipt class="h-4 w-4" /> Total Faktur Pajak</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(summary.fp_total) }}</div></div>
        </div>

        <!-- Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari unit / customer / no. BR…" :class="[inputCls, 'w-72']" />
            <select v-model="f.status" :class="inputCls">
                <option value="">Semua Status</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ statusMeta(s).label }}</option>
            </select>
        </div>

        <!-- Matriks per unit -->
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[1180px] text-sm">
                <thead class="text-xs uppercase text-neutral-500">
                    <tr class="border-b border-neutral-200 dark:border-neutral-800">
                        <th rowspan="2" class="px-3 py-2 text-left align-bottom">Unit</th>
                        <th rowspan="2" class="px-3 py-2 text-left align-bottom">Customer</th>
                        <th colspan="3" class="px-3 py-1.5 text-center" :class="g1">Booked Revenue</th>
                        <th rowspan="2" class="px-3 py-2 text-center align-bottom" :class="g2">BAST<div class="text-[10px] font-normal normal-case">Tgl Serah Terima</div></th>
                        <th rowspan="2" class="px-3 py-2 text-center align-bottom" :class="g2">AJB<div class="text-[10px] font-normal normal-case">Tgl / Notaris</div></th>
                        <th colspan="2" class="px-3 py-1.5 text-center" :class="g3">Retensi</th>
                        <th colspan="2" class="px-3 py-1.5 text-center" :class="g4">UTJ &amp; DP</th>
                        <th colspan="3" class="px-3 py-1.5 text-center" :class="g5">Faktur Pajak</th>
                    </tr>
                    <tr class="border-b border-neutral-200 text-left dark:border-neutral-800">
                        <th class="px-3 py-2" :class="g1">No. BR / Tgl</th>
                        <th class="px-3 py-2 text-right" :class="g1">Nilai</th>
                        <th class="px-3 py-2 text-center" :class="g1">Status</th>
                        <th class="px-3 py-2" :class="g3">Tanggal</th>
                        <th class="px-3 py-2 text-right" :class="g3">Nominal</th>
                        <th class="px-3 py-2" :class="g4">Tanggal</th>
                        <th class="px-3 py-2 text-right" :class="g4">Nominal</th>
                        <th class="px-3 py-2" :class="g5">Tanggal</th>
                        <th class="px-3 py-2 text-right" :class="g5">Nominal</th>
                        <th class="px-3 py-2" :class="g5">Nomor FP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5"><div class="font-mono font-semibold">{{ r.unit ?? '—' }}</div><div v-if="r.cluster" class="text-[11px] text-neutral-400">{{ r.cluster }}</div></td>
                        <td class="px-3 py-2.5"><div>{{ r.customer ?? '—' }}</div><div v-if="r.customer_code" class="font-mono text-[11px] text-neutral-400">{{ r.customer_code }}</div></td>
                        <td class="px-3 py-2.5"><div class="font-mono text-xs">{{ r.br_number }}</div><div class="text-[11px] text-neutral-400">{{ fmtDate(r.br_date) }}</div></td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.br_amount) }}</td>
                        <td class="px-3 py-2.5 text-center"><Badge :variant="statusMeta(r.br_status).variant" size="sm">{{ statusMeta(r.br_status).label }}</Badge></td>
                        <td class="px-3 py-2.5 text-center" :class="r.bast_date ? '' : 'text-neutral-300'">{{ fmtDate(r.bast_date) }}</td>
                        <td class="px-3 py-2.5 text-center" :class="r.ajb_date ? '' : 'text-neutral-300'"><div>{{ fmtDate(r.ajb_date) }}</div><div v-if="r.ajb_notary" class="text-[11px] text-neutral-400">{{ r.ajb_notary }}</div></td>
                        <td class="px-3 py-2.5" :class="r.retensi_date ? '' : 'text-neutral-300'">{{ fmtDate(r.retensi_date) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono" :class="r.retensi_amount ? 'text-info-600' : 'text-neutral-300'">{{ fmtRpc(r.retensi_amount) }}</td>
                        <td class="px-3 py-2.5" :class="r.utjdp_date ? '' : 'text-neutral-300'">{{ fmtDate(r.utjdp_date) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono" :class="r.utjdp_amount ? 'text-amber-600' : 'text-neutral-300'">{{ fmtRpc(r.utjdp_amount) }}</td>
                        <td class="px-3 py-2.5" :class="r.fp_date ? '' : 'text-neutral-300'">{{ fmtDate(r.fp_date) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono" :class="r.fp_amount ? 'text-success-600' : 'text-neutral-300'">{{ fmtRpc(r.fp_amount) }}</td>
                        <td class="px-3 py-2.5"><span v-if="r.fp_number" class="font-mono text-xs">{{ r.fp_number }}</span><span v-else class="text-xs italic text-neutral-300">belum</span></td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                        <td colspan="3" class="px-3 py-2.5 text-right">Total ({{ rows.length }} unit)</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(summary.br_total) }}</td>
                        <td colspan="4"></td>
                        <td class="px-3 py-2.5 text-right font-mono text-info-600">{{ fmtRp(summary.retensi_total) }}</td>
                        <td></td>
                        <td class="px-3 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(summary.utjdp_total) }}</td>
                        <td></td>
                        <td class="px-3 py-2.5 text-right font-mono text-success-600">{{ fmtRp(summary.fp_total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada data Booked Revenue" description="Report akan terisi saat ada unit yang di-booking beserta milestone BAST/AJB/Retensi/UTJ & DP/Faktur Pajak." />
        </div>
    </div>
</template>
