<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, PieChart, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { year: number; budget: number; realisasi: number; sisa: number; lines: number; pending: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// Sub-modul Budget Control â€” dikelompokkan persis seperti menu eProperty BO (/BC/).
const menu: Array<{ group: string; items: Array<{ label: string; route: string | null; summary?: string | null }> }> = [
    // "General Function → Print-Out" dihapus: isinya hanya tautan ke laporan yang sudah
    // ada satu per satu di grup Query.
    { group: 'Master', items: [
        { label: 'Budget Control', route: r('bs.budget.index') },
        { label: 'Alokasi Budget Control', route: r('bs.budget.allocation.index') },
        { label: 'Koreksi Budget Control', route: r('bs.budget.correction.index') },
    ]},
    { group: 'Query', items: [
        { label: 'Master Budget', route: r('bs.budget.query.master') },
        { label: 'Koreksi Budget', route: r('bs.budget.query.correction') },
        { label: 'Realisasi Budget', route: r('bs.budget.query.realization') },
        { label: 'Alokasi Budget', route: r('bs.budget.query.allocation') },
    ]},
    { group: 'Upload Budget', items: [
        { label: 'Upload Budget', route: r('bs.budget.upload.index') },
        { label: 'Approval Budget', route: r('bs.budget.approvals') },
    ]},
    { group: 'Alokasi Budget', items: [
        { label: 'Alokasi Budget', route: r('bs.budget.allocation.create') },
        { label: 'Approval Alokasi Budget', route: r('bs.budget.allocation.approvals') },
    ]},
    { group: 'Koreksi Budget', items: [
        { label: 'Koreksi Budget', route: r('bs.budget.correction.create') },
        { label: 'Approval Koreksi Budget', route: r('bs.budget.correction.approvals') },
    ]},
];

const totalItems = menu.reduce((n, g) => n + g.items.length, 0);
const builtItems = menu.reduce((n, g) => n + g.items.filter(i => i.route).length, 0);

const open = ref<Record<string, boolean>>({});
function toggle(g: string) { open.value[g] = !open.value[g]; }
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Dashboard</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><PieChart class="h-6 w-6 text-primary-600" /> Budget Control</h1>
            <Link :href="route('bs.budget.upload.index')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Upload Budget</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Modul anggaran (per cost center, bulanan) â€” {{ totalItems }} sub-modul ({{ builtItems }} aktif, {{ totalItems - builtItems }} dalam pembangunan). Klik sub-modul untuk melihat detailnya.</p>

        <!-- Ringkasan anggaran tahun berjalan -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Anggaran {{ summary.year }}</div><div class="mt-1 font-mono text-xl font-bold">{{ fmtRp(summary.budget) }}</div><div class="text-xs text-neutral-500">{{ summary.lines }} baris</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi</div><div class="mt-1 font-mono text-xl font-bold text-primary-600">{{ fmtRp(summary.realisasi) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Anggaran</div><div class="mt-1 font-mono text-xl font-bold" :class="summary.sisa >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(summary.sisa) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Menunggu Approval</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.pending }}</div></div>
        </div>

        <!-- Direktori sub-modul -->
        <div class="grid gap-3 md:grid-cols-2">
            <div v-for="g in menu" :key="g.group" class="card overflow-hidden">
                <button type="button" @click="toggle(g.group)" class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <span class="text-xs font-semibold uppercase tracking-wider text-accent-600">{{ g.group }}</span>
                    <span class="flex items-center gap-2"><span class="text-xs text-neutral-400">{{ g.items.length }}</span><ChevronDown class="h-4 w-4 text-neutral-400 transition-transform" :class="{ 'rotate-180': open[g.group] }" /></span>
                </button>
                <ul v-show="open[g.group]" class="border-t border-neutral-200 px-4 py-2 dark:border-neutral-800">
                    <li v-for="it in g.items" :key="it.label" class="flex items-center justify-between gap-2 rounded px-1 py-1.5 text-sm">
                        <a v-if="it.route" :href="it.route" class="flex-1 text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                        <span v-else class="flex-1 text-neutral-400">{{ it.label }}</span>
                        <span class="flex items-center gap-2"><a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a><Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge><span v-else class="text-primary-400">â†’</span></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

