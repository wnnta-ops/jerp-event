<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Waves } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    beginning: number;
    ending: number;
    cashMovement: number;
    direct: any;
    indirect: any;
}>();

const year = ref(props.year);
function changeYear() { router.get(route('bs.gl.cashflow'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { const n = Number(v ?? 0); return (n < 0 ? '(Rp ' + Math.abs(n).toLocaleString('id-ID') + ')' : 'Rp ' + n.toLocaleString('id-ID')); }
const reconciled = Math.abs(props.direct.net_change - props.cashMovement) < 1;
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Waves class="h-6 w-6 text-primary-600" /> Laporan Arus Kas</h1>
            <input v-model.number="year" @change="changeYear" type="number" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Saldo Kas Awal {{ year }}</div><div class="mt-1 text-lg font-bold">{{ fmtRp(beginning) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Perubahan Kas</div><div class="mt-1 text-lg font-bold" :class="cashMovement >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(cashMovement) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Saldo Kas Akhir</div><div class="mt-1 text-lg font-bold text-primary-600">{{ fmtRp(ending) }}</div></div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- DIRECT -->
            <div class="card p-5">
                <h2 class="mb-3 flex items-center justify-between font-semibold">
                    <span>Metode Langsung (Direct)</span>
                    <Badge :variant="reconciled ? 'success' : 'warning'" size="sm">{{ reconciled ? 'Rekonsiliasi ✓' : 'Cek' }}</Badge>
                </h2>
                <div class="space-y-3 text-sm">
                    <div v-for="grp in [{k:'operating',t:'Aktivitas Operasi'},{k:'investing',t:'Aktivitas Investasi'},{k:'financing',t:'Aktivitas Pendanaan'}]" :key="grp.k">
                        <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">{{ grp.t }}</div>
                        <div v-for="r in direct[grp.k]" :key="r.code" class="flex justify-between py-0.5"><span class="text-neutral-600 dark:text-neutral-300">{{ r.name }}</span><span class="font-mono" :class="r.amount >= 0 ? '' : 'text-danger-600'">{{ fmtRp(r.amount) }}</span></div>
                        <div v-if="!direct[grp.k].length" class="py-0.5 text-neutral-400">—</div>
                        <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Subtotal</span><span class="font-mono">{{ fmtRp(direct['total_'+grp.k]) }}</span></div>
                    </div>
                    <div class="flex justify-between rounded-lg bg-primary-50 px-3 py-2 font-bold dark:bg-primary-900/20"><span>Kenaikan/(Penurunan) Kas</span><span class="font-mono">{{ fmtRp(direct.net_change) }}</span></div>
                </div>
            </div>

            <!-- INDIRECT -->
            <div class="card p-5">
                <h2 class="mb-3 font-semibold">Metode Tidak Langsung (Indirect)</h2>
                <div class="space-y-1 text-sm">
                    <div class="mb-1 text-xs font-semibold uppercase text-neutral-400">Arus Kas Operasi</div>
                    <div class="flex justify-between py-0.5"><span>Laba/(Rugi) Bersih</span><span class="font-mono">{{ fmtRp(indirect.net_profit) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Penyusutan</span><span class="font-mono">{{ fmtRp(indirect.depreciation) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(−) Kenaikan Piutang</span><span class="font-mono">{{ fmtRp(-indirect.d_receivable) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(−) Kenaikan Persediaan/CIP</span><span class="font-mono">{{ fmtRp(-indirect.d_inventory) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Kenaikan Hutang Usaha</span><span class="font-mono">{{ fmtRp(indirect.d_payable) }}</span></div>
                    <div class="flex justify-between py-0.5"><span>(+) Kenaikan Hutang Pajak</span><span class="font-mono">{{ fmtRp(indirect.d_tax_payable) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 py-1 font-semibold dark:border-neutral-700"><span>Kas dari Operasi</span><span class="font-mono">{{ fmtRp(indirect.cash_from_ops) }}</span></div>
                    <div class="flex justify-between py-0.5 pt-2"><span class="text-xs font-semibold uppercase text-neutral-400">Investasi</span><span class="font-mono">{{ fmtRp(indirect.investing) }}</span></div>
                    <div class="flex justify-between py-0.5"><span class="text-xs font-semibold uppercase text-neutral-400">Pendanaan</span><span class="font-mono">{{ fmtRp(indirect.financing) }}</span></div>
                    <div class="flex justify-between rounded-lg bg-neutral-50 px-3 py-2 font-bold dark:bg-neutral-800"><span>Kenaikan/(Penurunan) Kas</span><span class="font-mono">{{ fmtRp(indirect.net_change) }}</span></div>
                </div>
            </div>
        </div>
    </div>
</template>
