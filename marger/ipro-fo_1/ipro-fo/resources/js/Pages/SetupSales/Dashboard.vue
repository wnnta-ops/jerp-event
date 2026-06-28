<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, LayoutDashboard, Boxes, Layers } from 'lucide-vue-next';

const props = defineProps<{
    perType: Array<{ code: string; name: string; total: number; available: number; booked: number; sold: number; value: number }>;
    schemeMix: Array<{ type: string; label: string; count: number }>;
    totals: { units: number; value: number; schemes: number };
}>();

const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const pct = (part: number, total: number) => (total > 0 ? Math.round((part / total) * 100) : 0);
const maxType = () => Math.max(1, ...props.perType.map(r => r.total));
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><LayoutDashboard class="h-6 w-6 text-success-600" /> Dashboard Setup Sales</h1>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4 flex items-center gap-3"><Boxes class="h-8 w-8 text-primary-500" /><div><div class="text-xs uppercase text-neutral-400">Total Unit</div><div class="text-2xl font-bold">{{ totals.units }}</div></div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Nilai Unit</div><div class="mt-1 text-2xl font-bold text-success-700">{{ rb(totals.value) }}</div></div>
            <div class="card p-4 flex items-center gap-3"><Layers class="h-8 w-8 text-accent-500" /><div><div class="text-xs uppercase text-neutral-400">Skema</div><div class="text-2xl font-bold">{{ totals.schemes }}</div></div></div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Stok Per Tipe</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-2 text-left">Tipe</th><th class="px-4 py-2 text-right">Tersedia</th><th class="px-4 py-2 text-right">Booked</th><th class="px-4 py-2 text-right">Terjual</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2 text-left w-40">Komposisi</th><th class="px-4 py-2 text-right">Nilai</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in perType" :key="r.code" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2"><span class="font-medium">{{ r.name }}</span> <span class="text-xs text-neutral-400">{{ r.code }}</span></td>
                        <td class="px-4 py-2 text-right text-success-600">{{ r.available }}</td>
                        <td class="px-4 py-2 text-right text-amber-600">{{ r.booked }}</td>
                        <td class="px-4 py-2 text-right text-danger-600">{{ r.sold }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ r.total }}</td>
                        <td class="px-4 py-2">
                            <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-700" :title="`${pct(r.sold+r.booked, r.total)}% terjual`">
                                <div class="bg-success-500" :style="{ width: pct(r.available, r.total) + '%' }"></div>
                                <div class="bg-amber-500" :style="{ width: pct(r.booked, r.total) + '%' }"></div>
                                <div class="bg-danger-500" :style="{ width: pct(r.sold, r.total) + '%' }"></div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rb(r.value) }}</td>
                    </tr>
                    <tr v-if="!perType.length"><td colspan="7" class="px-4 py-6 text-center text-neutral-400">Belum ada data unit.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Komposisi Skema Cara Bayar</div>
            <div class="space-y-2 p-4">
                <div v-for="s in schemeMix" :key="s.type" class="flex items-center gap-3">
                    <div class="w-32 text-sm">{{ s.label }}</div>
                    <div class="h-3 flex-1 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-700">
                        <div class="h-full bg-primary-500" :style="{ width: pct(s.count, totals.schemes) + '%' }"></div>
                    </div>
                    <div class="w-10 text-right text-sm font-semibold">{{ s.count }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
