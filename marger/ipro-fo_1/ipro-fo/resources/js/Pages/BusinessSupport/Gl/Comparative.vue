<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3 } from 'lucide-vue-next';

const props = defineProps<{ year: number; lr: Array<any>; neraca: Array<any>; lrTotal: any }>();
const year = ref(props.year);
const mode = ref<'lr' | 'neraca'>('lr');
function changeYear() { router.get(route('bs.gl.comparative'), { year: year.value }, { preserveState: true, replace: true }); }
function fmt(v: number) { const n = Number(v ?? 0); return n ? (n < 0 ? '(' + Math.abs(n).toLocaleString('id-ID') + ')' : n.toLocaleString('id-ID')) : '—'; }
const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

const lrRows = computed(() => [
    { label: 'Pendapatan', key: 'income', cls: '' },
    { label: 'Beban', key: 'expense', cls: 'text-danger-600' },
    { label: 'Laba/(Rugi) Bersih', key: 'laba', cls: 'font-bold text-primary-700 dark:text-primary-300' },
]);
const nrcRows = computed(() => [
    { label: 'Total Aset', key: 'asset', cls: 'font-semibold' },
    { label: 'Total Kewajiban', key: 'liability', cls: 'text-warning-600' },
    { label: 'Total Ekuitas', key: 'equity', cls: 'text-primary-600' },
]);
const rows = computed(() => mode.value === 'lr' ? lrRows.value : nrcRows.value);
const data = computed(() => mode.value === 'lr' ? props.lr : props.neraca);
function rowTotal(key: string) { return mode.value === 'lr' ? (props.lrTotal[key] ?? 0) : null; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BarChart3 class="h-6 w-6 text-primary-600" /> Komparasi Bulanan {{ year }}</h1>
            <div class="flex items-center gap-2">
                <div class="flex rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
                    <button @click="mode='lr'" :class="mode==='lr' ? 'bg-primary-600 text-white' : 'text-neutral-500'" class="rounded-md px-3 py-1 font-medium">Laba Rugi</button>
                    <button @click="mode='neraca'" :class="mode==='neraca' ? 'bg-primary-600 text-white' : 'text-neutral-500'" class="rounded-md px-3 py-1 font-medium">Neraca</button>
                </div>
                <input v-model.number="year" @change="changeYear" type="number" class="h-9 w-24 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-right text-[10px] uppercase text-neutral-500">
                    <th class="sticky left-0 bg-neutral-50 px-3 py-2 text-left dark:bg-neutral-800">Akun</th>
                    <th v-for="m in months" :key="m" class="px-3 py-2">{{ m }}</th>
                    <th v-if="mode==='lr'" class="px-3 py-2 font-bold">YTD</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                    <tr v-for="r in rows" :key="r.key" :class="r.cls">
                        <td class="sticky left-0 bg-white px-3 py-2 text-left font-medium dark:bg-neutral-900">{{ r.label }}</td>
                        <td v-for="(d, i) in data" :key="i" class="px-3 py-2 text-right font-mono">{{ fmt(d[r.key]) }}</td>
                        <td v-if="mode==='lr'" class="px-3 py-2 text-right font-mono font-bold">{{ fmt(rowTotal(r.key)) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-neutral-400">{{ mode==='lr' ? 'Laba Rugi menampilkan aktivitas tiap bulan.' : 'Neraca menampilkan saldo kumulatif per akhir bulan.' }}</p>
    </div>
</template>
