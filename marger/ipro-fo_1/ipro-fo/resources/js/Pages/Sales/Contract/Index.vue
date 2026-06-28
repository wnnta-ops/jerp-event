<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ contracts: any; filters: Record<string, any> }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Kontrak (PPJB)</h1>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Booking Ref</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Signed At</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in contracts.data" :key="c.id">
                        <td class="px-4 py-3 font-mono">{{ c.contract_number }}</td>
                        <td class="px-4 py-3"><span class="font-mono text-xs text-neutral-400">{{ c.booking?.customer?.code }}</span> {{ c.booking?.customer?.full_name ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ c.booking?.unit?.unit_code ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ c.booking?.booking_number }}</td>
                        <td class="px-4 py-3"><Badge>{{ c.status }}</Badge></td>
                        <td class="px-4 py-3">{{ c.signed_at ?? '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('contracts.show', c.id)" class="text-primary-600 text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!contracts.data?.length" title="Belum ada kontrak" />
        </div>
    </div>
</template>
