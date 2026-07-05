<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3 } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    filters: { year: number; cost_center_id: number | null; date_from: string | null; date_to: string | null };
    rows: Array<any>;
    monthLabels: string[];
    costCenters: Array<any>;
    total: { budget: number; real: number; sisa: number };
}>();

const year = ref(props.filters.year);
const cc = ref<number | string>(props.filters.cost_center_id ?? '');
const dateFrom = ref<string>(props.filters.date_from ?? '');
const dateTo = ref<string>(props.filters.date_to ?? '');
const mode = ref<'nilai' | 'persen'>('nilai');

function apply() { router.get(route('bs.budget.query.realization'), { year: year.value, cost_center_id: cc.value || undefined, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }, { preserveState: true, replace: true }); }
function fmt(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function cellClass(pct: number) { return pct > 100 ? 'text-danger-600' : (pct >= 90 ? 'text-amber-600' : 'text-neutral-700 dark:text-neutral-300'); }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BarChart3 class="h-6 w-6 text-primary-600" /> Realisasi Budget</h1>

        <div class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs text-neutral-500">Tahun</label><input v-model.number="year" type="number" :class="[inputCls, 'w-28']" /></div>
            <div><label class="mb-1 block text-xs text-neutral-500">Cost Center</label><select v-model="cc" :class="[inputCls, 'w-52']"><option value="">Semua</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div><label class="mb-1 block text-xs text-neutral-500">Tgl Awal</label><input v-model="dateFrom" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs text-neutral-500">Tgl Akhir</label><input v-model="dateTo" type="date" :class="inputCls" /></div>
            <button @click="apply" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <div class="ml-auto inline-flex overflow-hidden rounded-lg border border-neutral-300 dark:border-neutral-700">
                <button @click="mode = 'nilai'" class="px-3 py-1.5 text-sm" :class="mode === 'nilai' ? 'bg-primary-600 text-white' : ''">Nilai</button>
                <button @click="mode = 'persen'" class="px-3 py-1.5 text-sm" :class="mode === 'persen' ? 'bg-primary-600 text-white' : ''">Persentase</button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Anggaran</div><div class="mt-1 font-mono text-lg font-bold">Rp {{ fmt(total.budget) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi</div><div class="mt-1 font-mono text-lg font-bold text-primary-600">Rp {{ fmt(total.real) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa</div><div class="mt-1 font-mono text-lg font-bold" :class="total.sisa >= 0 ? 'text-success-700' : 'text-danger-600'">Rp {{ fmt(total.sisa) }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left uppercase text-neutral-500">
                    <th class="px-3 py-2.5">Akun</th><th class="px-3 py-2.5">Cost Center</th>
                    <th v-for="lbl in monthLabels" :key="lbl" class="px-2 py-2.5 text-right">{{ lbl }}</th>
                    <th class="px-3 py-2.5 text-right">Anggaran</th><th class="px-3 py-2.5 text-right">Realisasi</th><th class="px-3 py-2.5 text-right">Sisa</th><th class="px-3 py-2.5 text-right">%</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, ri) in rows" :key="ri">
                        <td class="px-3 py-2 whitespace-nowrap">{{ r.account }}</td>
                        <td class="px-3 py-2 text-neutral-500">{{ r.cost_center }}</td>
                        <td v-for="(m, mi) in r.months" :key="mi" class="px-2 py-2 text-right font-mono" :class="cellClass(m.pct)">
                            <template v-if="mode === 'nilai'">{{ fmt(m.real) }}</template>
                            <template v-else>{{ m.pct }}%</template>
                        </td>
                        <td class="px-3 py-2 text-right font-mono">{{ fmt(r.budget) }}</td>
                        <td class="px-3 py-2 text-right font-mono text-primary-600">{{ fmt(r.real) }}</td>
                        <td class="px-3 py-2 text-right font-mono" :class="r.sisa < 0 ? 'text-danger-600' : ''">{{ fmt(r.sisa) }}</td>
                        <td class="px-3 py-2 text-right font-mono font-semibold" :class="cellClass(r.pct)">{{ r.pct }}%</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada data" description="Belum ada anggaran untuk filter ini." />
        </div>
    </div>
</template>
