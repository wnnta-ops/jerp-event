<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Plus, ArrowLeft, HandCoins, Search, CalendarClock, CalendarCheck, BookText, Tags, FileInput, ReceiptText, Eraser, FileSpreadsheet, FilterX, Printer } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { useIzin } from '@/Composables/useIzin';

// Tombol di kepala halaman mengikuti hak akses: tiap tombol hanya muncul bila izin yang dijaga
// controller tujuannya dipegang — kalau tidak, orang mengeklik lalu menabrak 403.
const { punyaIzin } = useIzin();

type Row = {
    id: number; invoice_number: string; invoice_date: string | null; receive_date: string | null; due_date: string | null;
    supplier: { name: string } | null; customer: { name: string } | null; source_type: string | null; refund_source: string | null;
    po_id: number | null;   // PO asal (source_type='po') — dasar tombol Cetak Purchase Order
    description: string | null; berkas_notes: string | null; berkas_finance_date: string | null;
    status: string; payable: number; sisa: number;
    status_jurnal: string; journal_id: number | null;
    /** Memo Urgent yang ditarik ke invoice ini (status: Menunggu MW / BA, Disetujui, …). */
    memo: { number: string; status: string; url: string | null } | null;
};
type Kolom = 'no' | 'tgl' | 'terima' | 'supplier' | 'sumber' | 'ket' | 'total' | 'sisa' | 'status' | 'jurnal' | 'berkas' | 'berkasFinance';
const props = defineProps<{
    /** Hasil paginate() — 25 baris per halaman, sudah disaring server. */
    invoices: { data: Row[]; links?: any[]; from?: number | null; to?: number | null; total?: number };
    /** Ringkasan atas SELURUH hasil saringan (bukan hanya halaman ini). */
    summary: { open: number; dueNow: number; notDue: number; count_open: number; count_total: number };
    jumlahSemua: number;
    /** Isi dropdown per kolom (nilai tampilan), dari seluruh invoice jenis ini. */
    opsi: Record<Kolom, string[]>;
    batasOpsi?: number;
    filters: { search?: string; t?: Partial<Record<Kolom, string>>; c?: Partial<Record<Kolom, string[]>> };
    canBerkasFinance?: boolean;
    /** Modul yang sedang dibuka: Invoice Hutang / Reimburse Karyawan / Deposit Renovasi. */
    jenis: { kunci: string; judul: string; penerima: string; rute: string; izin: string };
}>();
const modulInvoice = props.jenis.kunci === 'invoice';

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusLabel = (s: string) => (s === 'paid' ? 'Lunas' : (s === 'batal' ? 'Batal' : 'Open'));

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
const dBerkasFinance = (r: Row) => fmtDate(r.berkas_finance_date);

// Tanggal berkas diterima Finance — hanya tampil sbg input bila user Dept Finance (server tetap
// penentu: ApInvoiceController::updateBerkasFinance menolak selain Finance/FinanceManager).
function saveBerkasFinance(r: Row, ev: Event) {
    const val = (ev.target as HTMLInputElement).value || null;
    router.post(route('bs.ap.berkas-finance', r.id), { berkas_finance_date: val }, { preserveScroll: true, preserveState: false });
}

const rows = computed<Row[]>(() => props.invoices.data ?? []);
const optNo = computed(() => props.opsi?.no ?? []);
const optTgl = computed(() => props.opsi?.tgl ?? []);
const optTerima = computed(() => props.opsi?.terima ?? []);
const optSupplier = computed(() => props.opsi?.supplier ?? []);
const optSumber = computed(() => props.opsi?.sumber ?? []);
const optKet = computed(() => props.opsi?.ket ?? []);
const optTotal = computed(() => props.opsi?.total ?? []);
const optSisa = computed(() => props.opsi?.sisa ?? []);
const optStatus = computed(() => props.opsi?.status ?? []);
const optJurnal = computed(() => props.opsi?.jurnal ?? []);
const optBerkas = computed(() => props.opsi?.berkas ?? []);
const optBerkasFinance = computed(() => props.opsi?.berkasFinance ?? []);

