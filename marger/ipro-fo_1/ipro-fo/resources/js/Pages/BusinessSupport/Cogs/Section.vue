<!-- Cogs: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Calculator } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ section: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
const titles: Record<string, string> = {
    'setup': 'Setup',
    'master': 'Master',
    'query': 'Query',
    'mutasi': 'Mutasi Budget',
    'hpp': 'Transaksi HPP RealCost',
    'saldo-awal': 'Saldo Awal Realisasi',
};
const sections = computed<Record<string, Item[]>>(() => ({
    // Seksi "general" dihapus bersama Print-Out — lihat catatan di Hub.vue.
    'setup': [
        { label: 'Lokasi', route: r('bs.cogs.location.index') },
        { label: 'Ketentuan', route: r('bs.cogs.provision.index') },
        { label: 'Budget', route: r('bs.cogs.budget.index') },
        { label: 'Set Akun Budget', route: r('bs.cogs.category.index') },
        { label: 'Set COA Jenis Unit', route: r('bs.cogs.unitcoa.index') },
        { label: 'Upload Kategori Budget', route: r('bs.cogs.upload.form', { kind: 'kategori' }) },
        { label: 'Upload Akun Budget', route: r('bs.cogs.upload.form', { kind: 'akun' }) },
        { label: 'Upload Nilai Budget', route: r('bs.cogs.upload.form', { kind: 'nilai' }) },
    ],
    'master': [
        { label: 'Mutasi Budget', route: r('bs.cogs.mutation.index') },
        { label: 'HPP RealCost', route: r('bs.cogs.hpp.index') },
        { label: 'Saldo Awal Realisasi', route: r('bs.cogs.realization.index') },
    ],
    'query': [
        { label: 'Report Budget', route: r('bs.cogs.query.budget') },
        { label: 'Report Realisasi Budget', route: r('bs.cogs.query.realisasi') },
        { label: 'Report Realisasi Budget Detil', route: r('bs.cogs.query.realisasi-detil') },
        { label: 'Report Pengakuan HPP RealCost', route: r('bs.cogs.query.pengakuan') },
        { label: 'Report Persediaan Unit RealCost', route: r('bs.cogs.query.persediaan-unit') },
        { label: 'Report HPP Unit RealCost', route: r('bs.cogs.query.hpp-unit') },
    ],
    'mutasi': [
        { label: 'Registrasi Mutasi Budget', route: r('bs.cogs.mutation.create') },
        { label: 'Approval Mutasi Budget', route: r('bs.cogs.mutation.approvals') },
    ],
    'hpp': [
        { label: 'Generate HPP', route: r('bs.cogs.hpp.generate') },
        { label: 'Posting Jurnal HPP Persediaan', route: r('bs.cogs.hpp.post-persediaan') },
        { label: 'Posting Jurnal HPP', route: r('bs.cogs.hpp.post-hpp') },
    ],
    'saldo-awal': [
        { label: 'Input Saldo Awal Realisasi', route: r('bs.cogs.realization.create') },
    ],
}));
const items = computed<Item[]>(() => sections.value[props.section] ?? []);
const title = computed(() => titles[props.section] ?? 'Sub-Modul');
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="it in items" :key="it.label" class="card flex items-center justify-between gap-2 p-4">
                <a v-if="it.route" :href="it.route" class="flex-1 font-medium text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                <span v-else class="flex-1 font-medium text-neutral-400">{{ it.label }}</span>
                <span class="flex items-center gap-2">
                    <a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a>
                    <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                    <span v-else class="text-primary-400">→</span>
                </span>
            </div>
        </div>
    </div>
</template>
