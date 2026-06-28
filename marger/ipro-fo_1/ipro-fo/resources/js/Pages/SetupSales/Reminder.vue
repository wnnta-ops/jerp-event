<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, BellRing, Tag, Boxes, Layers } from 'lucide-vue-next';

defineProps<{
    items: Array<{ type: string; title: string; date: string | null; severity: string; ref: number }>;
    summary: { pricelist: number; unit: number; scheme: number };
}>();

const icon = (t: string) => (t === 'pricelist' ? Tag : t === 'unit' ? Boxes : Layers);
const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BellRing class="h-6 w-6 text-amber-600" /> Reminder</h1>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Price List Berakhir ≤30 hari</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.pricelist }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Unit Belum Berharga</div><div class="mt-1 text-xl font-bold text-danger-600">{{ summary.unit }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Skema Tanpa Termin</div><div class="mt-1 text-xl font-bold text-primary-600">{{ summary.scheme }}</div></div>
        </div>

        <div class="card divide-y divide-neutral-100 dark:divide-neutral-800">
            <div v-for="(it, i) in items" :key="i" class="flex items-center gap-3 px-4 py-3">
                <span class="flex h-8 w-8 items-center justify-center rounded-full" :class="it.severity === 'overdue' ? 'bg-danger-100 text-danger-600' : 'bg-amber-100 text-amber-600'">
                    <component :is="icon(it.type)" class="h-4 w-4" />
                </span>
                <div class="flex-1 text-sm">{{ it.title }}</div>
                <div class="text-xs text-neutral-400">{{ fmt(it.date) }}</div>
            </div>
            <div v-if="!items.length" class="px-4 py-10 text-center text-sm text-neutral-400">✅ Tidak ada reminder. Semua setup penjualan rapi.</div>
        </div>
    </div>
</template>
