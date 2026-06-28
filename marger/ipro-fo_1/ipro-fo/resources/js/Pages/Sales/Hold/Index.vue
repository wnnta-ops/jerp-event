<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ holds: any; filters: Record<string, any> }>();

function release(id: number) {
    if (confirm('Lepas hold ini? Unit kembali AVAILABLE.')) {
        router.post(route('holds.release', id));
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold">Unit Hold</h1>
                <p class="text-sm text-neutral-500">Reservasi unit sementara untuk customer</p>
            </div>
            <Link :href="route('holds.create')">
                <Button><template #icon-left><Plus class="h-4 w-4" /></template>Hold Unit Baru</Button>
            </Link>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Hold Sejak</th>
                        <th class="px-4 py-3">Expires</th>
                        <th class="px-4 py-3">Alasan</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="h in holds.data" :key="h.id">
                        <td class="px-4 py-3 font-mono">{{ h.unit?.unit_code }}</td>
                        <td class="px-4 py-3">{{ h.customer?.full_name }}</td>
                        <td class="px-4 py-3">{{ new Date(h.held_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3">{{ new Date(h.expires_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">{{ h.reason ?? '-' }}</td>
                        <td class="px-4 py-3"><Badge :variant="h.status === 'active' ? 'info' : 'neutral'" dot>{{ h.status }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <button v-if="h.status === 'active'" @click="release(h.id)" class="text-danger-600 text-sm">Release</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!holds.data?.length" title="Tidak ada hold aktif" />
        </div>
    </div>
</template>
