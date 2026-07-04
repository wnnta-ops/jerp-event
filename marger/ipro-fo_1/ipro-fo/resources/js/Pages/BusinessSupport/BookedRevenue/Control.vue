<script setup lang="ts">
import { reactive, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck, Stamp, Receipt, ClipboardCheck, ListChecks } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    type: string;
    title: string;
    subtitle: string;
    extraLabel: string | null;
    extraIsMoney: boolean;
    rows: any;
    filters: { search?: string; status?: string };
    summary: { total: number; byStatus: Record<string, number> };
}>();

const routeName = computed(() => ({ bast: 'bs.booked-revenue.control.bast', ajb: 'bs.booked-revenue.control.ajb', fp: 'bs.booked-revenue.control.fp' } as Record<string, string>)[props.type] ?? 'bs.booked-revenue.control.bast');
const icon = computed(() => ({ bast: FileCheck, ajb: Stamp, fp: Receipt } as Record<string, any>)[props.type] ?? ClipboardCheck);

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function statusMeta(s: string): { label: string; variant: 'success' | 'info' | 'warning' | 'neutral' | 'danger' } {
    const m: Record<string, any> = {
        active: { label: 'Aktif', variant: 'success' }, issued: { label: 'Terbit', variant: 'success' },
        done: { label: 'Selesai', variant: 'success' }, ready: { label: 'Siap', variant: 'info' },
        draft: { label: 'Draft', variant: 'neutral' }, pending: { label: 'Pending', variant: 'warning' },
        scheduled: { label: 'Terjadwal', variant: 'warning' }, void: { label: 'Void', variant: 'danger' },
        cancelled: { label: 'Batal', variant: 'danger' }, rejected: { label: 'Ditolak', variant: 'danger' },
    };
    return m[s] ?? { label: s, variant: 'neutral' };
}
const statuses = computed(() => Object.keys(props.summary.byStatus ?? {}));

const f = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route(routeName.value), { search: val.search || undefined, status: val.status || undefined }, { preserveState: true, replace: true }), 300);
});
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const cardTints = ['text-primary-600', 'text-emerald-600', 'text-amber-600', 'text-sky-600', 'text-rose-600', 'text-violet-600'];
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><component :is="icon" class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
            <p class="text-sm text-neutral-500">{{ subtitle }} — monitoring dokumen per unit &amp; customer.</p>
        </div>

        <!-- Ringkasan: total + per status -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><ListChecks class="h-4 w-4" /> Total Dokumen</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ summary.total }}</div>
            </div>
            <div v-for="(s, i) in statuses" :key="s" class="card p-4">
                <div class="text-xs uppercase text-neutral-400">{{ statusMeta(s).label }}</div>
                <div class="mt-1 text-2xl font-bold" :class="cardTints[(i + 1) % cardTints.length]">{{ summary.byStatus[s] }}</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari no. dokumen / customer / unit…" :class="[inputCls, 'w-72']" />
            <select v-model="f.status" :class="inputCls">
                <option value="">Semua Status</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ statusMeta(s).label }}</option>
            </select>
        </div>

        <!-- Tabel -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Dokumen</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Unit</th>
                    <th v-if="extraLabel" class="px-4 py-3" :class="extraIsMoney ? 'text-right' : ''">{{ extraLabel }}</th>
                    <th class="px-4 py-3">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.number ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td v-if="extraLabel" class="px-4 py-2.5" :class="extraIsMoney ? 'text-right tabular-nums' : ''">{{ extraIsMoney ? fmtRp(r.extra) : (r.extra ?? '—') }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="statusMeta(r.status).variant" size="sm">{{ statusMeta(r.status).label }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" :title="'Belum ada ' + title" description="Dokumen akan muncul di sini saat diterbitkan dari modul terkait." />
        </div>

        <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>
    </div>
</template>
