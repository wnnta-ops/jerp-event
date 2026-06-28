<script setup lang="ts">
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: any }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">IMB / PBG Documents</h1>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Unit ID</th>
                        <th class="px-4 py-3">Nomor IMB</th>
                        <th class="px-4 py-3">Tanggal Terbit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="px-4 py-3">#{{ r.unit_id }}</td>
                        <td class="px-4 py-3 font-mono">{{ r.imb_number }}</td>
                        <td class="px-4 py-3">{{ r.issued_at }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada IMB" />
        </div>
    </div>
</template>
