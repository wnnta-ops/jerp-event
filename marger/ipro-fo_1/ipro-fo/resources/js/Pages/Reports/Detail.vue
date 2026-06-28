<script setup lang="ts">
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, PieChart, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Col { label: string; type: string; key: string }

const props = defineProps<{
    report: { key: string; label: string; group: string; hasStatus: boolean };
    columns: Col[];
    rows: any;
    filters: { from: string | null; to: string | null; status: string | null };
    statuses: string[];
}>();

const f = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: props.filters.status ?? '',
});

function apply() {
    router.get(route('reports.detail', props.report.key), cleaned(), { preserveState: true, preserveScroll: true });
}
function reset() {
    f.from = ''; f.to = ''; f.status = '';
    router.get(route('reports.detail', props.report.key));
}
function cleaned() {
    const o: Record<string, string> = {};
    if (f.from) o.from = f.from;
    if (f.to) o.to = f.to;
    if (f.status) o.status = f.status;
    return o;
}
function exportUrl() {
    const q = new URLSearchParams(cleaned()).toString();
    return route('reports.export', props.report.key) + (q ? '?' + q : '');
}
function fmt(v: any, type: string): string {
    if (v === null || v === undefined || v === '') return '—';
    if (type === 'money') return 'Rp ' + Number(v).toLocaleString('id-ID');
    if (type === 'date') return String(v).slice(0, 10);
    if (type === 'datetime') return String(v).slice(0, 16).replace('T', ' ');
    return String(v);
}
</script>

<template>
    <Head :title="`Laporan ${report.label}`" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <Link :href="route('reports.index')" class="text-sm text-neutral-500 hover:text-primary-600 inline-flex items-center gap-1"><ArrowLeft class="h-3.5 w-3.5" /> Semua Laporan</Link>
                <h1 class="text-2xl font-bold mt-1">Laporan {{ report.label }} — Detail</h1>
            </div>
            <div class="flex gap-2">
                <Link :href="route('reports.summary', report.key)">
                    <Button variant="secondary"><template #icon-left><PieChart class="h-4 w-4" /></template>Ringkasan</Button>
                </Link>
                <a :href="exportUrl()">
                    <Button><template #icon-left><Download class="h-4 w-4" /></template>Export CSV</Button>
                </a>
            </div>
        </div>

        <!-- Filter -->
        <div class="card p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="label-base">Dari Tanggal</label>
                <input type="date" v-model="f.from" class="input-base" />
            </div>
            <div>
                <label class="label-base">Sampai Tanggal</label>
                <input type="date" v-model="f.to" class="input-base" />
            </div>
            <div v-if="report.hasStatus && statuses.length">
                <label class="label-base">Status</label>
                <select v-model="f.status" class="input-base">
                    <option value="">Semua</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>
            <div class="flex gap-2">
                <Button @click="apply">Terapkan</Button>
                <Button variant="ghost" @click="reset">Reset</Button>
            </div>
        </div>

        <!-- Tabel -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th v-for="c in columns" :key="c.key" class="px-4 py-3 whitespace-nowrap"
                            :class="c.type === 'money' ? 'text-right' : ''">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(row, i) in rows.data" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td v-for="c in columns" :key="c.key" class="px-4 py-2.5 whitespace-nowrap"
                            :class="[c.type === 'money' ? 'text-right font-mono tabular-nums' : '', c.label === 'Status' ? 'capitalize' : '']">
                            {{ fmt(row[c.key], c.type) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Tidak ada data" description="Coba ubah filter tanggal/status." />
        </div>

        <!-- Pagination -->
        <div v-if="rows.data?.length" class="flex items-center justify-between text-sm text-neutral-500">
            <span>Menampilkan {{ rows.from }}–{{ rows.to }} dari {{ rows.total }} baris</span>
            <div class="flex gap-2">
                <Link v-if="rows.prev_page_url" :href="rows.prev_page_url" preserve-scroll
                      class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 hover:bg-neutral-50 dark:hover:bg-neutral-800">← Sebelumnya</Link>
                <Link v-if="rows.next_page_url" :href="rows.next_page_url" preserve-scroll
                      class="px-3 py-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 hover:bg-neutral-50 dark:hover:bg-neutral-800">Berikutnya →</Link>
            </div>
        </div>
    </div>
</template>
