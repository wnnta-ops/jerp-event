<!-- Budget Control: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, PieChart } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ section: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
const titles: Record<string, string> = {
    general: 'General Function',
    master: 'Master',
    query: 'Query',
    upload: 'Upload Budget',
    alokasi: 'Alokasi Budget',
    koreksi: 'Koreksi Budget',
};
const sections = computed<Record<string, Item[]>>(() => ({
    general: [
        { label: 'Print-Out', route: r('bs.budget.printhub') },
    ],
    master: [
        { label: 'Budget Control', route: r('bs.budget.index') },
        { label: 'Alokasi Budget Control', route: r('bs.budget.allocation.index') },
        { label: 'Koreksi Budget Control', route: r('bs.budget.correction.index') },
    ],
    query: [
        { label: 'Master Budget', route: r('bs.budget.query.master') },
        { label: 'Koreksi Budget', route: r('bs.budget.query.correction') },
        { label: 'Realisasi Budget', route: r('bs.budget.query.realization') },
        { label: 'Alokasi Budget', route: r('bs.budget.query.allocation') },
    ],
    upload: [
        { label: 'Upload Budget', route: r('bs.budget.upload.index') },
        { label: 'Approval Budget', route: r('bs.budget.approvals') },
    ],
    alokasi: [
        { label: 'Alokasi Budget', route: r('bs.budget.allocation.create') },
        { label: 'Approval Alokasi Budget', route: r('bs.budget.allocation.approvals') },
    ],
    koreksi: [
        { label: 'Koreksi Budget', route: r('bs.budget.correction.create') },
        { label: 'Approval Koreksi Budget', route: r('bs.budget.correction.approvals') },
    ],
}));
const items = computed<Item[]>(() => sections.value[props.section] ?? []);
const title = computed(() => titles[props.section] ?? 'Sub-Modul');
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><PieChart class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
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
