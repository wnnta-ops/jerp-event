<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<{ code: string; name: string; type: string; debit: number; credit: number }>;
    total_debit: number;
    total_credit: number;
    year: number | null;
}>();
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
const typeLabel: Record<string,string> = { asset:'Aktiva', liability:'Kewajiban', equity:'Ekuitas', income:'Pendapatan', expense:'Beban' };
const balanced = Math.abs(props.total_debit - props.total_credit) < 0.01;
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Neraca Saldo (Trial Balance)</h1>
            <Badge :variant="balanced ? 'success' : 'danger'" dot>{{ balanced ? 'Balance' : 'Tidak Balance' }}</Badge>
        </div>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Akun</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.code">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2">{{ r.name }}</td>
                        <td class="px-4 py-2 text-xs text-neutral-500">{{ typeLabel[r.type] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ r.debit > 0 ? fmtRp(r.debit) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ r.credit > 0 ? fmtRp(r.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 dark:bg-neutral-800 font-bold"><tr>
                    <td colspan="3" class="px-4 py-2.5 text-right">TOTAL</td>
                    <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(total_debit) }}</td>
                    <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(total_credit) }}</td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada saldo" description="Posting jurnal dulu untuk menampilkan neraca saldo." />
        </div>
    </div>
</template>
