<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ChevronDown, HardHat, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { contract: number; progress: number; retention: number; active: number; spk: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// Sub-modul Contractor Management â€” dikelompokkan persis seperti menu eProperty BO (/PLAN/).
const menu: Array<{ group: string; items: Array<{ label: string; route: string | null; summary?: string | null }> }> = [
    { group: 'Setup', items: [
        { label: 'Project', route: r('bs.spk.project.index') },
        { label: 'Pasangan Unit MUK', route: r('bs.muk.pasangan.index') },
    ]},
    { group: 'Master', items: [
        // Baca-saja dari MUK ERP — sumber angka kontrak & penagihan SPK. Dipindah ke sini
        // bersama entri sidebarnya; rute & layarnya tetap di tempat semula.
        { label: 'Kontrak SPK (MUK)', route: r('bs.muk.kontrak.index') },
        { label: 'Informasi CTC', route: r('bs.muk.ctc.index') },
        { label: 'Tagihan Progress Project', route: r('bs.muk.tagihan.index') },
        { label: 'Surat Perintah Kerja', route: r('bs.spk.index') },
        { label: 'Kontrol Progress Lapangan', route: r('bs.spk.kpl.index') },
        { label: 'Progress Retensi', route: r('bs.spk.retention.index') },
    ]},
    { group: 'Query', items: [
        { label: 'Rekap SPK', route: r('bs.spk.query.rekap') },
        { label: 'Kartu Hutang Supplier - SPK', route: r('bs.spk.query.kartu-hutang') },
        { label: 'Pemakaian Barang', route: r('bs.spk.query.pemakaian') },
        { label: 'Summary SPK', route: r('bs.spk.query.summary') },
    ]},
    { group: 'Surat Perintah Kerja', items: [
        { label: 'Registrasi Surat Perintah Kerja', route: r('bs.spk.create') },
        { label: 'Registrasi Kontrol Progress Lapangan', route: r('bs.spk.kpl.create') },
        { label: 'Registrasi Retensi', route: r('bs.spk.retention.create') },
        { label: 'Addendum SPK (Tambah / Kurang)', route: r('bs.spk.addendum.form') },
        { label: 'Adjustment PPN', route: r('bs.spk.adjust-ppn.form') },
        { label: 'Pembatalan Surat Perintah Kerja', route: r('bs.spk.cancel.form') },
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
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Contractor Management</h1>
            <Link :href="route('bs.spk.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Registrasi SPK</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Manajemen kontraktor (SPK) â€” {{ totalItems }} sub-modul ({{ builtItems }} aktif, {{ totalItems - builtItems }} dalam pembangunan). Klik sub-modul untuk melihat detailnya.</p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Kontrak</div><div class="mt-1 font-mono text-lg font-bold">{{ fmtRp(summary.contract) }}</div><div class="text-xs text-neutral-500">{{ summary.spk }} SPK</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Progress</div><div class="mt-1 font-mono text-lg font-bold text-primary-600">{{ fmtRp(summary.progress) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Retensi Ditahan</div><div class="mt-1 font-mono text-lg font-bold text-amber-600">{{ fmtRp(summary.retention) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">SPK Aktif</div><div class="mt-1 text-lg font-bold">{{ summary.active }}</div></div>
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

