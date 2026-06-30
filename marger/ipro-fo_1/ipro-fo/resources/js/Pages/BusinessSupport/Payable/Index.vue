<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Plus, ArrowLeft, HandCoins, Search, CalendarClock, CalendarCheck, BookText, Tags, FileInput, ReceiptText, Eraser, FolderCheck, FileSpreadsheet, FilterX } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Row = {
    id: number; invoice_number: string; invoice_date: string | null; receive_date: string | null; due_date: string | null;
    supplier: { name: string } | null; customer: { name: string } | null; source_type: string | null; refund_source: string | null;
    description: string | null; berkas_notes: string | null;
    status: string; payable: number; sisa: number;
};
const props = defineProps<{ invoices: Row[] }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusLabel = (s: string) => (s === 'paid' ? 'Lunas' : 'Open');

// Tanggal hari ini (lokal, YYYY-MM-DD) untuk pemisahan jatuh tempo.
const todayStr = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();

// Nilai tampilan per-kolom — dipakai untuk render, opsi filter, & pencocokan filter agar selalu konsisten.
const dNo = (r: Row) => r.invoice_number ?? '';
const dTgl = (r: Row) => fmtDate(r.invoice_date);
const dTerima = (r: Row) => fmtDate(r.receive_date);
const dSupplier = (r: Row) => r.supplier?.name ?? r.customer?.name ?? '—';
// Sumber tagihan: PO / SPK / Refund (per jenis: SPR / SPRS / Transfer Anonim) / Manual.
const SUMBER_LABEL: Record<string, string> = { SPR: 'Refund: SPR', SPRS: 'Refund: SPRS', TRANSFER_ANONIM: 'Refund: Transfer Anonim' };
const dSumber = (r: Row) => {
    if (r.source_type === 'refund') return SUMBER_LABEL[r.refund_source ?? ''] ?? 'Refund';
    if (r.source_type === 'komisi') return 'Komisi';
    if (r.source_type === 'blt') return 'BLT';
    if (r.source_type === 'gmc') return 'GMC';
    if (r.source_type === 'po') return 'PO';
    if (r.source_type === 'spk') return 'SPK';
    return 'Manual';
};
const dKet = (r: Row) => r.description ?? '';
const dTotal = (r: Row) => fmtRp(r.payable);
const dSisa = (r: Row) => fmtRp(r.sisa);
const dStatus = (r: Row) => statusLabel(r.status);
const dBerkas = (r: Row) => r.berkas_notes || '—';

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.invoices) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optTgl = computed(() => distinct(dTgl));
const optTerima = computed(() => distinct(dTerima));
const optSupplier = computed(() => distinct(dSupplier));
const optSumber = computed(() => distinct(dSumber));
const optKet = computed(() => distinct(dKet));
const optTotal = computed(() => distinct(dTotal));
const optSisa = computed(() => distinct(dSisa));
const optStatus = computed(() => distinct(dStatus));
const optBerkas = computed(() => distinct(dBerkas));

// Filter global + checklist per-kolom (kosong = tampilkan semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], tgl: [], terima: [], supplier: [], sumber: [], ket: [], total: [], sisa: [], status: [], berkas: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

const filtered = computed<Row[]>(() => props.invoices.filter((r) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(r), dTgl(r), dTerima(r), dSupplier(r), dSumber(r), dKet(r), dTotal(r), dSisa(r), dStatus(r), dBerkas(r)].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.no.length && !col.no.includes(dNo(r))) return false;
    if (col.tgl.length && !col.tgl.includes(dTgl(r))) return false;
    if (col.terima.length && !col.terima.includes(dTerima(r))) return false;
    if (col.supplier.length && !col.supplier.includes(dSupplier(r))) return false;
    if (col.sumber.length && !col.sumber.includes(dSumber(r))) return false;
    if (col.ket.length && !col.ket.includes(dKet(r))) return false;
    if (col.total.length && !col.total.includes(dTotal(r))) return false;
    if (col.sisa.length && !col.sisa.includes(dSisa(r))) return false;
    if (col.status.length && !col.status.includes(dStatus(r))) return false;
    if (col.berkas.length && !col.berkas.includes(dBerkas(r))) return false;
    return true;
}));