// Filter global + per-kolom — DIKERJAKAN SERVER (DaftarInvoiceHutang). Tiap kolom punya DUA cara:
//   • txt[...]  → KETIK bebas (cocok "berisi") langsung di kotak kolom;
//   • col[...]  → checklist nilai persis dari dropdown.
// Setiap perubahan dikirim ke server (jeda singkat saat mengetik) dan daftar kembali ke halaman 1.
const KOLOM: Kolom[] = ['no', 'tgl', 'terima', 'supplier', 'sumber', 'ket', 'total', 'sisa', 'status', 'jurnal', 'berkas', 'berkasFinance'];
// Warna status jurnal — sama dengan General Ledger.
const warnaJurnal: Record<string, string> = { Baru: 'warning', Final: 'info', Approved: 'success', Closed: 'neutral' };
const search = ref(props.filters?.search ?? '');
const col = reactive<Record<string, string[]>>(Object.fromEntries(KOLOM.map((k) => [k, [...(props.filters?.c?.[k] ?? [])]])));
const txt = reactive<Record<string, string>>(Object.fromEntries(KOLOM.map((k) => [k, props.filters?.t?.[k] ?? ''])));
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length) || Object.values(txt).some((v) => v.trim() !== ''));
function resetFilter() {
    search.value = '';
    KOLOM.forEach((k) => { col[k] = []; txt[k] = ''; });
}
function paramSaring(): Record<string, any> {
    const t: Record<string, string> = {}; const c: Record<string, string[]> = {};
    KOLOM.forEach((k) => { if (txt[k].trim()) t[k] = txt[k].trim(); if (col[k].length) c[k] = col[k]; });
    return { search: search.value || undefined, t: Object.keys(t).length ? t : undefined, c: Object.keys(c).length ? c : undefined };
}
let jeda: ReturnType<typeof setTimeout> | null = null;
watch([search, () => JSON.stringify(txt), () => JSON.stringify(col)], () => {
    if (jeda) clearTimeout(jeda);
    jeda = setTimeout(() => router.get(window.location.pathname, paramSaring(), { preserveState: true, preserveScroll: true, replace: true }), 450);
});

// ── Pilih baris (checklist kiri) untuk dicetak sebagai Purchase Order ──
// Hanya invoice yang benar-benar lahir dari PO (punya po_id) yang bisa dicetak sebagai PO.
const checked = reactive<Record<number, boolean>>({});
const selectedRows = computed(() => rows.value.filter((r) => checked[r.id]));
const selectedPoIds = computed(() => selectedRows.value.map((r) => r.po_id).filter((v): v is number => !!v));
const selectedNonPo = computed(() => selectedRows.value.length - selectedPoIds.value.length);
const allChecked = computed<boolean>({
    get: () => rows.value.length > 0 && rows.value.every((r) => checked[r.id]),
    set: (v: boolean) => rows.value.forEach((r) => (checked[r.id] = v)),
});
function clearSelection() { Object.keys(checked).forEach((k) => delete checked[Number(k)]); }
/** Buka halaman cetak PO (editable) untuk seluruh PO dari baris yang dicentang. */
function cetakPo() {
    if (!selectedPoIds.value.length) return;
    window.open(route('bs.po.print', { ids: selectedPoIds.value.join(',') }), '_blank');
}

// Ringkasan (nett) dihitung server atas SELURUH hasil saringan — props.summary.
const summary = computed(() => props.summary);

