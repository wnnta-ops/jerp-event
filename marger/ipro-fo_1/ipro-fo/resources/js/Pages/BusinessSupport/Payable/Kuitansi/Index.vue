<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { ArrowLeft, ReceiptText, Search, FilterX } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import Pagination from '@/Components/UI/Pagination.vue';

type Row = { id: number; kuitansi_number: string; kuitansi_date: string | null; supplier: { name: string } | null; alat_transaksi: string; total: number; status: string };
type Kolom = 'no' | 'tgl' | 'supplier' | 'alat' | 'total' | 'status';
const props = defineProps<{
    /** Hasil paginate() — 25 baris per halaman, sudah disaring server. */
    kuitansis: { data: Row[]; links?: any[]; from?: number | null; to?: number | null; total?: number };
    /** Ringkasan atas SELURUH hasil saringan. */
    summary: { active: number; count: number };
    jumlahSemua: number;
    opsi: Record<Kolom, string[]>;
    filters: { search?: string; c?: Partial<Record<Kolom, string[]>> };
}>();
const rows = computed<Row[]>(() => props.kuitansis.data ?? []);

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

const optNo = computed(() => props.opsi?.no ?? []);
const optTgl = computed(() => props.opsi?.tgl ?? []);
const optSupplier = computed(() => props.opsi?.supplier ?? []);
const optAlat = computed(() => props.opsi?.alat ?? []);
const optTotal = computed(() => props.opsi?.total ?? []);
const optStatus = computed(() => props.opsi?.status ?? []);

// Filter global + checklist per-kolom — DIKERJAKAN SERVER; perubahan dikirim dengan jeda singkat
// dan daftar kembali ke halaman 1.
const KOLOM: Kolom[] = ['no', 'tgl', 'supplier', 'alat', 'total', 'status'];
const search = ref(props.filters?.search ?? '');
const col = reactive<Record<string, string[]>>(Object.fromEntries(KOLOM.map((k) => [k, [...(props.filters?.c?.[k] ?? [])]])));
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; KOLOM.forEach((k) => (col[k] = [])); }
let jeda: ReturnType<typeof setTimeout> | null = null;
watch([search, () => JSON.stringify(col)], () => {
    if (jeda) clearTimeout(jeda);
    jeda = setTimeout(() => {
        const c: Record<string, string[]> = {};
        KOLOM.forEach((k) => { if (col[k].length) c[k] = col[k]; });
        router.get(window.location.pathname, { search: search.value || undefined, c: Object.keys(c).length ? c : undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 450);
});

// Ringkasan dihitung server atas SELURUH hasil saringan.
const summary = computed(() => props.summary);
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
            <div class="card border-l-4 border-neutral-300 p-4 dark:border-neutral-600"><div class="text-xs uppercase text-neutral-400">Jumlah Kuitansi</div><div class="mt-1 flex items-baseline gap-2 text-xl font-bold">{{ summary.count }}<span v-if="hasFilter" class="text-xs font-normal text-neutral-400">dari {{ props.jumlahSemua }}</span></div></div>
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
                    <tr v-for="k in rows" :key="k.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.ap.kuitansi.show', k.id))">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">{{ k.kuitansi_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(k.kuitansi_date) }}</td>
                        <td class="px-4 py-2.5">{{ k.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ alat[k.alat_transaksi] ?? k.alat_transaksi }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(k.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="stMeta(k.status).v" size="sm">{{ stMeta(k.status).l }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.jumlahSemua" title="Belum ada kuitansi" description="Voucher pembayaran supplier dibuat dari Cash Book → Registrasi Pengeluaran Kas &amp; Bank (AP)." />
            <EmptyState v-else-if="!rows.length" title="Tidak ada hasil" description="Tidak ada kuitansi yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
        <Pagination :data="kuitansis" satuan="kuitansi" />
    </div>
</template>