// Ringkasan dari hasil filter (nett). Jatuh tempo dipisah relatif hari ini.
const summary = computed(() => {
    const open = filtered.value.filter((r) => r.status === 'open');
    let dueNow = 0, notDue = 0;
    for (const r of open) {
        // Tanpa due_date dianggap sudah jatuh tempo (perlu perhatian) agar dueNow+notDue == total open.
        if (!r.due_date || r.due_date <= todayStr) dueNow += Number(r.sisa); else notDue += Number(r.sisa);
    }
    return {
        open: open.reduce((s, r) => s + Number(r.sisa), 0),
        count_open: open.length,
        count_total: filtered.value.length,
        dueNow, notDue,
    };
});

// Bila data berubah (mis. reload), buang nilai filter yang sudah tak ada agar tak diam-diam mengosongkan tabel.
watch([optNo, optTgl, optTerima, optSupplier, optSumber, optKet, optTotal, optSisa, optStatus, optBerkas], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.tgl = prune(col.tgl, optTgl.value); col.terima = prune(col.terima, optTerima.value);
    col.supplier = prune(col.supplier, optSupplier.value); col.sumber = prune(col.sumber, optSumber.value); col.ket = prune(col.ket, optKet.value); col.total = prune(col.total, optTotal.value);
    col.sisa = prune(col.sisa, optSisa.value); col.status = prune(col.status, optStatus.value); col.berkas = prune(col.berkas, optBerkas.value);
});

