<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText, FileClock, Hourglass, CheckCircle2, Wallet, FileSpreadsheet, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

interface Row { no: string; date: string | null; holder: string | null; department: string | null; purpose: string | null; amount: number; realized: number; outstanding: number; status: string }
const props = defineProps<{ rows: Row[]; statuses: Record<string, string> }>();

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function statusLabel(s: string) { return props.statuses[s] ?? s; }

// ── Filter per-kolom (checklist) + periode tanggal ──
const fNo = ref<string[]>([]);
const fHolder = ref<string[]>([]);
const fPurpose = ref<string[]>([]);
const fStatus = ref<string[]>([]);
const from = ref('');
const to = ref('');

const uniq = (arr: (string | null)[]) => [...new Set(arr.map((v) => v ?? ''))].sort((a, b) => a.localeCompare(b));
const noOptions = computed(() => uniq(props.rows.map((r) => r.no)));
const holderOptions = computed(() => uniq(props.rows.map((r) => r.holder)));
const purposeOptions = computed(() => uniq(props.rows.map((r) => r.purpose)));
const statusOptions = computed(() => uniq(props.rows.map((r) => statusLabel(r.status))));

const pass = (val: string, sel: string[]) => sel.length === 0 || sel.includes(val);
const filteredRows = computed(() => props.rows.filter((r) =>
    pass(r.no ?? '', fNo.value)
    && pass(r.holder ?? '', fHolder.value)
    && pass(r.purpose ?? '', fPurpose.value)
    && pass(statusLabel(r.status), fStatus.value)
    && (!from.value || (r.date ?? '') >= from.value)
    && (!to.value || (r.date ?? '') <= to.value)));

const anyFilter = computed(() => fNo.value.length || fHolder.value.length || fPurpose.value.length || fStatus.value.length || from.value || to.value);
function resetFilters() { fNo.value = []; fHolder.value = []; fPurpose.value = []; fStatus.value = []; from.value = ''; to.value = ''; }

// #9: parameter unduhan = filter layar (pemegang, status, rentang tgl). fStatus menyimpan LABEL → petakan balik ke key.
const exportParams = computed<Record<string, any>>(() => {
    const p: Record<string, any> = {};
    if (fHolder.value.length) p.holders = fHolder.value;
    if (fStatus.value.length) {
        const keys = Object.entries(props.statuses).filter(([, label]) => fStatus.value.includes(label)).map(([k]) => k);
        if (keys.length) p.statuses = keys;
    }
    if (from.value) p.from = from.value;
    if (to.value) p.to = to.value;
    return p;
});

// ── Ringkasan reaktif terhadap filter ──
const totals = computed(() => ({
    amount: filteredRows.value.reduce((s, r) => s + Number(r.amount), 0),
    realized: filteredRows.value.reduce((s, r) => s + Number(r.realized), 0),
    outstanding: filteredRows.value.reduce((s, r) => s + Number(r.outstanding), 0),
    count: filteredRows.value.length,
}));

const STATUS_KEYS = ['requested', 'outstanding', 'realized'] as const;
const statusMeta: Record<string, { label: string; icon: any; text: string; bar: string; soft: string; ring: string }> = {
    requested:   { label: 'Diajukan',    icon: FileClock,   text: 'text-sky-600 dark:text-sky-400',       bar: 'bg-sky-500',     soft: 'bg-sky-50 dark:bg-sky-900/15',       ring: 'border-sky-200 dark:border-sky-900/40' },
    outstanding: { label: 'Outstanding', icon: Hourglass,   text: 'text-amber-600 dark:text-amber-400',   bar: 'bg-amber-500',   soft: 'bg-amber-50 dark:bg-amber-900/15',   ring: 'border-amber-200 dark:border-amber-900/40' },
    realized:    { label: 'Realized',    icon: CheckCircle2, text: 'text-emerald-600 dark:text-emerald-400', bar: 'bg-emerald-500', soft: 'bg-emerald-50 dark:bg-emerald-900/15', ring: 'border-emerald-200 dark:border-emerald-900/40' },
};
const byStatus = computed(() => {
    const m: Record<string, { count: number; amount: number; outstanding: number }> = {};
    for (const k of STATUS_KEYS) m[k] = { count: 0, amount: 0, outstanding: 0 };
    for (const r of filteredRows.value) {
        const k = r.status; if (!m[k]) m[k] = { count: 0, amount: 0, outstanding: 0 };
        m[k].count++; m[k].amount += Number(r.amount); m[k].outstanding += Number(r.outstanding);
    }
    return m;
});
function pct(part: number) { return totals.value.amount > 0 ? Math.round((part / totals.value.amount) * 1000) / 10 : 0; }
// Segmen terakhir = sisa agar bar pas 100% (hindari celah/klip akibat pembulatan per-segmen).
function barWidth(k: string) {
    const i = STATUS_KEYS.indexOf(k as typeof STATUS_KEYS[number]);
    if (i < STATUS_KEYS.length - 1) return pct(byStatus.value[k].amount);
    const used = STATUS_KEYS.slice(0, -1).reduce((s, kk) => s + pct(byStatus.value[kk].amount), 0);
    return Math.max(0, Math.round((100 - used) * 10) / 10);
}

