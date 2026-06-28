<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Table, ArrowLeft, Hash, Wallet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Donut from '@/Components/Charts/Donut.vue';
import AreaLine from '@/Components/Charts/AreaLine.vue';

interface Seg { label: string; value: number; amount: number | null; color: string }

const props = defineProps<{
    report: { key: string; label: string; group: string; hasAmount: boolean };
    kpi: { total: number; amount_total: number | null };
    status_dist: Seg[];
    trend: { labels: string[]; count: number[]; amount: number[] };
    filters: { from: string | null; to: string | null; status: string | null };
}>();

const f = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '' });
const trendMode = ref<'count' | 'amount'>('count');

const trendValues = computed(() => trendMode.value === 'amount' ? props.trend.amount : props.trend.count);

function rupiah(v: number | null): string {
    if (v === null) return '—';
    if (v >= 1e9) return 'Rp ' + (v / 1e9).toFixed(2).replace('.', ',') + ' M';
    if (v >= 1e6) return 'Rp ' + (v / 1e6).toFixed(1).replace('.', ',') + ' Jt';
    return 'Rp ' + Number(v).toLocaleString('id-ID');
}
function apply() {
    const o: Record<string, string> = {};
    if (f.from) o.from = f.from;
    if (f.to) o.to = f.to;
    router.get(route('reports.summary', props.report.key), o, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="`Ringkasan ${report.label}`" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <Link :href="route('reports.index')" class="text-sm text-neutral-500 hover:text-primary-600 inline-flex items-center gap-1"><ArrowLeft class="h-3.5 w-3.5" /> Semua Laporan</Link>
                <h1 class="text-2xl font-bold mt-1">Laporan {{ report.label }} — Ringkasan</h1>
            </div>
            <Link :href="route('reports.detail', report.key)">
                <Button variant="secondary"><template #icon-left><Table class="h-4 w-4" /></template>Lihat Detail</Button>
            </Link>
        </div>

        <!-- Filter periode -->
        <div class="card p-4 flex flex-wrap items-end gap-3">
            <div><label class="label-base">Dari</label><input type="date" v-model="f.from" class="input-base" /></div>
            <div><label class="label-base">Sampai</label><input type="date" v-model="f.to" class="input-base" /></div>
            <Button @click="apply">Terapkan</Button>
        </div>

        <!-- KPI -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-neutral-500">Total Data</p>
                    <span class="p-1.5 rounded-lg bg-primary-50 dark:bg-primary-900/20"><Hash class="h-4 w-4 text-primary-600" /></span>
                </div>
                <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ kpi.total.toLocaleString('id-ID') }}</p>
            </div>
            <div v-if="report.hasAmount" class="card p-5 bg-gradient-to-br from-primary-600 to-primary-700 text-white border-transparent">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-white/80">Total Nominal</p>
                    <span class="p-1.5 rounded-lg bg-white/15"><Wallet class="h-4 w-4 text-white" /></span>
                </div>
                <p class="text-3xl font-extrabold mt-2 tabular-nums">{{ rupiah(kpi.amount_total) }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Distribusi status -->
            <div class="card p-5">
                <h2 class="font-semibold mb-4">Distribusi Status</h2>
                <Donut :items="status_dist" center-label="Total" :size="170" />
            </div>

            <!-- Tren bulanan -->
            <div class="card p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-semibold">Tren 6 Bulan</h2>
                    <div v-if="report.hasAmount" class="flex gap-1 bg-neutral-100 dark:bg-neutral-800 rounded-lg p-1">
                        <button @click="trendMode = 'count'" class="px-3 py-1 text-xs font-medium rounded-md transition"
                                :class="trendMode === 'count' ? 'bg-white dark:bg-neutral-700 shadow text-primary-700' : 'text-neutral-500'">Jumlah</button>
                        <button @click="trendMode = 'amount'" class="px-3 py-1 text-xs font-medium rounded-md transition"
                                :class="trendMode === 'amount' ? 'bg-white dark:bg-neutral-700 shadow text-primary-700' : 'text-neutral-500'">Nominal</button>
                    </div>
                </div>
                <AreaLine :labels="trend.labels" :values="trendValues" :money="trendMode === 'amount'" color="#ff8c00" :height="220" />
            </div>
        </div>

        <!-- Tabel ringkasan status -->
        <div v-if="status_dist.length" class="card p-5">
            <h2 class="font-semibold mb-3">Rincian per Status</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr>
                        <th class="py-2">Status</th>
                        <th class="py-2 text-right">Jumlah</th>
                        <th v-if="report.hasAmount" class="py-2 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="s in status_dist" :key="s.label">
                        <td class="py-2"><span class="inline-flex items-center gap-2 capitalize"><span class="w-2.5 h-2.5 rounded-full" :style="{ background: s.color }"></span>{{ s.label }}</span></td>
                        <td class="py-2 text-right font-semibold tabular-nums">{{ s.value.toLocaleString('id-ID') }}</td>
                        <td v-if="report.hasAmount" class="py-2 text-right font-mono tabular-nums">{{ rupiah(s.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
