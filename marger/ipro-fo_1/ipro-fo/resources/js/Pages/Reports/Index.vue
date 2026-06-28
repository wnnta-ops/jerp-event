<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { FileBarChart, Table, PieChart } from 'lucide-vue-next';

interface Report { key: string; label: string; group: string; hasAmount: boolean }

const props = defineProps<{ reports: Report[]; groups: string[] }>();

const byGroup = computed(() => {
    const m: Record<string, Report[]> = {};
    for (const r of props.reports) (m[r.group] ??= []).push(r);
    return m;
});
</script>

<template>
    <Head title="Laporan" />
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold flex items-center gap-2"><FileBarChart class="h-6 w-6 text-primary-600" /> Laporan</h1>
            <p class="text-sm text-neutral-500 mt-1">Laporan detail &amp; ringkasan per modul. Pilih modul untuk melihat rincian atau ringkasan grafik.</p>
        </div>

        <div v-for="g in groups" :key="g" class="space-y-2">
            <h2 class="text-xs uppercase tracking-wider text-neutral-400 font-semibold">{{ g }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="r in byGroup[g]" :key="r.key" class="card p-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold truncate">{{ r.label }}</p>
                        <p class="text-xs text-neutral-400">{{ r.hasAmount ? 'Detail + nominal' : 'Detail' }}</p>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <Link :href="route('reports.summary', r.key)"
                              class="inline-flex items-center gap-1 rounded-lg border border-neutral-200 dark:border-neutral-700 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:hover:bg-neutral-800">
                            <PieChart class="h-3.5 w-3.5" /> Ringkasan
                        </Link>
                        <Link :href="route('reports.detail', r.key)"
                              class="inline-flex items-center gap-1 rounded-lg bg-primary-600 text-white px-2.5 py-1.5 text-xs hover:bg-primary-700">
                            <Table class="h-3.5 w-3.5" /> Detail
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
