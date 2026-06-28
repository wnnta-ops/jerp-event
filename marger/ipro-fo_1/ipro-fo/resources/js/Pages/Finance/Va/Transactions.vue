<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: any }>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('va.index')" class="text-sm text-neutral-500">← Kembali</Link>
        <h1 class="text-2xl font-bold">Transaksi VA</h1>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Waktu</th>
                        <th class="px-4 py-3">VA Number</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">External Ref</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in rows.data" :key="t.id">
                        <td class="px-4 py-3">{{ new Date(t.received_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3 font-mono">{{ t.va_number }}</td>
                        <td class="px-4 py-3">{{ t.customer_name ?? '-' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ t.external_reference }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(t.amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="t.status === 'allocated' ? 'success' : 'warning'" dot>{{ t.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada transaksi VA" />
        </div>
    </div>
</template>
