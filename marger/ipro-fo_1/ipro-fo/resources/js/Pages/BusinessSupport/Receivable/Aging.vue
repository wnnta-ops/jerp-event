<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: Array<any>; totals: any }>();
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-success-600" /> Aging Piutang</h1>
        <p class="text-sm text-neutral-500">Umur piutang dihitung dari tanggal jatuh tempo tiap invoice yang belum lunas.</p>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Customer</th><th class="px-4 py-3 text-right">Belum JT</th><th class="px-4 py-3 text-right">1–30</th><th class="px-4 py-3 text-right">31–60</th><th class="px-4 py-3 text-right">61–90</th><th class="px-4 py-3 text-right">&gt;90</th><th class="px-4 py-3 text-right">Total</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.current) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d30) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d60) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d90) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.d90p) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800"><tr>
                    <td class="px-4 py-3">Total</td>
                    <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.current) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d30) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d60) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d90) }}</td><td class="px-4 py-3 text-right font-mono text-danger-600">{{ fmtRp(totals.d90p) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.total) }}</td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada piutang outstanding" description="Semua invoice piutang sudah lunas." />
        </div>
    </div>
</template>
