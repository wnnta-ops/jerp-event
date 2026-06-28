<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Tag, Printer } from 'lucide-vue-next';

defineProps<{ rows: Array<any>; total: number }>();
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const doPrint = () => window.print();
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between print:hidden">
            <Link :href="route('setup-sales.reports.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Laporan</Link>
            <button @click="doPrint" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Cetak</button>
        </div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Tag class="h-6 w-6 text-accent-600" /> Laporan Price List (Aktif)</h1>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Price List</th><th class="px-4 py-3">Project</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Diskon Maks</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-2">{{ r.pricelist }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.project }}</td>
                        <td class="px-4 py-2 font-mono">{{ r.unit_code }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.type ?? '—' }}</td>
                        <td class="px-4 py-2 text-center text-xs text-neutral-500">{{ r.status }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rb(r.price) }}</td>
                        <td class="px-4 py-2 text-right">{{ Number(r.discount_max_pct).toFixed(2) }}%</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Tidak ada price list aktif.</td></tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t border-neutral-200 dark:border-neutral-700"><tr class="font-semibold"><td class="px-4 py-2" colspan="5">Total Nilai ({{ rows.length }} unit)</td><td class="px-4 py-2 text-right">{{ rb(total) }}</td><td></td></tr></tfoot>
            </table>
        </div>
    </div>
</template>
