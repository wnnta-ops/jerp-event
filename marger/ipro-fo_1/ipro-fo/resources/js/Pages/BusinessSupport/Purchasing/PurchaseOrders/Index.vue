<script setup lang="ts">
/**
 * Daftar Purchase Order — RINCIAN & PERILAKUNYA DISAMAKAN dengan daftar Invoice Hutang
 * (BusinessSupport/Payable/Index.vue): kartu ringkasan yang mengikuti filter, pencarian
 * global, filter per-kolom (ketik "berisi" + checklist nilai), checklist baris di kiri
 * untuk Cetak PO, ekspor Excel sesuai tampilan, dan scrollbar horizontal atas tersinkron.
 */
import { router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Plus, ShoppingCart, Search, FileSpreadsheet, FilterX, Printer, ClipboardList, CircleDollarSign, PackageCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Row = {
    id: number; po_number: string; po_date: string | null; due_date: string | null;
    supplier: { name: string } | null; project: string | null; po_type: string | null; notes: string | null;
    lines_count: number; subtotal: number; ppn_amount: number; total: number; status: string;
    printed_at: string | null; print_count: number;
};
const props = defineProps<{ orders: Row[] }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function fmtDateTime(d: string | null) { return d ? new Date(d.replace(' ', 'T')).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
const statusLabel: Record<string, string> = { draft: 'Draft', approved: 'Approved', closed: 'Closed' };
const statusColor: Record<string, any> = { draft: 'warning', approved: 'success', closed: 'neutral' };

// Nilai tampilan per-kolom — dipakai untuk render, opsi filter, & pencocokan filter agar selalu konsisten.
const dNo = (r: Row) => r.po_number ?? '';
const dTgl = (r: Row) => fmtDate(r.po_date);
const dTempo = (r: Row) => fmtDate(r.due_date);
const dSupplier = (r: Row) => r.supplier?.name ?? '—';
const dProyek = (r: Row) => r.project ?? '—';
const dKet = (r: Row) => r.notes ?? '';
const dItem = (r: Row) => String(r.lines_count ?? 0);
const dTotal = (r: Row) => fmtRp(r.total);
const dStatus = (r: Row) => statusLabel[r.status] ?? r.status;
const dCetak = (r: Row) => fmtDateTime(r.printed_at);

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.orders) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optTgl = computed(() => distinct(dTgl));
const optTempo = computed(() => distinct(dTempo));
const optSupplier = computed(() => distinct(dSupplier));
const optProyek = computed(() => distinct(dProyek));
const optKet = computed(() => distinct(dKet));
const optItem = computed(() => distinct(dItem));
const optTotal = computed(() => distinct(dTotal));
const optStatus = computed(() => distinct(dStatus));
const optCetak = computed(() => distinct(dCetak));

// Filter global + per-kolom. Tiap kolom punya DUA cara: ketik bebas (txt) & checklist (col).
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], tgl: [], tempo: [], supplier: [], proyek: [], ket: [], item: [], total: [], status: [], cetak: [] });
const txt = reactive<Record<string, string>>({ no: '', tgl: '', tempo: '', supplier: '', proyek: '', ket: '', item: '', total: '', status: '', cetak: '' });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length) || Object.values(txt).some((v) => v.trim() !== ''));
function resetFilter() {
    search.value = '';
    (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = []));
    (Object.keys(txt) as Array<keyof typeof txt>).forEach((k) => (txt[k] = ''));
}
function okTxt(key: string, val: string) {
    const q = txt[key]?.trim().toLowerCase();
    return !q || val.toLowerCase().includes(q);
}
function okCol(key: string, val: string) { return !col[key].length || col[key].includes(val); }

const filtered = computed<Row[]>(() => props.orders.filter((r) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(r), dTgl(r), dTempo(r), dSupplier(r), dProyek(r), dKet(r), dItem(r), dTotal(r), dStatus(r), dCetak(r)].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    const pairs: Array<[string, string]> = [
        ['no', dNo(r)], ['tgl', dTgl(r)], ['tempo', dTempo(r)], ['supplier', dSupplier(r)], ['proyek', dProyek(r)],
        ['ket', dKet(r)], ['item', dItem(r)], ['total', dTotal(r)], ['status', dStatus(r)], ['cetak', dCetak(r)],
    ];
    return pairs.every(([k, v]) => okCol(k, v) && okTxt(k, v));
}));