const statusColor: Record<string, any> = { requested: 'info', outstanding: 'warning', realized: 'success' };
const inputCls = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> Kartu Bon Sementara</h1>
            <div class="flex flex-wrap items-center gap-2">
                <!-- #9: Excel + PDF mengikuti filter layar (pemegang, status, rentang tgl) -->
                <a :href="route('bs.advance.card.excel', exportParams)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.advance.card.pdf', exportParams)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> PDF</a>
                <button v-if="anyFilter" type="button" @click="resetFilters" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Reset filter</button>
            </div>
        </div>

        <!-- Ringkasan per status (reaktif terhadap filter) -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="k in STATUS_KEYS" :key="k" class="card border p-4" :class="statusMeta[k].ring">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide" :class="statusMeta[k].text"><component :is="statusMeta[k].icon" class="h-4 w-4" /> {{ statusMeta[k].label }}</div>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="[statusMeta[k].soft, statusMeta[k].text]">{{ byStatus[k].count }} bon</span>
                </div>
                <div class="mt-1.5 font-mono text-xl font-bold" :class="statusMeta[k].text">Rp {{ fmtRp(byStatus[k].amount) }}</div>
                <div class="mt-0.5 text-[11px] text-neutral-400">{{ pct(byStatus[k].amount) }}% dari total nilai</div>
            </div>
            <div class="card border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-900/40 dark:bg-primary-900/10">
                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-primary-700 dark:text-primary-300"><Wallet class="h-4 w-4" /> Total Outstanding</div>
                <div class="mt-1.5 font-mono text-xl font-bold text-amber-600 dark:text-amber-400">Rp {{ fmtRp(totals.outstanding) }}</div>
                <div class="mt-0.5 text-[11px] text-neutral-400">{{ totals.count }} bon · nilai Rp {{ fmtRp(totals.amount) }}</div>
            </div>
        </div>

        <!-- Ratio bar komposisi nilai per status -->
        <div v-if="totals.amount > 0" class="card p-4">
            <div class="mb-2 flex items-center justify-between text-xs text-neutral-500"><span class="font-medium">Komposisi Nilai per Status</span><span>Total Rp {{ fmtRp(totals.amount) }}</span></div>
            <div class="flex h-3 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                <div v-for="k in STATUS_KEYS" :key="k" class="h-full transition-all" :class="statusMeta[k].bar" :style="{ width: barWidth(k) + '%' }" :title="`${statusMeta[k].label}: Rp ${fmtRp(byStatus[k].amount)}`"></div>
            </div>
            <div class="mt-2 flex flex-wrap gap-4 text-xs">
                <div v-for="k in STATUS_KEYS" :key="k" class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm" :class="statusMeta[k].bar"></span>
                    <span class="text-neutral-500">{{ statusMeta[k].label }}</span>
                    <span class="font-semibold" :class="statusMeta[k].text">{{ pct(byStatus[k].amount) }}%</span>
                </div>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No Bon</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Pemegang</th><th class="px-4 py-3">Keperluan</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-right">Realisasi</th><th class="px-4 py-3 text-right">Outstanding</th><th class="px-4 py-3 text-center">Status</th>
                    </tr>
                    <!-- Baris filter per-kolom -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-3 py-2"><ColumnFilter v-model="fNo" :options="noOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"><div class="flex flex-col gap-1"><input v-model="from" type="date" :class="inputCls" title="Dari tanggal" /><input v-model="to" type="date" :class="inputCls" title="Sampai tanggal" /></div></th>
                        <th class="px-3 py-2"><ColumnFilter v-model="fHolder" :options="holderOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"><ColumnFilter v-model="fPurpose" :options="purposeOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"></th><th class="px-3 py-2"></th><th class="px-3 py-2"></th>
                        <th class="px-3 py-2"><ColumnFilter v-model="fStatus" :options="statusOptions" placeholder="Semua" align="right" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in filteredRows" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.no }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5">{{ r.holder }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.realized) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold" :class="r.outstanding > 0 ? 'text-amber-600' : ''">{{ fmtRp(r.outstanding) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="statusColor[r.status] ?? 'neutral'" size="sm">{{ statusLabel(r.status) }}</Badge></td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800"><tr>
                    <td class="px-4 py-3" colspan="4">TOTAL ({{ totals.count }} bon)</td>
                    <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.amount) }}</td>
                    <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.realized) }}</td>
                    <td class="px-4 py-3 text-right font-mono text-amber-600">{{ fmtRp(totals.outstanding) }}</td>
                    <td></td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!filteredRows.length" :title="rows.length ? 'Tidak ada data sesuai filter' : 'Belum ada bon sementara'" />
        </div>
    </div>
</template>
