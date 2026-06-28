<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Boxes, Printer } from 'lucide-vue-next';

defineProps<{ rows: Array<any>; totals: { available: number; booked: number; sold: number; total: number; value: number } }>();
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const doPrint = () => window.print();
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between print:hidden">
            <Link :href="route('setup-sales.reports.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Laporan</Link>
            <button @click="doPrint" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Cetak</button>
        </div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Boxes class="h-6 w-6 text-success-600" /> Summary Stock Per Tipe</h1>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-3 text-left">Tipe</th><th class="px-4 py-3 text-right">Tersedia</th><th class="px-4 py-3 text-right">Booked</th><th class="px-4 py-3 text-right">Terjual</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Nilai</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.code" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2"><span class="font-medium">{{ r.name }}</span> <span class="text-xs text-neutral-400">{{ r.code }}</span></td>
                        <td class="px-4 py-2 text-right text-success-600">{{ r.available }}</td>
                        <td class="px-4 py-2 text-right text-amber-600">{{ r.booked }}</td>
                        <td class="px-4 py-2 text-right text-danger-600">{{ r.sold }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ r.total }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rb(r.value) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="6" class="px-4 py-8 text-center text-neutral-400">Belum ada data unit.</td></tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700"><tr>
                    <td class="px-4 py-2">TOTAL</td>
                    <td class="px-4 py-2 text-right text-success-600">{{ totals.available }}</td>
                    <td class="px-4 py-2 text-right text-amber-600">{{ totals.booked }}</td>
                    <td class="px-4 py-2 text-right text-danger-600">{{ totals.sold }}</td>
                    <td class="px-4 py-2 text-right">{{ totals.total }}</td>
                    <td class="px-4 py-2 text-right">{{ rb(totals.value) }}</td>
                </tr></tfoot>
            </table>
        </div>
    </div>
</template>
