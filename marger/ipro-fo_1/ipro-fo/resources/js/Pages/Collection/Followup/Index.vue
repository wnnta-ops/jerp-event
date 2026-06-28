<script setup lang="ts">
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ followups: any; filters: Record<string, any> }>();
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Follow-up Customer</h1>
        <p class="text-sm text-neutral-500">Aktivitas follow-up customer oleh Sales</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Channel</th>
                        <th class="px-4 py-3">Tanggal Kontak</th>
                        <th class="px-4 py-3">Result</th>
                        <th class="px-4 py-3">Catatan</th>
                        <th class="px-4 py-3">Next Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="f in followups.data" :key="f.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3">{{ f.customer?.full_name }}</td>
                        <td class="px-4 py-3 capitalize">{{ f.channel }}</td>
                        <td class="px-4 py-3">{{ new Date(f.contacted_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3"><Badge>{{ f.result }}</Badge></td>
                        <td class="px-4 py-3 max-w-md truncate">{{ f.notes }}</td>
                        <td class="px-4 py-3">{{ f.next_action_at ? new Date(f.next_action_at).toLocaleDateString('id-ID') : '-' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!followups.data?.length" title="Belum ada follow-up" />
        </div>
    </div>
</template>