// Export Excel = SELURUH hasil saringan (server), bukan hanya 25 baris yang tampil.
function exportExcel() {
    const qs = new URLSearchParams();
    const p = paramSaring();
    if (p.search) qs.set('search', p.search);
    Object.entries(p.t ?? {}).forEach(([k, v]) => qs.set(`t[${k}]`, String(v)));
    Object.entries(p.c ?? {}).forEach(([k, arr]) => (arr as string[]).forEach((v) => qs.append(`c[${k}][]`, v)));
    qs.set('excel', '1');
    window.location.href = `${window.location.pathname}?${qs.toString()}`;
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
watch(rows, () => nextTick(measure));
</script>
<template>
    <div ref="rootEl" class="space-y-4">
        <Link :href="route('bs.ap.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> <template v-if="modulInvoice">Account Payable <span class="text-base font-normal text-neutral-400">(Hutang Usaha)</span></template><template v-else>{{ jenis.judul }}</template></h1>
            <div class="flex flex-wrap gap-2">
                <Link v-if="punyaIzin('ap.category.view')" :href="route('bs.ap.categories.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Tags class="h-4 w-4" /> Kategori</Link>
                <Link v-if="punyaIzin('ap.aging.view')" :href="route('bs.ap.aging')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><CalendarClock class="h-4 w-4" /> Aging</Link>
                <Link v-if="punyaIzin('ap.paycard.view')" :href="route('bs.ap.card')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Kartu</Link>
                <Link v-if="punyaIzin('ap.posting.view')" :href="route('bs.ap.posting.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileInput class="h-4 w-4" /> Posting</Link>
                <Link v-if="punyaIzin('ap.penghapusan.view')" :href="route('bs.ap.penghapusan.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Eraser class="h-4 w-4" /> Hapus</Link>
                <button type="button" @click="exportExcel" title="Unduh seluruh hasil filter (nilai nett), bukan hanya halaman ini" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
                <button v-if="punyaIzin('bs.po.view')" type="button" @click="cetakPo" :disabled="!selectedPoIds.length"
                    :title="selectedPoIds.length ? 'Cetak Purchase Order dari baris tercentang' : 'Centang dulu invoice yang berasal dari PO'"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40"
                    :class="selectedPoIds.length ? 'border-primary-400 bg-primary-50 text-primary-700 hover:bg-primary-100 dark:border-primary-700/50 dark:bg-primary-900/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-500 dark:border-neutral-700'">
                    <Printer class="h-4 w-4" /> Cetak PO<span v-if="selectedPoIds.length"> ({{ selectedPoIds.length }})</span>
                </button>
                <Link v-if="punyaIzin('ap.kuitansi.view')" :href="route('bs.ap.kuitansi.index')"><Button variant="secondary"><template #icon-left><ReceiptText class="h-4 w-4" /></template>Kuitansi</Button></Link>
                <Link v-if="punyaIzin(jenis.izin + '.create')" :href="route(modulInvoice ? 'bs.ap.create' : jenis.rute + '.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>{{ modulInvoice ? 'Invoice' : jenis.judul.split(' ')[0] }}</Button></Link>
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
                <div class="mt-1 flex items-baseline gap-2 text-xl font-bold">{{ summary.count_total }}<span v-if="hasFilter" class="text-xs font-normal text-neutral-400">dari {{ props.jumlahSemua }}</span></div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <!-- Info baris terpilih: berapa yang bisa dicetak sebagai PO, berapa yang bukan dari PO. -->
        <div v-if="selectedRows.length" class="flex flex-wrap items-center gap-2 rounded-lg border border-primary-200 bg-primary-50/60 px-3 py-2 text-sm dark:border-primary-700/40 dark:bg-primary-900/15">
            <span class="font-medium">{{ selectedRows.length }} baris dipilih</span>
            <span v-if="selectedPoIds.length" class="text-primary-700 dark:text-primary-300">· {{ selectedPoIds.length }} bisa dicetak sebagai PO</span>
            <span v-if="selectedNonPo" class="text-amber-700 dark:text-amber-400">· {{ selectedNonPo }} bukan dari PO (dilewati)</span>
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
                        <th class="w-10 px-3 pt-3 pb-1.5 text-center" title="Centang untuk memilih baris yang akan dicetak sebagai Purchase Order">
                            <input v-model="allChecked" type="checkbox" class="rounded border-neutral-300" @click.stop />
                        </th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. Invoice</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tanggal</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tgl. Diterima</th><th class="px-4 pt-3 pb-1.5">{{ modulInvoice ? 'Supplier / Customer' : jenis.penerima }}</th><th class="px-4 pt-3 pb-1.5">Sumber</th><th class="px-4 pt-3 pb-1.5">Keterangan</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Total</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Sisa</th><th class="px-4 pt-3 pb-1.5 text-center">Status</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-center">Status<br>Jurnal</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Status<br>Memo</th><th class="px-4 pt-3 pb-1.5">Kelengkapan<br>Berkas</th>
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5" title="Tanggal berkas fisik diterima Finance — berbeda dari Tgl. Diterima (tagihan supplier masuk). Hanya Dept Finance yang boleh mengisi.">Berkas Diterima<br>Finance</th>
                    </tr>
                    <!-- Filter per-kolom: KETIK langsung di kotak (cocok "berisi"), atau klik panah
                         untuk memilih nilai persis dari daftar. Keduanya bisa dipakai bersamaan. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-2 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" v-model:text="txt.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" v-model:text="txt.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.terima" v-model:text="txt.terima" :options="optTerima" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.supplier" v-model:text="txt.supplier" :options="optSupplier" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.sumber" v-model:text="txt.sumber" :options="optSumber" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.ket" v-model:text="txt.ket" :options="optKet" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.total" v-model:text="txt.total" :options="optTotal" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.sisa" v-model:text="txt.sisa" :options="optSisa" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" v-model:text="txt.status" :options="optStatus" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.jurnal" v-model:text="txt.jurnal" :options="optJurnal" /></th>
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.berkas" v-model:text="txt.berkas" :options="optBerkas" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.berkasFinance" v-model:text="txt.berkasFinance" :options="optBerkasFinance" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="checked[r.id] ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''" @click="router.get(route('bs.ap.show', r.id))">
                        <!-- .stop: mencentang tidak boleh ikut membuka rincian invoice -->
                        <td class="px-3 py-2.5 text-center" @click.stop>
                            <input v-model="checked[r.id]" type="checkbox" class="rounded border-neutral-300"
                                :title="r.po_id ? 'Pilih untuk dicetak sebagai Purchase Order' : 'Invoice ini bukan dari PO — tidak bisa dicetak sebagai Purchase Order'" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold">{{ r.invoice_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.receive_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.supplier?.name ?? r.customer?.name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5"><Badge v-if="r.source_type === 'refund'" variant="info" size="sm">{{ dSumber(r) }}</Badge><span v-else class="text-neutral-400">{{ dSumber(r) }}</span></td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[220px] truncate" :title="r.description ?? ''">{{ r.description }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(r.payable) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono" :class="r.sisa > 0 ? 'text-danger-600' : 'text-neutral-400'">{{ fmtRp(r.sisa) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'paid' ? 'success' : (r.status === 'batal' ? 'danger' : 'warning')" size="sm">{{ statusLabel(r.status) }}</Badge></td>
                        <!-- Status jurnal (Baru / Final / Approved / Closed) — klik membuka jurnalnya. -->
                        <td class="px-4 py-2.5 text-center" @click.stop>
                            <Link v-if="r.journal_id && r.status_jurnal !== '—' && punyaIzin('gl.journal.view')" :href="route('bs.gl.journal.show', r.journal_id)" :title="'Buka jurnal invoice ' + r.invoice_number"><Badge :variant="(warnaJurnal[r.status_jurnal] ?? 'neutral') as any" size="sm">{{ r.status_jurnal }}</Badge></Link>
                            <Badge v-else-if="r.status_jurnal && r.status_jurnal !== '—'" :variant="(warnaJurnal[r.status_jurnal] ?? 'neutral') as any" size="sm">{{ r.status_jurnal }}</Badge>
                            <span v-else class="text-neutral-400">—</span>
                        </td>
                        <!-- Memo Urgent yang ditarik — klik membuka memonya di Approval Inbox. -->
                        <td class="whitespace-nowrap px-4 py-2.5" @click.stop>
                            <template v-if="r.memo">
                                <component :is="r.memo.url ? Link : 'span'" :href="r.memo.url ?? undefined" class="block font-mono text-[11px] text-primary-700 hover:underline dark:text-primary-300">{{ r.memo.number }}</component>
                                <Badge :variant="r.memo.status === 'Disetujui' ? 'success' : (r.memo.status === 'Ditolak' ? 'danger' : 'warning')" size="sm">{{ r.memo.status }}</Badge>
                            </template>
                            <span v-else class="text-neutral-400">—</span>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[200px] truncate" :title="r.berkas_notes ?? ''">{{ r.berkas_notes || '—' }}</span></td>
                        <!-- .stop: mengisi tanggal tidak boleh ikut membuka rincian invoice -->
                        <td class="whitespace-nowrap px-4 py-2.5" @click.stop>
                            <input v-if="canBerkasFinance" :value="r.berkas_finance_date ?? ''" @change="saveBerkasFinance(r, $event)" type="date"
                                class="h-8 rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900"
                                title="Tanggal berkas fisik diterima Finance" />
                            <span v-else :title="r.berkas_finance_date ? '' : 'Hanya Dept Finance yang boleh mengisi tanggal ini.'">{{ dBerkasFinance(r) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.jumlahSemua" title="Belum ada tagihan hutang" description="Klik Invoice untuk mencatat tagihan supplier." />
            <EmptyState v-else-if="!rows.length" title="Tidak ada hasil" description="Tidak ada tagihan yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
        <Pagination :data="invoices" satuan="tagihan" />
    </div>
</template>
