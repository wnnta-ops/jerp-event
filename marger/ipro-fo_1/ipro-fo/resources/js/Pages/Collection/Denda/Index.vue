<script setup lang="ts">
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ dendas: any; filters: Record<string, any> }>();

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Denda Keterlambatan</h1>
        <p class="text-sm text-neutral-500">Denda terhitung otomatis tiap pagi via scheduler.</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Tanggal Hitung</th>
                        <th class="px-4 py-3">Schedule ID</th>
                        <th class="px-4 py-3 text-right">Terlambat (hari)</th>
                        <th class="px-4 py-3 text-right">Nominal Denda</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="d in dendas.data" :key="d.id">
                        <td class="px-4 py-3">{{ d.computed_date }}</td>
                        <td class="px-4 py-3 font-mono">#{{ d.payment_schedule_id }}</td>
                        <td class="px-4 py-3 text-right">{{ d.overdue_days }}</td>
                        <td class="px-4 py-3 text-right font-mono text-danger-600">{{ fmtRp(d.amount) }}</td>
                        <td class="px-4 py-3"><Badge>{{ d.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!dendas.data?.length" title="Tidak ada denda aktif" />
        </div>
    </div>
</template>
