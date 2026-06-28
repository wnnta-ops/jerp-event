<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, CircleDollarSign, CheckCircle2, Clock, XCircle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: any;
    filters: { search?: string; status?: string };
    summary: { booked_total: number; recognized_total: number; booked_count: number; recognized_count: number; cancelled_count: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const statusMeta: Record<string, { label: string; variant: 'warning' | 'success' | 'neutral' }> = {
    booked:     { label: 'Booked',    variant: 'warning' },
    recognized: { label: 'Diakui',    variant: 'success' },
    cancelled:  { label: 'Dibatalkan',variant: 'neutral' },
};

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('bs.booked-revenue.index'), { search: val.search || undefined, status: val.status || undefined }, { preserveState: true, replace: true }), 300);
});
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CircleDollarSign class="h-6 w-6 text-primary-600" /> Booked Revenue <span class="text-base font-normal text-neutral-400">(Pendapatan Ter-booking)</span></h1>
            <Link :href="route('bs.booked-revenue.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Booking Baru</Button></Link>
        </div>

        <!-- Ringkasan -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Clock class="h-4 w-4" /> Booked (belum diakui)</div>
                <div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(summary.booked_total) }}</div>
                <div class="text-xs text-neutral-500">{{ summary.booked_count }} record</div>
            </div>
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><CheckCircle2 class="h-4 w-4" /> Pendapatan Diakui</div>
                <div class="mt-1 text-xl font-bold text-emerald-600">{{ fmtRp(summary.recognized_total) }}</div>
                <div class="text-xs text-neutral-500">{{ summary.recognized_count }} record</div>
            </div>
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><CircleDollarSign class="h-4 w-4" /> Total Pipeline</div>
                <div class="mt-1 text-xl font-bold text-primary-600">{{ fmtRp(summary.booked_total + summary.recognized_total) }}</div>
                <div class="text-xs text-neutral-500">Booked + Diakui</div>
            </div>
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><XCircle class="h-4 w-4" /> Dibatalkan</div>
                <div class="mt-1 text-xl font-bold text-neutral-500">{{ summary.cancelled_count }}</div>
                <div class="text-xs text-neutral-500">record</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari no. BR / customer / unit…" :class="[inputCls, 'w-64']" />
            <select v-model="f.status" :class="inputCls">
                <option value="">Semua Status</option>
                <option value="booked">Booked</option>
                <option value="recognized">Diakui</option>
                <option value="cancelled">Dibatalkan</option>
            </select>
        </div>

        <!-- Tabel -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. BR</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3 text-right">Nilai Revenue</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.br_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.booking_date) }}</td>
                        <td class="px-4 py-2.5"><span v-if="r.customer"><span class="font-mono text-xs text-neutral-400">{{ r.customer.code }}</span> {{ r.customer.name }}</span><span v-else>—</span></td>
                        <td class="px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="statusMeta[r.status]?.variant ?? 'neutral'" size="sm">{{ statusMeta[r.status]?.label ?? r.status }}</Badge></td>
                        <td class="px-4 py-2.5 text-right"><Link :href="route('bs.booked-revenue.show', r.id)" class="text-sm font-medium text-primary-600 hover:underline">Lihat</Link></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada Booked Revenue" description="Klik Booking Baru untuk mem-booking pendapatan dari customer & unit." />
        </div>

        <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>
    </div>
</template>