// ── Pilih baris (checklist kiri) untuk dicetak sebagai Purchase Order ──
const checked = reactive<Record<number, boolean>>({});
const selectedRows = computed(() => filtered.value.filter((r) => checked[r.id]));
const selectedIds = computed(() => selectedRows.value.map((r) => r.id));
const allChecked = computed<boolean>({
    get: () => filtered.value.length > 0 && filtered.value.every((r) => checked[r.id]),
    set: (v: boolean) => filtered.value.forEach((r) => (checked[r.id] = v)),
});
function clearSelection() { Object.keys(checked).forEach((k) => delete checked[Number(k)]); }
function cetakPo() {
    if (!selectedIds.value.length) return;
    window.open(route('bs.po.print', { ids: selectedIds.value.join(',') }), '_blank');
}

// Ringkasan mengikuti hasil filter.
const summary = computed(() => {
    const rows = filtered.value;
    return {
        count_total: rows.length,
        total: rows.reduce((s, r) => s + Number(r.total ?? 0), 0),
        draft: rows.filter((r) => r.status === 'draft').length,
        approved: rows.filter((r) => r.status === 'approved').length,
        printed: rows.filter((r) => r.printed_at).length,
    };
});

// Bila data berubah, buang nilai filter yang sudah tak ada agar tabel tidak diam-diam kosong.
watch([optNo, optTgl, optTempo, optSupplier, optProyek, optKet, optItem, optTotal, optStatus, optCetak], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.tgl = prune(col.tgl, optTgl.value); col.tempo = prune(col.tempo, optTempo.value);
    col.supplier = prune(col.supplier, optSupplier.value); col.proyek = prune(col.proyek, optProyek.value); col.ket = prune(col.ket, optKet.value);
    col.item = prune(col.item, optItem.value); col.total = prune(col.total, optTotal.value); col.status = prune(col.status, optStatus.value);
    col.cetak = prune(col.cetak, optCetak.value);
});

