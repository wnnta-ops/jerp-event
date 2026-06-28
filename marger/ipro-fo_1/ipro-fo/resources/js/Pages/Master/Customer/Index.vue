<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus, Search } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    customers: any;
    filters: Record<string, any>;
    statuses: Array<{ value: string; label: string; color: string }>;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

const refresh = debounce(() => {
    router.get(route('customers.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}, 300);

watch([search, status], refresh);

type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
function colorFor(value: string): BadgeVariant {
    return (props.statuses.find(s => s.value === value)?.color ?? 'neutral') as BadgeVariant;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Customer</h1>
                <p class="text-sm text-neutral-500">Daftar customer terdaftar di Java Core System</p>
            </div>
            <Link :href="route('customers.create')">
                <Button>
                    <template #icon-left><Plus class="h-4 w-4" /></template>
                    Customer Baru
                </Button>
            </Link>
        </div>

        <div class="card p-4">
            <div class="flex gap-3 items-end">
                <div class="flex-1 max-w-md">
                    <Input v-model="search" placeholder="Cari nama / NIK / HP..." label="Cari">
                        <template #prefix><Search class="absolute left-3 top-3 h-4 w-4 text-neutral-400" /></template>
                    </Input>
                </div>
                <div class="w-48">
                    <label class="label-base">Status</label>
                    <select v-model="status" class="input-base">
                        <option value="">Semua</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">No HP</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in customers.data" :key="c.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ c.code }}</td>
                        <td class="px-4 py-3 font-medium">{{ c.full_name }}</td>
                        <td class="px-4 py-3">{{ c.phone || '-' }}</td>
                        <td class="px-4 py-3 text-neutral-500">{{ c.email || '-' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="colorFor(c.status)" dot>{{ c.status }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('customers.show', c.id)" class="text-primary-600 hover:underline text-sm">
                                Lihat →
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState
                v-if="customers.data.length === 0"
                title="Belum ada customer"
                description="Buat customer baru untuk memulai pipeline sales."
            >
                <template #action>
                    <Link :href="route('customers.create')">
                        <Button>+ Customer Baru</Button>
                    </Link>
                </template>
            </EmptyState>
        </div>
    </div>
</template>
