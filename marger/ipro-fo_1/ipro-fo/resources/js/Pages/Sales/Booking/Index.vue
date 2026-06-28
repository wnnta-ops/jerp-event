<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    bookings: any;
    filters: Record<string, any>;
    statuses: Array<{ value: string; label: string; color: string }>;
    projects: Array<{ id: number; code: string; name: string }>;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

const refresh = debounce(() => {
    router.get(route('bookings.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}, 300);

watch([search, status], refresh);

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
function colorFor(value: string): BadgeVariant {
    return (props.statuses.find(s => s.value === value)?.color ?? 'neutral') as BadgeVariant;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Booking</h1>
                <p class="text-sm text-neutral-500">Daftar booking customer</p>
            </div>
            <Link :href="route('bookings.create')">
                <Button>
                    <template #icon-left><Plus class="h-4 w-4" /></template>
                    Booking Baru
                </Button>
            </Link>
        </div>

        <div class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
            <Input v-model="search" label="Cari Nomor" placeholder="BK/JRES/..." />
            <div>
                <label class="label-base">Status</label>
                <select v-model="status" class="input-base">
                    <option value="">Semua</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Skema</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="b in bookings.data" :key="b.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ b.booking_number }}</td>
                        <td class="px-4 py-3 font-medium">{{ b.customer?.full_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ b.unit?.unit_code }}</td>
                        <td class="px-4 py-3">{{ b.payment_scheme?.name }}</td>
                        <td class="px-4 py-3">{{ b.booking_date }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(b.pricing.total) }}</td>
                        <td class="px-4 py-3"><Badge :variant="colorFor(b.status)" dot>{{ b.status_label }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('bookings.show', b.id)" class="text-primary-600 hover:underline text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="bookings.data.length === 0" title="Belum ada booking" description="Mulai dengan menambah booking baru.">
                <template #action>
                    <Link :href="route('bookings.create')"><Button>+ Booking Baru</Button></Link>
                </template>
            </EmptyState>
        </div>
    </div>
</template>
