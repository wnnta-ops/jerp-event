<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, FileSpreadsheet, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ view?: string; rows: Array<any>; totals: any; details: Array<any>; dateFrom?: string | null; dateTo?: string | null; pulled?: boolean }>();
const mode = ref(props.view === 'detail' ? 'detail' : 'summary');
const dateFrom = ref(props.dateFrom ?? '');
const dateTo = ref(props.dateTo ?? '');
// Data ditarik hanya saat klik "Tampilkan" (pull=1) — hindari load berat di data banyak.
function applyFilter() {
    router.get(route('bs.ap.aging'), {
        view: mode.value, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined, pull: 1,
    }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.section', { section: 'query' })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Query — Account Payable</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-danger-600" /> Aging Hutang</h1>
            <div class="flex gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
                <button @click="mode = 'summary'" class="rounded px-3 py-1" :class="mode === 'summary' ? 'bg-primary-600 text-white' : ''">Summary</button>
                <button @click="mode = 'detail'" class="rounded px-3 py-1" :class="mode === 'detail' ? 'bg-primary-600 text-white' : ''">Detail</button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Umur hutang dihitung dari tanggal jatuh tempo tiap invoice yang belum lunas, terhadap tanggal acuan.</p>
        <p class="text-xs text-neutral-500">Outstanding = nilai <b>NET</b> (net_payable setelah PPN &amp; PPh) − pembayaran s/d tanggal acuan. Basis &amp; cutoff sama dengan <b>Kartu Hutang</b>, jadi Total per supplier konsisten antar laporan.</p>

        <!-- #139: kontrol periode + unduh -->
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Invoice Dari</label>
                <input v-model="dateFrom" type="date" title="Batas bawah tanggal invoice" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Per Tanggal (acuan aging)</label>
                <input v-model="dateTo" type="date" title="Tanggal acuan perhitungan umur hutang" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button @click="applyFilter" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <div class="ml-auto flex gap-2">
                <a :href="route('bs.ap.aging.export-excel', { date_from: dateFrom || undefined, date_to: dateTo || undefined })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.ap.aging.export-pdf', { date_from: dateFrom || undefined, date_to: dateTo || undefined })" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-danger-300 bg-danger-50 px-3 text-sm font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-4 w-4" /> PDF</a>
            </div>
        </div>

        <template v-if="pulled">
        <!-- SUMMARY: per supplier -->
        <div v-if="mode === 'summary'" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Supplier</th><th class="px-4 py-3 text-right">Belum JT</th><th class="px-4 py-3 text-right">1–30</th><th class="px-4 py-3 text-right">31–60</th><th class="px-4 py-3 text-right">61–90</th><th class="px-4 py-3 text-right">&gt;90</th><th class="px-4 py-3 text-right">Total</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.current) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d30) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d60) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.d90) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.d90p) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800"><tr>
                    <td class="px-4 py-3">Total</td>
                    <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.current) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d30) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d60) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.d90) }}</td><td class="px-4 py-3 text-right font-mono text-danger-600">{{ fmtRp(totals.d90p) }}</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.total) }}</td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada hutang outstanding" description="Semua invoice hutang sudah lunas." />
        </div>

        <!-- DETAIL: per invoice -->
        <div v-else class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Supplier</th><th class="px-4 py-3">No Invoice</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3 text-right">Hari</th><th class="px-4 py-3">Bucket</th><th class="px-4 py-3 text-right">Outstanding</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(d, i) in details" :key="i">
                        <td class="px-4 py-2.5">{{ d.supplier }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ d.invoice_no }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(d.due_date) }}</td>
                        <td class="px-4 py-2.5 text-right" :class="d.overdue > 0 ? 'text-danger-600' : 'text-neutral-400'">{{ d.overdue }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="d.bucket === '>90' ? 'danger' : (d.bucket === 'Belum JT' ? 'success' : 'warning')" size="sm">{{ d.bucket }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(d.outstanding) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!details?.length" title="Tidak ada hutang outstanding" />
        </div>
        </template>
        <EmptyState v-else title="Klik Tampilkan untuk menarik data" description="Pilih periode (Per Tanggal acuan), lalu klik tombol Tampilkan. Data aging tidak dimuat otomatis agar ringan saat data banyak." />
    </div>
</template>