// Export Excel mengikuti TAMPILAN saat ini (hasil filter + nilai nett).
function exportExcel() {
    const esc = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const num = (v: number) => Number(v ?? 0).toLocaleString('id-ID');
    const body = filtered.value.map((r) => '<tr>'
        + `<td>${esc(r.invoice_number)}</td>`
        + `<td>${esc(fmtDate(r.invoice_date))}</td>`
        + `<td>${esc(fmtDate(r.receive_date))}</td>`
        + `<td>${esc(fmtDate(r.due_date))}</td>`
        + `<td>${esc(r.supplier?.name ?? '')}</td>`
        + `<td>${esc(r.description)}</td>`
        + `<td>${esc(num(r.payable))}</td>`
        + `<td>${esc(num(r.sisa))}</td>`
        + `<td>${esc(statusLabel(r.status))}</td>`
        + `<td>${esc(r.berkas_notes ?? '')}</td>`
        + '</tr>').join('');
    const html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
        + '<table border="1"><tr style="background:#0f766e;color:#fff">'
        + '<th>No. Invoice</th><th>Tanggal</th><th>Tgl. Diterima</th><th>Jatuh Tempo</th><th>Supplier</th><th>Keterangan</th><th>Total (Net)</th><th>Sisa (Net)</th><th>Status</th><th>Kelengkapan Berkas</th>'
        + `</tr>${body}</table></body></html>`;
    const url = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `Invoice-Hutang-${new Date().toISOString().slice(0, 10)}.xls`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// ── Perlebar tampilan (full-bleed dari container terpusat) + scrollbar horizontal ATAS yang tersinkron ──
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
    const container = el?.parentElement;            // <div class="container mx-auto"> di AppLayout
    const main = el?.closest('main');
    if (!el || !container || !main) return;
    const wasted = Math.max(0, main.clientWidth - container.clientWidth); // ruang kosong kiri+kanan akibat container terpusat
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
// Lebar tabel berubah saat hasil filter berubah → ukur ulang.
watch(filtered, () => nextTick(measure));
</script>
<template>
    <div ref="rootEl" class="space-y-4">
        <Link :href="route('bs.ap.printhub')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Function — Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> Account Payable <span class="text-base font-normal text-neutral-400">(Hutang Usaha)</span></h1>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('bs.ap.categories.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Tags class="h-4 w-4" /> Kategori</Link>
                <Link :href="route('bs.ap.aging')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><CalendarClock class="h-4 w-4" /> Aging</Link>
                <Link :href="route('bs.ap.card')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Kartu</Link>
                <Link :href="route('bs.ap.posting.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileInput class="h-4 w-4" /> Posting</Link>
                <Link :href="route('bs.ap.penghapusan.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Eraser class="h-4 w-4" /> Hapus</Link>
                <Link :href="route('bs.ap.berkas.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FolderCheck class="h-4 w-4" /> Berkas</Link>
                <button type="button" @click="exportExcel" title="Unduh sesuai tampilan terfilter (nilai nett)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
                <Link :href="route('bs.ap.kuitansi.index')"><Button variant="secondary"><template #icon-left><ReceiptText class="h-4 w-4" /></template>Kuitansi</Button></Link>
                <Link :href="route('bs.ap.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Invoice</Button></Link>
            </div>
        </div>

        <!-- Ringkasan mengikuti hasil filter checklist (Total & Sisa = nett, setelah PPN & PPh). -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card border-l-4 border-danger-500 p-4">
                <div class="text-xs uppercase text-neutral-400">Sisa Hutang <span class="normal-case">(open · nett)</span></div>
                <div class="mt-1 text-lg font-bold text-danger-600">{{ fmtRp(summary.open) }}</div>
            </div>
            <div class="card border-l-4 border-amber-500 p-4">
                <div class="flex items-center gap-1 text-xs uppercase text-neutral-400"><CalendarClock class="h-3.5 w-3.5 text-amber-500" /> Jatuh Tempo Hari Ini</div>
                <div class="mt-1 text-lg font-bold text-amber-600">{{ fmtRp(summary.dueNow) }}</div>
                <div class="text-[10px] text-neutral-400">sudah lewat + jatuh tempo hari ini</div>
            </div>
            <div class="card border-l-4 border-emerald-500 p-4">
                <div class="flex items-center gap-1 text-xs uppercase text-neutral-400"><CalendarCheck class="h-3.5 w-3.5 text-emerald-500" /> Belum Jatuh Tempo</div>
                <div class="mt-1 text-lg font-bold text-emerald-600">{{ fmtRp(summary.notDue) }}</div>
                <div class="text-[10px] text-neutral-400">jatuh tempo setelah hari ini</div>
            </div>
            <div class="card border-l-4 border-neutral-300 p-4 dark:border-neutral-600">
                <div class="text-xs uppercase text-neutral-400">Tagihan Open</div>
                <div class="mt-1 text-xl font-bold">{{ summary.count_open }}</div>
            </div>
            <div class="card border-l-4 border-neutral-300 p-4 dark:border-neutral-600">
                <div class="text-xs uppercase text-neutral-400">Total Tagihan</div>
                <div class="mt-1 flex items-baseline gap-2 text-xl font-bold">{{ summary.count_total }}<span v-if="hasFilter" class="text-xs font-normal text-neutral-400">dari {{ props.invoices.length }}</span></div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <!-- Scrollbar horizontal ATAS (tersinkron) — muncul hanya saat tabel lebih lebar dari layar. -->
        <div v-show="overflow" ref="topScroll" @scroll="syncTop" class="overflow-x-auto rounded-lg border border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-800/50">
            <div :style="{ width: contentWidth + 'px' }" class="h-2.5"></div>
        </div>

        <div ref="botScroll" @scroll="syncBot" class="card overflow-x-auto">
            <table ref="tableEl" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. Invoice</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tanggal</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tgl. Diterima</th><th class="px-4 pt-3 pb-1.5">Supplier / Customer</th><th class="px-4 pt-3 pb-1.5">Sumber</th><th class="px-4 pt-3 pb-1.5">Keterangan</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Total</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Sisa</th><th class="px-4 pt-3 pb-1.5 text-center">Status</th><th class="px-4 pt-3 pb-1.5">Kelengkapan<br>Berkas</th>
                    </tr>
                    <!-- Filter checklist per-kolom (dropdown daftar nilai yang ada) -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.terima" :options="optTerima" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.supplier" :options="optSupplier" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.sumber" :options="optSumber" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.ket" :options="optKet" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.total" :options="optTotal" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.sisa" :options="optSisa" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" :options="optStatus" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.berkas" :options="optBerkas" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.ap.show', r.id))">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold">{{ r.invoice_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.receive_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.supplier?.name ?? r.customer?.name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5"><Badge v-if="r.source_type === 'refund'" variant="info" size="sm">{{ dSumber(r) }}</Badge><span v-else class="text-neutral-400">{{ dSumber(r) }}</span></td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[220px] truncate" :title="r.description ?? ''">{{ r.description }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(r.payable) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono" :class="r.sisa > 0 ? 'text-danger-600' : 'text-neutral-400'">{{ fmtRp(r.sisa) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'paid' ? 'success' : 'warning'" size="sm">{{ statusLabel(r.status) }}</Badge></td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[200px] truncate" :title="r.berkas_notes ?? ''">{{ r.berkas_notes || '—' }}</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.invoices.length" title="Belum ada tagihan hutang" description="Klik Invoice untuk mencatat tagihan supplier." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada tagihan yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
