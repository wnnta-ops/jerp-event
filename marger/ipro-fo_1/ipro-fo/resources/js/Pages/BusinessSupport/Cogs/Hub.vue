<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Calculator, ChevronDown, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { budget: number; hpp_recognized: number; revenue_recognized: number; hpp_units: number; mut_pending: number; recognitions: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// Sub-modul COGS â€” dikelompokkan persis seperti menu eProperty BO (/COGS/).
const menu: Array<{ group: string; items: Array<{ label: string; route: string | null; summary?: string | null }> }> = [
    // "General Function → Print-Out" dihapus: isinya hanya tautan ke laporan yang sudah
    // ada satu per satu di grup Query.
    { group: 'Setup', items: [
        { label: 'Lokasi', route: r('bs.cogs.location.index') },
        { label: 'Ketentuan', route: r('bs.cogs.provision.index') },
        { label: 'Budget', route: r('bs.cogs.budget.index') },
        { label: 'Set Akun Budget', route: r('bs.cogs.category.index') },
        { label: 'Set COA Jenis Unit', route: r('bs.cogs.unitcoa.index') },
        { label: 'Upload Kategori Budget', route: r('bs.cogs.upload.form', { kind: 'kategori' }) },
        { label: 'Upload Akun Budget', route: r('bs.cogs.upload.form', { kind: 'akun' }) },
        { label: 'Upload Nilai Budget', route: r('bs.cogs.upload.form', { kind: 'nilai' }) },
    ]},
    { group: 'Master', items: [
        { label: 'Mutasi Budget', route: r('bs.cogs.mutation.index') },
        { label: 'HPP RealCost', route: r('bs.cogs.hpp.index') },
        { label: 'Saldo Awal Realisasi', route: r('bs.cogs.realization.index') },
    ]},
    { group: 'Query', items: [
        { label: 'Report Budget', route: r('bs.cogs.query.budget') },
        { label: 'Report Realisasi Budget', route: r('bs.cogs.query.realisasi') },
        { label: 'Report Realisasi Budget Detil', route: r('bs.cogs.query.realisasi-detil') },
        { label: 'Report Pengakuan HPP RealCost', route: r('bs.cogs.query.pengakuan') },
        { label: 'Report Persediaan Unit RealCost', route: r('bs.cogs.query.persediaan-unit') },
        { label: 'Report HPP Unit RealCost', route: r('bs.cogs.query.hpp-unit') },
    ]},
    { group: 'Mutasi Budget', items: [
        { label: 'Registrasi Mutasi Budget', route: r('bs.cogs.mutation.create') },
        { label: 'Approval Mutasi Budget', route: r('bs.cogs.mutation.approvals') },
    ]},
    { group: 'Transaksi HPP RealCost', items: [
        { label: 'Generate HPP', route: r('bs.cogs.hpp.generate') },
        { label: 'Posting Jurnal HPP Persediaan', route: r('bs.cogs.hpp.post-persediaan') },
        { label: 'Posting Jurnal HPP', route: r('bs.cogs.hpp.post-hpp') },
    ]},
    // Urutannya mengikuti sidebar: Komponen COGS berada DI BAWAH Transaksi HPP RealCost.
    // Hub ini adalah pintu masuk kedua ke modul COGS; sub-modul yang hanya ada di sidebar
    // akan tak terlihat oleh siapa pun yang masuk lewat sini.
    { group: 'Komponen COGS', items: [
        { label: 'Tanah', route: r('bs.cogs.komponen.show', { komponen: 'tanah' }) },
        { label: 'Housing', route: r('bs.cogs.komponen.show', { komponen: 'housing' }) },
        { label: 'Infrastruktur', route: r('bs.cogs.komponen.show', { komponen: 'infrastruktur' }) },
        { label: 'License Permit', route: r('bs.cogs.komponen.show', { komponen: 'license-permit' }) },
    ]},
    { group: 'Saldo Awal Realisasi', items: [
        { label: 'Input Saldo Awal Realisasi', route: r('bs.cogs.realization.create') },
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
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> Cost of Good Sold</h1>
            <Link :href="route('bs.cogs.hpp.generate')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Generate HPP</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Budget proyek &amp; HPP RealCost (POC) â€” {{ totalItems }} sub-modul ({{ builtItems }} aktif, {{ totalItems - builtItems }} dalam pembangunan). Klik sub-modul untuk melihat detailnya.</p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Budget Proyek</div><div class="mt-1 font-mono text-lg font-bold">{{ fmtRp(summary.budget) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">HPP Diakui</div><div class="mt-1 font-mono text-lg font-bold text-primary-600">{{ fmtRp(summary.hpp_recognized) }}</div><div class="text-xs text-neutral-500">{{ summary.hpp_units }} unit</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pendapatan Diakui</div><div class="mt-1 font-mono text-lg font-bold text-emerald-600">{{ fmtRp(summary.revenue_recognized) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Mutasi Menunggu</div><div class="mt-1 text-lg font-bold text-amber-600">{{ summary.mut_pending }}</div></div>
        </div>

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

