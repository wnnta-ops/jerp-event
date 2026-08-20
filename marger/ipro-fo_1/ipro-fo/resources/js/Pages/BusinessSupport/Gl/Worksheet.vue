<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Columns3, FileSpreadsheet } from 'lucide-vue-next';

const props = defineProps<{ year: number; rows: Array<any>; totals: any; netProfit: number }>();
const year = ref(props.year);
function changeYear() { router.get(route('bs.gl.worksheet'), { year: year.value }, { preserveState: true, replace: true }); }
function excel() { window.location.href = route('bs.gl.worksheet.export', { year: year.value }); }
function fmt(v: number) { return Number(v ?? 0) ? Number(v).toLocaleString('id-ID') : ''; }
// Penyeimbang laba: + ke LR debit, + ke Neraca kredit.
const lrDebit = props.totals.lr_d + (props.netProfit >= 0 ? props.netProfit : 0);
const lrKredit = props.totals.lr_k + (props.netProfit < 0 ? -props.netProfit : 0);
const nrcDebit = props.totals.nrc_d + (props.netProfit < 0 ? -props.netProfit : 0);
const nrcKredit = props.totals.nrc_k + (props.netProfit >= 0 ? props.netProfit : 0);
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Columns3 class="h-6 w-6 text-primary-600" /> Neraca Lajur <span class="text-base font-normal text-neutral-400">(Worksheet)</span></h1>
            <div class="flex items-end gap-2">
                <input v-model.number="year" @change="changeYear" type="number" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-neutral-100 text-center text-[10px] uppercase text-neutral-500 dark:bg-neutral-800">
                        <th rowspan="2" class="px-3 py-2 text-left">Akun</th>
                        <th colspan="2" class="border-l border-neutral-200 px-3 py-1.5 dark:border-neutral-700">Neraca Saldo</th>
                        <th colspan="2" class="border-l border-neutral-200 px-3 py-1.5 dark:border-neutral-700">Laba Rugi</th>
                        <th colspan="2" class="border-l border-neutral-200 px-3 py-1.5 dark:border-neutral-700">Neraca</th>
                    </tr>
                    <tr class="bg-neutral-50 text-right text-[10px] uppercase text-neutral-400 dark:bg-neutral-800/60">
                        <th class="border-l border-neutral-200 px-3 py-1 dark:border-neutral-700">Debit</th><th class="px-3 py-1">Kredit</th>
                        <th class="border-l border-neutral-200 px-3 py-1 dark:border-neutral-700">Debit</th><th class="px-3 py-1">Kredit</th>
                        <th class="border-l border-neutral-200 px-3 py-1 dark:border-neutral-700">Debit</th><th class="px-3 py-1">Kredit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                    <tr v-for="r in rows" :key="r.code">
                        <td class="px-3 py-1.5"><span class="font-mono text-neutral-400">{{ r.code }}</span> {{ r.name }}</td>
                        <td class="border-l border-neutral-100 px-3 py-1.5 text-right font-mono dark:border-neutral-800">{{ fmt(r.ns_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(r.ns_k) }}</td>
                        <td class="border-l border-neutral-100 px-3 py-1.5 text-right font-mono dark:border-neutral-800">{{ fmt(r.lr_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(r.lr_k) }}</td>
                        <td class="border-l border-neutral-100 px-3 py-1.5 text-right font-mono dark:border-neutral-800">{{ fmt(r.nrc_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(r.nrc_k) }}</td>
                    </tr>
                    <tr class="bg-neutral-50 font-semibold dark:bg-neutral-800/40">
                        <td class="px-3 py-1.5 text-right">Subtotal</td>
                        <td class="border-l border-neutral-200 px-3 py-1.5 text-right font-mono dark:border-neutral-700">{{ fmt(totals.ns_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(totals.ns_k) }}</td>
                        <td class="border-l border-neutral-200 px-3 py-1.5 text-right font-mono dark:border-neutral-700">{{ fmt(totals.lr_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(totals.lr_k) }}</td>
                        <td class="border-l border-neutral-200 px-3 py-1.5 text-right font-mono dark:border-neutral-700">{{ fmt(totals.nrc_d) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(totals.nrc_k) }}</td>
                    </tr>
                    <tr class="font-semibold text-primary-700 dark:text-primary-300">
                        <td class="px-3 py-1.5 text-right">{{ netProfit >= 0 ? 'Laba Bersih' : 'Rugi Bersih' }}</td>
                        <td colspan="2"></td>
                        <td class="border-l border-neutral-200 px-3 py-1.5 text-right font-mono dark:border-neutral-700">{{ fmt(netProfit >= 0 ? netProfit : 0) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(netProfit < 0 ? -netProfit : 0) }}</td>
                        <td class="border-l border-neutral-200 px-3 py-1.5 text-right font-mono dark:border-neutral-700">{{ fmt(netProfit < 0 ? -netProfit : 0) }}</td><td class="px-3 py-1.5 text-right font-mono">{{ fmt(netProfit >= 0 ? netProfit : 0) }}</td>
                    </tr>
                    <tr class="border-t-2 border-neutral-300 bg-neutral-100 font-bold dark:border-neutral-600 dark:bg-neutral-800">
                        <td class="px-3 py-2 text-right">Total</td>
                        <td class="border-l border-neutral-200 px-3 py-2 text-right font-mono dark:border-neutral-700">{{ fmt(totals.ns_d) }}</td><td class="px-3 py-2 text-right font-mono">{{ fmt(totals.ns_k) }}</td>
                        <td class="border-l border-neutral-200 px-3 py-2 text-right font-mono dark:border-neutral-700">{{ fmt(lrDebit) }}</td><td class="px-3 py-2 text-right font-mono">{{ fmt(lrKredit) }}</td>
                        <td class="border-l border-neutral-200 px-3 py-2 text-right font-mono dark:border-neutral-700">{{ fmt(nrcDebit) }}</td><td class="px-3 py-2 text-right font-mono">{{ fmt(nrcKredit) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-neutral-400">Kolom Laba Rugi &amp; Neraca masing-masing seimbang setelah laba/rugi bersih dipindahkan ke ekuitas.</p>
    </div>
</template>
