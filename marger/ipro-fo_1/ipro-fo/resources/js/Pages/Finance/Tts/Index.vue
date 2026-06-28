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
    tts: any;
    filters: Record<string, any>;
    statuses: Array<any>;
    methods: Array<any>;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const method = ref(props.filters.payment_method ?? '');

const refresh = debounce(() => {
    router.get(route('tts.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        payment_method: method.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}, 300);
watch([search, status, method], refresh);

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function color(s: string) { return props.statuses.find(x => x.value === s)?.color ?? 'neutral'; }
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">TTS (Tanda Terima Setoran)</h1>
                <p class="text-sm text-neutral-500">Penerimaan kas dari customer</p>
            </div>
            <Link :href="route('tts.create')">
                <Button><template #icon-left><Plus class="h-4 w-4" /></template>TTS Baru</Button>
            </Link>
        </div>

        <div class="card p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
            <Input v-model="search" label="Cari Nomor" />
            <div>
                <label class="label-base">Status</label>
                <select v-model="status" class="input-base">
                    <option value="">Semua</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
            <div>
                <label class="label-base">Cara Bayar</label>
                <select v-model="method" class="input-base">
                    <option value="">Semua</option>
                    <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Booking</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">Cara Bayar</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in tts.data" :key="t.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ t.tts_number }}</td>
                        <td class="px-4 py-3">{{ t.customer?.full_name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ t.booking?.booking_number ?? '-' }}</td>
                        <td class="px-4 py-3">{{ t.received_date }}</td>
                        <td class="px-4 py-3 capitalize">{{ t.payment_method }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(t.amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="color(t.status)" dot>{{ t.status }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('tts.show', t.id)" class="text-primary-600 hover:underline text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="tts.data.length === 0" title="Belum ada TTS" />
        </div>
    </div>
</template>