// Export Excel mengikuti TAMPILAN saat ini (hasil filter).
function exportExcel() {
    const esc = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const num = (v: number) => Number(v ?? 0).toLocaleString('id-ID');
    const body = filtered.value.map((r) => '<tr>'
        + `<td>${esc(r.po_number)}</td><td>${esc(fmtDate(r.po_date))}</td><td>${esc(fmtDate(r.due_date))}</td>`
        + `<td>${esc(r.supplier?.name ?? '')}</td><td>${esc(r.project ?? '')}</td><td>${esc(r.notes ?? '')}</td>`
        + `<td>${r.lines_count}</td><td>${esc(num(r.subtotal))}</td><td>${esc(num(r.ppn_amount))}</td><td>${esc(num(r.total))}</td>`
        + `<td>${esc(statusLabel[r.status] ?? r.status)}</td><td>${esc(fmtDateTime(r.printed_at))}</td>`
        + '</tr>').join('');
    const html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
        + '<table border="1"><tr style="background:#c2410c;color:#fff">'
        + '<th>No. PO</th><th>Tanggal</th><th>Jatuh Tempo</th><th>Supplier</th><th>Proyek</th><th>Keterangan</th><th>Item</th><th>Subtotal</th><th>PPN</th><th>Total</th><th>Status</th><th>Waktu Dicetak</th>'
        + `</tr>${body}</table></body></html>`;
    const url = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `Purchase-Order-${new Date().toISOString().slice(0, 10)}.xls`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// ── Perlebar tampilan + scrollbar horizontal ATAS yang tersinkron (sama dengan Invoice Hutang) ──
const rootEl = ref<HTMLElement | null>(null);
const topScroll = ref<HTMLElement | null>(null);
const botScroll = ref<HTMLElement | null>(null);
const tableEl = ref<HTMLElement | null>(null);
const contentWidth = ref(0);
const overflow = ref(false);
let lock = false;
function syncTop() { if (lock || !botScroll.value || !topScroll.value) return; lock = true; botScroll.value.scrollLeft = topScroll.value.scrollLeft; lock = false; }
function syncBot() { if (lock || !botScroll.value || !topScroll.value) return; lock = true; topScroll.value.scrollLeft = botScroll.value.scrollLeft; lock = false; }
function applyWide() {
    const el = rootEl.value;
    const container = el?.parentElement;
    const main = el?.closest('main');
    if (!el || !container || !main) return;
    const wasted = Math.max(0, main.clientWidth - container.clientWidth);
    const m = wasted / 2;
    el.style.marginLeft = m ? `-${m}px` : '';
    el.style.marginRight = m ? `-${m}px` : '';
}
function measure() {
    const t = tableEl.value, b = botScroll.value;
    if (!t || !b) return;
    contentWidth.value = t.scrollWidth;
    overflow.value = t.scrollWidth > b.clientWidth + 1;
}
function recalc() { applyWide(); nextTick(measure); }
let roMain: ResizeObserver | null = null;
let roTable: ResizeObserver | null = null;
onMounted(() => {
    nextTick(recalc);
    const main = rootEl.value?.closest('main');
    if (main) { roMain = new ResizeObserver(recalc); roMain.observe(main); }
    if (tableEl.value) { roTable = new ResizeObserver(measure); roTable.observe(tableEl.value); }
    window.addEventListener('resize', recalc);
});
onBeforeUnmount(() => {
    roMain?.disconnect();
    roTable?.disconnect();
    window.removeEventListener('resize', recalc);
});
watch(filtered, () => nextTick(measure));
</script>

<template>
    <div ref="rootEl" class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ShoppingCart class="h-6 w-6 text-primary-600" /> Purchase Order <span class="text-base font-normal text-neutral-400">(Pesanan Pembelian)</span></h1>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('bs.po.report')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><ClipboardList class="h-4 w-4" /> Report PO</Link>
                <button type="button" @click="exportExcel" title="Unduh sesuai tampilan terfilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
                <button type="button" @click="cetakPo" :disabled="!selectedIds.length"
                    :title="selectedIds.length ? 'Cetak Purchase Order dari baris tercentang' : 'Centang dulu PO yang akan dicetak'"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40"
                    :class="selectedIds.length ? 'border-primary-400 bg-primary-50 text-primary-700 hover:bg-primary-100 dark:border-primary-700/50 dark:bg-primary-900/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-500 dark:border-neutral-700'">
                    <Printer class="h-4 w-4" /> Cetak PO<span v-if="selectedIds.length"> ({{ selectedIds.length }})</span>
                </button>
                <Link :href="route('bs.po.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Buat PO</Button></Link>
            </div>
        </div>

        <!-- Ringkasan mengikuti hasil filter (sama pola dengan Invoice Hutang). -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card border-l-4 border-primary-500 p-4">
                <div class="flex items-center gap-1 text-xs uppercase text-neutral-400"><CircleDollarSign class="h-3.5 w-3.5 text-primary-500" /> Nilai PO</div>
                <div class="mt-1 text-lg font-bold text-primary-600">{{ fmtRp(summary.total) }}</div>
            </div>
            <div class="card border-l-4 border-amber-500 p-4">
                <div class="text-xs uppercase text-neutral-400">Draft</div>
                <div class="mt-1 text-xl font-bold text-amber-600">{{ summary.draft }}</div>
            </div>
            <div class="card border-l-4 border-emerald-500 p-4">
                <div class="text-xs uppercase text-neutral-400">Approved</div>
                <div class="mt-1 text-xl font-bold text-emerald-600">{{ summary.approved }}</div>
            </div>
            <div class="card border-l-4 border-sky-500 p-4">
                <div class="flex items-center gap-1 text-xs uppercase text-neutral-400"><PackageCheck class="h-3.5 w-3.5 text-sky-500" /> Sudah Dicetak</div>
                <div class="mt-1 text-xl font-bold text-sky-600">{{ summary.printed }}</div>
            </div>
            <div class="card border-l-4 border-neutral-300 p-4 dark:border-neutral-600">
                <div class="text-xs uppercase text-neutral-400">Jumlah PO</div>
                <div class="mt-1 flex items-baseline gap-2 text-xl font-bold">{{ summary.count_total }}<span v-if="hasFilter" class="text-xs font-normal text-neutral-400">dari {{ props.orders.length }}</span></div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <div v-if="selectedRows.length" class="flex flex-wrap items-center gap-2 rounded-lg border border-primary-200 bg-primary-50/60 px-3 py-2 text-sm dark:border-primary-700/40 dark:bg-primary-900/15">
            <span class="font-medium">{{ selectedRows.length }} PO dipilih</span>
            <button type="button" @click="clearSelection" class="ml-auto text-xs text-neutral-500 underline hover:text-neutral-800 dark:hover:text-neutral-200">Bersihkan pilihan</button>
        </div>

        <!-- Scrollbar horizontal ATAS (tersinkron) — muncul hanya saat tabel lebih lebar dari layar. -->
        <div v-show="overflow" ref="topScroll" @scroll="syncTop" class="overflow-x-auto rounded-lg border border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-800/50">
            <div :style="{ width: contentWidth + 'px' }" class="h-2.5"></div>
        </div>

        <div ref="botScroll" @scroll="syncBot" class="card overflow-x-auto">
            <table ref="tableEl" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="w-10 px-3 pt-3 pb-1.5 text-center" title="Centang untuk memilih PO yang akan dicetak">
                            <input v-model="allChecked" type="checkbox" class="rounded border-neutral-300" @click.stop />
                        </th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. PO</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tanggal</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">Jatuh Tempo</th>
                        <th class="px-4 pt-3 pb-1.5">Supplier</th>
                        <th class="px-4 pt-3 pb-1.5">Proyek</th>
                        <th class="px-4 pt-3 pb-1.5">Keterangan</th>
                        <th class="px-4 pt-3 pb-1.5 text-center">Item</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Total</th>
                        <th class="px-4 pt-3 pb-1.5 text-center">Status</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">Waktu<br>Dicetak</th>
                    </tr>
                    <!-- Filter per-kolom: KETIK langsung di kotak, atau klik panah untuk pilih nilai. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-2 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" v-model:text="txt.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" v-model:text="txt.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tempo" v-model:text="txt.tempo" :options="optTempo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.supplier" v-model:text="txt.supplier" :options="optSupplier" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.proyek" v-model:text="txt.proyek" :options="optProyek" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.ket" v-model:text="txt.ket" :options="optKet" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.item" v-model:text="txt.item" :options="optItem" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.total" v-model:text="txt.total" :options="optTotal" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" v-model:text="txt.status" :options="optStatus" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cetak" v-model:text="txt.cetak" :options="optCetak" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="checked[r.id] ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''" @click="router.get(route('bs.po.show', r.id))">
                        <!-- .stop: mencentang tidak boleh ikut membuka rincian PO -->
                        <td class="px-3 py-2.5 text-center" @click.stop>
                            <input v-model="checked[r.id]" type="checkbox" class="rounded border-neutral-300" title="Pilih untuk dicetak" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold">{{ r.po_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.po_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.due_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[200px] truncate" :title="r.project ?? ''">{{ r.project ?? '—' }}</span></td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[220px] truncate" :title="r.notes ?? ''">{{ r.notes }}</span></td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="statusColor[r.status] ?? 'neutral'" size="sm">{{ statusLabel[r.status] ?? r.status }}</Badge></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-neutral-500">{{ fmtDateTime(r.printed_at) }}<span v-if="r.print_count" class="ml-1 text-xs text-neutral-400">({{ r.print_count }}×)</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.orders.length" title="Belum ada PO" description="Klik Buat PO untuk membuat pesanan pembelian." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada PO yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
