<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Receipt, Printer, Search, FilterX, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';

type Row = {
    id: number; br_number: string; invoice_number: string; invoice_date: string | null;
    customer: string | null; unit: string | null; unit_desc: string; spr: string | null;
    status: string; gross: number; discount: number; nett: number; dpp: number; ppn: number; grand_total: number;
};
const props = defineProps<{
    rows: { data: Row[]; links?: Array<any>; total?: number };  // paginator Laravel
    filters: { search?: string; status?: string };
    summary: { count: number; total: number };
}>();
const list = computed<Row[]>(() => props.rows?.data ?? []);

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusLabel: Record<string, string> = { booked: 'Booked', recognized: 'Diakui' };

// Filter server-side (mengikuti pola layar Sudah Booked Revenue).
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

/* Ekspor memakai saringan yang sama dengan layar, tetapi TANPA paginasi — jumlah
   barisnya cocok dengan kartu ringkasan, bukan dengan halaman yang kebetulan terbuka. */
function exportExcel() {
    // Saringan yang SUDAH diterapkan (props), bukan isian yang belum ditekan Cari.
    window.location.href = route('bs.sales-invoice.export', {
        search: props.filters.search || undefined, status: props.filters.status || undefined,
    });
}
function applyFilter() {
    selected.value = []; // hasil filter berubah → centang lama bisa tak lagi tampil
    router.get(route('bs.sales-invoice.index'), {
        search: search.value || undefined, status: status.value || undefined,
    }, { preserveState: true, replace: true });
}
const hasFilter = computed(() => Boolean(search.value || status.value));
function resetFilter() { search.value = ''; status.value = ''; applyFilter(); }

// ── Pilih massal untuk cetak invoice ──
// Dideklarasikan SEBELUM watch di bawah (watch bisa jalan saat setup).
const selected = ref<number[]>([]);
// "Pilih semua" = semua baris di HALAMAN INI (sama seperti register Cash Book).
const pageIds = computed<number[]>(() => list.value.map((r) => r.id));
const allChecked = computed<boolean>({
    get: () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
    set: (v: boolean) => { selected.value = v ? [...pageIds.value] : []; },
});
// Pindah halaman → centang halaman lain TIDAK dibuang (id-nya tetap sah untuk dicetak);
// yang dibuang hanya saat filter berubah (lihat applyFilter).
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Receipt class="h-6 w-6 text-primary-600" /> Invoice Penjualan</h1>
            <button type="button" @click="exportExcel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700">
                <FileSpreadsheet class="h-4 w-4" /> Excel
            </button>
            <p class="text-sm text-neutral-500">
                Rincian yang <strong>sudah Booked Revenue</strong>. Centang barisnya lalu cetak Invoice Penjualan
                secara massal — 1 invoice per halaman. Tanggal invoice memakai <strong>tanggal Booked Revenue</strong>,
                dan nilainya mengikuti perhitungan resmi (DPP Nilai Lain 11/12, PPN 12%).
            </p>
            <p class="text-sm text-neutral-500">Booked Revenue yang <strong>dibatalkan</strong> tidak tampil di sini — jurnalnya sudah dibalik, jadi tidak boleh ditagihkan.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Bisa Ditagihkan</p><p class="mt-1 text-2xl font-bold">{{ summary.count }}<span class="ml-1 text-sm font-normal text-neutral-400">unit</span></p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Grand Total</p><p class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(summary.total) }}</p></div>
        </div>

        <!-- Bilah aksi massal — sticky agar tetap terjangkau saat menggulir. -->
        <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> invoice dipilih</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a :href="route('bs.sales-invoice.print-bulk', { ids: selected.join(',') })" target="_blank" rel="noopener"
                   title="Cetak Invoice Penjualan untuk semua baris terpilih — 1 invoice per halaman."
                   class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak Invoice ({{ selected.length }})</a>
                <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
            </div>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[220px] flex-1">
                <label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Cari</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="search" @keyup.enter="applyFilter" @change="applyFilter" placeholder="No. BR / customer / unit" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Status</label>
                <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 pr-6 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">Semua</option>
                    <option value="booked">Booked</option>
                    <option value="recognized">Diakui</option>
                </select>
            </div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="ml-auto inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <template v-if="list.length">
        <TableTopScroll>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="w-10 px-3 py-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :disabled="!pageIds.length" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua baris'" /></th>
                        <th class="whitespace-nowrap px-4 py-3">No. Invoice</th>
                        <th class="whitespace-nowrap px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">Customer / Unit</th>
                        <th class="whitespace-nowrap px-4 py-3">No. SPR</th>
                        <th class="whitespace-nowrap px-4 py-3">No. BR</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">Harga Nett</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">PPN</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">Grand Total</th>
                        <th class="px-4 py-3 text-center">Cetak</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in list" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="selected.includes(r.id) ? 'bg-primary-500/10' : ''">
                        <td class="px-3 py-2.5"><input v-model="selected" type="checkbox" :value="r.id" :class="cbCls" title="Pilih untuk cetak Invoice Penjualan" /></td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">{{ r.invoice_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-4 py-2.5">
                            <p class="whitespace-nowrap">{{ r.customer ?? '—' }}</p>
                            <p class="max-w-[280px] truncate text-xs text-neutral-400" :title="r.unit_desc">{{ r.unit_desc }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.spr ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.br_number }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'recognized' ? 'success' : 'warning'" size="sm">{{ statusLabel[r.status] ?? r.status }}</Badge></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(r.nett) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.ppn) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.grand_total) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <a :href="route('bs.sales-invoice.print', r.id)" target="_blank" rel="noopener" title="Cetak invoice ini" class="inline-flex text-primary-600 hover:text-primary-800"><Printer class="h-4 w-4" /></a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <!-- Pager — WAJIB: daftarnya dipaginasi 50/halaman; tanpa ini baris ke-51 dst. tak akan
             pernah bisa dibuka, dicentang, apalagi dicetak. Pola sama dgn Sudah Booked Revenue. -->
        <div class="flex flex-wrap items-center gap-1">
            <p class="mr-2 text-xs text-neutral-500">Menampilkan {{ list.length }} dari {{ rows.total ?? list.length }} baris</p>
            <template v-if="(rows.links?.length ?? 0) > 3">
                <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                    class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
            </template>
        </div>
        </template>

        <EmptyState v-else-if="hasFilter" title="Tidak ada hasil" description="Tidak ada Booked Revenue yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        <EmptyState v-else title="Belum ada yang bisa ditagihkan"
            description="Belum ada rincian yang sudah Booked Revenue. Buat Booked Revenue dulu — barisnya akan otomatis muncul di sini.">
            <template #icon><Receipt class="h-12 w-12" /></template>
        </EmptyState>
    </div>
</template>
