<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins, Plus, ChevronDown } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { outstanding: number; overdue: number; due_soon: number; count_open: number; count_total: number; suppliers: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
// 9 sub-modul AP — persis menu eProperty BO (Acc. Payable). Detail muncul saat sub-modul diklik.
const menu: Array<{ group: string; items: Item[] }> = [
    { group: 'Setup', items: [
        { label: 'Kategori Invoice', route: r('bs.ap.categories.index') },
        { label: 'Repair Data', route: r('bs.ap.repair.index') },
        { label: 'Kelengkapan Berkas', route: r('bs.ap.berkas.index') },
    ]},
    { group: 'Master', items: [
        { label: 'Invoice Hutang', route: r('bs.ap.index') },
        { label: 'Kuitansi Hutang', route: r('bs.ap.kuitansi.index') },
    ]},
    { group: 'Query', items: [
        { label: 'Kartu Hutang', route: r('bs.ap.card'), summary: r('bs.ap.card', { view: 'summary' }) },
        { label: 'Aging Hutang', route: r('bs.ap.aging', { view: 'detail' }), summary: r('bs.ap.aging', { view: 'summary' }) },
        { label: 'Proyeksi Hutang', route: r('bs.projection', { mode: 'hutang' }), summary: r('bs.projection', { mode: 'hutang', view: 'summary' }) },
        { label: 'Kartu Pembayaran Hutang', route: r('bs.ap.payment-card') },
        { label: 'Laporan Invoice Hutang', route: r('bs.ap.report') },
        { label: 'Rekap PPh Dipotong', route: r('bs.tax.pph') },
    ]},
    { group: 'Generate Invoice', items: [
        { label: 'Registrasi Invoice Hutang', route: r('bs.ap.create') },
    ]},
    { group: 'Penyesuaian Invoice', items: [
        { label: 'Penghapusan Nilai Hutang', route: r('bs.ap.penghapusan.index') },
    ]},
    { group: 'Posting Invoice dari FO', items: [
        { label: 'Posting Invoice dari FO', route: r('bs.ap.posting.index', { source: 'po' }) },
    ]},
    { group: 'Posting Invoice SPK', items: [
        { label: 'Posting Invoice', route: r('bs.ap.posting.index', { source: 'spk' }) },
    ]},
    { group: 'Saldo Awal', items: [
        { label: 'Saldo Awal', route: r('bs.ap.opening.index') },
    ]},
];

// Accordion — semua tertutup; detail muncul saat sub-modul diklik.
const open = ref<Record<string, boolean>>({});
function toggle(g: string) { open.value[g] = !open.value[g]; }
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Dashboard</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-primary-600" /> Account Payable</h1>
            <Link :href="route('bs.ap.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Registrasi Invoice Hutang</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Modul hutang usaha — {{ menu.length }} sub-modul. Klik sub-modul untuk melihat detailnya.</p>

        <!-- Ringkasan hutang -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Outstanding</div><div class="mt-1 font-mono text-xl font-bold">{{ fmtRp(summary.outstanding) }}</div><div class="text-xs text-neutral-500">{{ summary.count_open }} invoice open</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jatuh Tempo ≤30 hari</div><div class="mt-1 font-mono text-xl font-bold text-amber-600">{{ fmtRp(summary.due_soon) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Terlambat (overdue)</div><div class="mt-1 font-mono text-xl font-bold text-danger-600">{{ fmtRp(summary.overdue) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Invoice / Supplier</div><div class="mt-1 font-mono text-xl font-bold">{{ summary.count_total }} / {{ summary.suppliers }}</div></div>
        </div>

        <!-- 9 sub-modul (accordion) -->
        <div class="grid gap-3 md:grid-cols-2">
            <div v-for="g in menu" :key="g.group" class="card overflow-hidden">
                <button type="button" @click="toggle(g.group)" class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <span class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wider text-accent-600">{{ g.group }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-xs text-neutral-400">{{ g.items.length }}</span>
                        <ChevronDown class="h-4 w-4 text-neutral-400 transition-transform" :class="{ 'rotate-180': open[g.group] }" />
                    </span>
                </button>
                <ul v-show="open[g.group]" class="border-t border-neutral-200 px-4 py-2 dark:border-neutral-800">
                    <li v-for="it in g.items" :key="it.label" class="flex items-center justify-between gap-2 rounded px-1 py-1.5 text-sm">
                        <a v-if="it.route" :href="it.route" class="flex-1 text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                        <span v-else class="flex-1 text-neutral-400">{{ it.label }}</span>
                        <span class="flex items-center gap-2">
                            <a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a>
                            <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                            <span v-else class="text-primary-400">→</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
