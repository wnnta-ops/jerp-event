<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: any; filters: Record<string, any> }>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }

function approve(id: number) {
    if (confirm('Approve refund?')) router.post(route('refunds.approve', id));
}
function pay(id: number) {
    if (confirm('Bayar refund? Memo pengeluaran akan dibuat.')) router.post(route('refunds.pay', id));
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Refund</h1>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Sumber</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="px-4 py-3 font-mono text-xs">{{ r.refund_number }}</td>
                        <td class="px-4 py-3 capitalize text-xs">{{ r.refundable_type }} #{{ r.refundable_id }}</td>
                        <td class="px-4 py-3">{{ r.requested_at }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="r.status === 'paid' ? 'success' : r.status === 'approved' ? 'info' : 'warning'" dot>{{ r.status }}</Badge></td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button v-if="r.status === 'pending'" @click="approve(r.id)" class="text-success-600 text-sm">Approve</button>
                            <button v-if="r.status === 'approved'" @click="pay(r.id)" class="text-primary-600 text-sm">Pay</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Tidak ada refund" />
        </div>
    </div>
</template>
