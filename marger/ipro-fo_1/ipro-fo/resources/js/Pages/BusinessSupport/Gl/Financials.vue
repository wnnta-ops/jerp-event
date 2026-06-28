<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, TrendingUp, Scale } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number | null;
    incomeStatement: { income: Array<any>; expense: Array<any>; total_income: number; total_expense: number; net_profit: number };
    balanceSheet: { assets: Array<any>; liabilities: Array<any>; equity: Array<any>; total_assets: number; total_liabilities: number; total_equity: number; net_profit: number; total_liab_equity: number };
}>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
const balanced = Math.abs(props.balanceSheet.total_assets - props.balanceSheet.total_liab_equity) < 1;
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="text-2xl font-bold">Laporan Keuangan</h1>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Laba Rugi -->
            <div class="card p-5">
                <h2 class="mb-3 flex items-center gap-2 font-semibold"><TrendingUp class="h-5 w-5 text-success-600" /> Laba Rugi</h2>
                <div class="text-sm">
                    <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">Pendapatan</div>
                    <div v-for="r in incomeStatement.income" :key="r.code" class="flex justify-between py-1"><span>{{ r.name }}</span><span class="font-mono">{{ fmtRp(r.amount) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Total Pendapatan</span><span class="font-mono text-success-600">{{ fmtRp(incomeStatement.total_income) }}</span></div>
                    <div class="mb-1 mt-3 text-xs font-semibold uppercase text-neutral-400">Beban</div>
                    <div v-for="r in incomeStatement.expense" :key="r.code" class="flex justify-between py-1"><span>{{ r.name }}</span><span class="font-mono">{{ fmtRp(r.amount) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Total Beban</span><span class="font-mono text-danger-600">{{ fmtRp(incomeStatement.total_expense) }}</span></div>
                    <div class="mt-2 flex justify-between rounded-lg bg-primary-50 px-3 py-2 font-bold dark:bg-primary-900/20"><span>Laba / (Rugi) Bersih</span><span class="font-mono" :class="incomeStatement.net_profit >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(incomeStatement.net_profit) }}</span></div>
                </div>
            </div>

            <!-- Neraca -->
            <div class="card p-5">
                <h2 class="mb-3 flex items-center justify-between font-semibold">
                    <span class="flex items-center gap-2"><Scale class="h-5 w-5 text-primary-600" /> Neraca</span>
                    <Badge :variant="balanced ? 'success' : 'warning'" size="sm">{{ balanced ? 'Balance' : 'Cek' }}</Badge>
                </h2>
                <div class="text-sm">
                    <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">Aktiva</div>
                    <div v-for="r in balanceSheet.assets" :key="r.code" class="flex justify-between py-1"><span>{{ r.name }}</span><span class="font-mono">{{ fmtRp(r.amount) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-bold dark:border-neutral-700"><span>Total Aktiva</span><span class="font-mono">{{ fmtRp(balanceSheet.total_assets) }}</span></div>

                    <div class="mb-1 mt-3 text-xs font-semibold uppercase text-neutral-400">Kewajiban & Ekuitas</div>
                    <div v-for="r in balanceSheet.liabilities" :key="r.code" class="flex justify-between py-1"><span>{{ r.name }}</span><span class="font-mono">{{ fmtRp(r.amount) }}</span></div>
                    <div v-for="r in balanceSheet.equity" :key="r.code" class="flex justify-between py-1"><span>{{ r.name }}</span><span class="font-mono">{{ fmtRp(r.amount) }}</span></div>
                    <div class="flex justify-between py-1"><span>Laba Berjalan</span><span class="font-mono">{{ fmtRp(balanceSheet.net_profit) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-bold dark:border-neutral-700"><span>Total Kewajiban + Ekuitas</span><span class="font-mono">{{ fmtRp(balanceSheet.total_liab_equity) }}</span></div>
                </div>
            </div>
        </div>
    </div>
</template>
