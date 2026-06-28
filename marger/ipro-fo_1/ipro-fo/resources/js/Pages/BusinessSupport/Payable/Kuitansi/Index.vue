<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { ArrowLeft, ReceiptText, Search, FilterX } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Row = { id: number; kuitansi_number: string; kuitansi_date: string | null; supplier: { name: string } | null; alat_transaksi: string; total: number; status: string };
const props = defineProps<{ kuitansis: Row[] }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
type BadgeVariant = 'success' | 'neutral' | 'primary' | 'warning' | 'danger' | 'info';
const statusMeta: Record<string, { v: BadgeVariant; l: string }> = {
    pending:  { v: 'warning', l: 'Menunggu' },
    active:   { v: 'success', l: 'Aktif' },
    rejected: { v: 'danger',  l: 'Ditolak' },
    void:     { v: 'danger',  l: 'Void' },
};
function stMeta(s: string): { v: BadgeVariant; l: string } { return statusMeta[s] ?? { v: 'neutral', l: s }; }
const alat: Record<string, string> = { tunai: 'Tunai', transfer: 'Transfer', giro: 'Cek/Giro' };

// Nilai tampilan per-kolom — dipakai utk render, opsi filter & pencocokan filter agar konsisten.
const dNo = (k: Row) => k.kuitansi_number ?? '';
const dTgl = (k: Row) => fmtDate(k.kuitansi_date);
const dSupplier = (k: Row) => k.supplier?.name ?? '—';
const dAlat = (k: Row) => alat[k.alat_transaksi] ?? k.alat_transaksi ?? '—';
const dTotal = (k: Row) => fmtRp(k.total);
const dStatus = (k: Row) => stMeta(k.status).l;

function distinct(fn: (k: Row) => string) {
    const s = new Set<string>();
    for (const k of props.kuitansis) s.add(fn(k));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optTgl = computed(() => distinct(dTgl));
const optSupplier = computed(() => distinct(dSupplier));
const optAlat = computed(() => distinct(dAlat));
const optTotal = computed(() => distinct(dTotal));
const optStatus = computed(() => distinct(dStatus));

// Filter global + checklist per-kolom (kosong = tampilkan semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], tgl: [], supplier: [], alat: [], total: [], status: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

const filtered = computed<Row[]>(() => props.kuitansis.filter((k) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(k), dTgl(k), dSupplier(k), dAlat(k), dTotal(k), dStatus(k)].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.no.length && !col.no.includes(dNo(k))) return false;
    if (col.tgl.length && !col.tgl.includes(dTgl(k))) return false;
    if (col.supplier.length && !col.supplier.includes(dSupplier(k))) return false;
    if (col.alat.length && !col.alat.includes(dAlat(k))) return false;
    if (col.total.length && !col.total.includes(dTotal(k))) return false;
    if (col.status.length && !col.status.includes(dStatus(k))) return false;
    return true;
}));

// Ringkasan mengikuti hasil filter.
const summary = computed(() => ({
    active: filtered.value.filter((k) => k.status === 'active').reduce((s, k) => s + Number(k.total), 0),
    count: filtered.value.length,
}));

// Buang nilai filter yang sudah tak ada bila data berubah.
watch([optNo, optTgl, optSupplier, optAlat, optTotal, optStatus], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.tgl = prune(col.tgl, optTgl.value); col.supplier = prune(col.supplier, optSupplier.value);
    col.alat = prune(col.alat, optAlat.value); col.total = prune(col.total, optTotal.value); col.status = prune(col.status, optStatus.value);
});
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-danger-600" /> Kuitansi Hutang</h1>
        </div>

        <!-- Ringkasan mengikuti hasil filter checklist. -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card border-l-4 border-success-500 p-4"><div class="text-xs uppercase text-neutral-400">Total Dibayar (aktif)</div><div class="mt-1 text-xl font-bold text-success-700">{{ fmtRp(summary.active) }}</div></div>
            <div class="card border-l-4 border-neutral-300 p-4 dark:border-neutral-600"><div class="text-xs uppercase text-neutral-400">Jumlah Kuitansi</div><div class="mt-1 flex items-baseline gap-2 text-xl font-bold">{{ summary.count }}<span v-if="hasFilter" class="text-xs font-normal text-neutral-400">dari {{ props.kuitansis.length }}</span></div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. Kuitansi</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tanggal</th><th class="px-4 pt-3 pb-1.5">Supplier</th><th class="px-4 pt-3 pb-1.5">Alat</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Total</th><th class="px-4 pt-3 pb-1.5 text-center">Status</th>
                    </tr>
                    <!-- Filter checklist per-kolom -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.supplier" :options="optSupplier" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.alat" :options="optAlat" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.total" :options="optTotal" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" :options="optStatus" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="k in filtered" :key="k.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.ap.kuitansi.show', k.id))">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">{{ k.kuitansi_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(k.kuitansi_date) }}</td>
                        <td class="px-4 py-2.5">{{ k.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ alat[k.alat_transaksi] ?? k.alat_transaksi }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(k.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="stMeta(k.status).v" size="sm">{{ stMeta(k.status).l }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.kuitansis.length" title="Belum ada kuitansi" description="Voucher pembayaran supplier dibuat dari Cash Book → Registrasi Pengeluaran Kas &amp; Bank (AP)." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada kuitansi yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
