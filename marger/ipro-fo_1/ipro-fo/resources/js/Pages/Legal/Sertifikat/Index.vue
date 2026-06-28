<script setup lang="ts">
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ rows: any }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Sertifikat Tanah/Bangunan</h1>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Tipe</th>
                        <th class="px-4 py-3">Serial</th>
                        <th class="px-4 py-3">Terbit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="px-4 py-3">#{{ r.unit_id }}</td>
                        <td class="px-4 py-3"><Badge>{{ r.type }}</Badge></td>
                        <td class="px-4 py-3 font-mono">{{ r.serial_number }}</td>
                        <td class="px-4 py-3">{{ r.issued_at }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada sertifikat" />
        </div>
    </div>
</template>
