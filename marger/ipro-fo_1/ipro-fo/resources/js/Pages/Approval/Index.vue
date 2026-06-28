<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ approvals: any; filters: Record<string, any> }>();

const tab = ref(props.filters.tab ?? 'mine');
watch(tab, (v) => router.get(route('approvals.index'), { tab: v }, { preserveState: true, replace: true }));
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Pusat Approval</h1>

        <div class="flex gap-2 border-b border-neutral-200 dark:border-neutral-800">
            <button @click="tab = 'mine'" :class="['px-4 py-2 text-sm font-medium', tab === 'mine' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-neutral-500']">
                Tugas Saya
            </button>
            <button @click="tab = 'all'" :class="['px-4 py-2 text-sm font-medium', tab === 'all' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-neutral-500']">
                Semua
            </button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">Dokumen</th>
                        <th class="px-4 py-3">Diajukan</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="a in approvals.data" :key="a.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">#{{ a.id }}</td>
                        <td class="px-4 py-3 capitalize">{{ a.document_type?.replace(/_/g, ' ') }}</td>
                        <td class="px-4 py-3">{{ new Date(a.submitted_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="a.status === 'approved' ? 'success' : a.status === 'rejected' ? 'danger' : 'warning'" dot>{{ a.status }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('approvals.show', a.id)" class="text-primary-600 hover:underline text-sm">Detail →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!approvals.data?.length" title="Tidak ada approval tertunda" />
        </div>
    </div>
</template>
