<script setup lang="ts">
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: any }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">BAST Documents</h1>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor BAST</th>
                        <th class="px-4 py-3">Booking Ref</th>
                        <th class="px-4 py-3">Handover</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="px-4 py-3 font-mono">{{ r.bast_number }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ r.booking?.booking_number }}</td>
                        <td class="px-4 py-3">{{ r.handed_over_at }}</td>
                        <td class="px-4 py-3 capitalize">{{ r.status }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada BAST" />
        </div>
    </div>
</template>
