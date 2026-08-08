<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, Search, FilterX, Printer, ChevronDown, ChevronRight, Undo2, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import SortableTh from '@/Components/UI/SortableTh.vue';
import { useTableColumns } from '@/Composables/useTableColumns';

type Inv = { id: number; customer: string | null; number: string; date: string | null; description: string | null; total: number; paid: number; outstanding: number };
type Um = { id: number; customer: string | null; source_type: string; source_label: string; date: string | null; note: string | null; amount: number };
type Row = {
    row_key: string; booking_id: number | null; spr: string | null; unit: string | null; customer: string | null;
    customers: string[]; multi_customer: boolean;
    invoice_total: number; invoice_count: number; um_total: number; um_count: number;
    sisa: number; invoices: Inv[]; ums: Um[];
};
const props = defineProps<{
    rows: Row[];
    summary: { count: number; invoice: number; um: number; sisa: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Filter & urut per kolom, seluruhnya di sisi klien — server sudah mengirim semua baris.
const semua = computed(() => props.rows ?? []);
const T = useTableColumns<Row>(semua, [
    { key: 'spr',      value: (r) => r.spr ?? 'Tanpa SPR' },
    { key: 'unit',     value: (r) => r.unit ?? '—' },
    // Memakai SELURUH nama pada grup, bukan nama utamanya saja — kalau tidak, customer
    // kedua pada satu SPR tidak akan pernah ketemu walaupun namanya diketik persis.
    { key: 'customer', value: (r) => (r.customers?.length ? r.customers.join(' · ') : (r.customer ?? '—')) },
    { key: 'invoice',  value: (r) => fmtRp(r.invoice_total), sort: (r) => Number(r.invoice_total) || 0 },
    { key: 'um',       value: (r) => fmtRp(r.um_total),      sort: (r) => Number(r.um_total) || 0 },
    { key: 'sisa',     value: (r) => fmtRp(r.sisa),          sort: (r) => Number(r.sisa) || 0 },
]);
const rows = T.result;

// ── Pilih massal untuk penagihan ──
const selected = ref<string[]>([]);
// Hanya baris yang SEDANG TAMPIL — kalau memakai seluruh data, mencentang kotak kepala
// akan diam-diam ikut memilih baris yang tersaring keluar lalu ikut tercetak.
const pageKeys = computed<string[]>(() => rows.value.map((r) => r.row_key));
const allChecked = computed<boolean>({
    get: () => pageKeys.value.length > 0 && pageKeys.value.every((k) => selected.value.includes(k)),
    set: (v: boolean) => { selected.value = v ? [...pageKeys.value] : []; },
});
const selectedSisa = computed(() => semua.value.filter((r) => selected.value.includes(r.row_key)).reduce((s, r) => s + Number(r.sisa || 0), 0));

// Daftar berubah (mis. sesudah membatalkan kompensasi, grup bisa lenyap karena isinya habis)
// -> buang centang yang barisnya sudah tidak ada. MEMANGKAS, bukan mengosongkan: centang lain
// yang masih sah harus tetap bertahan. Tanpa ini, "Cetak Surat Tagihan" mengirim key hantu
// dan berakhir 404.
watch(() => props.rows, (rows) => {
    if (! selected.value.length) return;
    const ada = new Set(rows.map((r) => r.row_key));
    const tersisa = selected.value.filter((k) => ada.has(k));
    if (tersisa.length !== selected.value.length) selected.value = tersisa;
});
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';

// Rincian per SPR dibuka sesuai kebutuhan agar tabel induk tetap terbaca.
const open = ref<string[]>([]);
function toggle(key: string) {
    open.value = open.value.includes(key) ? open.value.filter((k) => k !== key) : [...open.value, key];
}

// Batalkan kompensasi satu baris UM — mengembalikannya ke antrean UM Penjualan.
// Tidak menyentuh jurnal (kompensasi memang tidak memposting jurnal).
const membatalkan = ref(false);
function batalKompensasi(u: Um) {
    if (membatalkan.value) return;
    if (! window.confirm(`Batalkan kompensasi ${u.source_label} sebesar ${fmtRp(u.amount)}?\n\nBaris ini akan kembali ke antrean UM Penjualan.`)) return;
    membatalkan.value = true;
    router.post(route('bs.ar.um.undo'), { ids: [u.id] }, {
        preserveScroll: true,
        onFinish: () => { membatalkan.value = false; },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.ar.um.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> UM Penjualan</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Rekonsiliasi Piutang</h1>
            <p class="text-sm text-neutral-500">
                Tagihan piutang disandingkan dengan UM Penjualan yang sudah dikompensasi, <strong>dikelompokkan per SPR</strong>.
                <strong>Sisa Piutang = Total Invoice Piutang − Total UM Penjualan</strong>. Centang barisnya lalu klik
                <strong>Cetak Surat Tagihan</strong> untuk menagih ke customer.
            </p>
            <p class="text-sm text-neutral-500">Nilai invoice yang dipakai adalah <strong>sisa berjalan</strong> (sudah dikurangi pembayaran yang masuk), bukan nilai penuh invoice.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Kelompok SPR</p><p class="mt-1 text-2xl font-bold">{{ summary.count }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Invoice Piutang</p><p class="mt-1 text-xl font-bold">{{ fmtRp(summary.invoice) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total UM Dikompensasi</p><p class="mt-1 text-xl font-bold text-warning-600">{{ fmtRp(summary.um) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Sisa Piutang</p><p class="mt-1 text-xl font-bold text-primary-600">{{ fmtRp(summary.sisa) }}</p></div>
        </div>

        <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> SPR dipilih · sisa <b class="tabular-nums">{{ fmtRp(selectedSisa) }}</b></span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a :href="route('bs.ar.rekonsiliasi.tagihan', { keys: selected.join(',') })" target="_blank" rel="noopener"
                   title="Cetak surat tagihan untuk SPR terpilih — 1 halaman per SPR."
                   class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak Surat Tagihan ({{ selected.length }})</a>
                <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
            </div>
        </div>

        <div class="card flex flex-wrap items-center gap-3 p-4">
            <div class="relative min-w-[220px] flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="T.search.value" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button v-if="T.hasFilter.value" type="button" @click="T.reset()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <TableTopScroll v-if="rows.length">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <!-- Baris 1: label kolom — diklik untuk mengurutkan. -->
                    <tr class="text-left">
                        <th class="w-10 px-3 pb-1 pt-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :disabled="!pageKeys.length" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua baris yang tampil'" /></th>
                        <th class="w-8 px-2 pb-1 pt-3"></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. SPR" :state="T.sortState('spr')" @sort="T.toggleSort('spr')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Unit" :state="T.sortState('unit')" @sort="T.toggleSort('unit')" /></th>
                        <th class="px-4 pb-1 pt-3"><SortableTh label="Customer" :state="T.sortState('customer')" @sort="T.toggleSort('customer')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Invoice Piutang" align="right" :state="T.sortState('invoice')" @sort="T.toggleSort('invoice')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="UM Penjualan" align="right" :state="T.sortState('um')" @sort="T.toggleSort('um')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Sisa Piutang" align="right" :state="T.sortState('sisa')" @sort="T.toggleSort('sisa')" /></th>
                    </tr>
                    <!-- Baris 2: filter per kolom — ketik untuk mencari, panah untuk memilih dari daftar. -->
                    <tr>
                        <th class="px-3 pb-2"></th>
                        <th class="px-2 pb-2"></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.spr" v-model:text="T.txt.spr" :options="T.options.value.spr" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.unit" v-model:text="T.txt.unit" :options="T.options.value.unit" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.customer" v-model:text="T.txt.customer" :options="T.options.value.customer" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.invoice" v-model:text="T.txt.invoice" :options="T.options.value.invoice" align="right" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.um" v-model:text="T.txt.um" :options="T.options.value.um" align="right" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.sisa" v-model:text="T.txt.sisa" :options="T.options.value.sisa" align="right" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="r in rows" :key="r.row_key">
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="selected.includes(r.row_key) ? 'bg-primary-500/10' : ''">
                            <td class="px-3 py-2.5"><input v-model="selected" type="checkbox" :value="r.row_key" :class="cbCls" title="Pilih untuk cetak surat tagihan" /></td>
                            <td class="px-2 py-2.5">
                                <button type="button" @click="toggle(r.row_key)" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" :title="open.includes(r.row_key) ? 'Sembunyikan rincian' : 'Lihat rincian'">
                                    <component :is="open.includes(r.row_key) ? ChevronDown : ChevronRight" class="h-4 w-4" />
                                </button>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">
                                {{ r.spr ?? '—' }}
                                <Badge v-if="!r.booking_id" variant="neutral" size="sm" class="ml-1">Tanpa SPR</Badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">{{ r.unit ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <div>{{ r.customer ?? '—' }}</div>
                                <!-- Satu SPR bisa memuat baris atas nama customer lain (mis. pengalihan
                                     pembeli). Namanya WAJIB ikut tampil, kalau tidak angkanya terhitung
                                     tapi orangnya tidak pernah ketemu saat dicari. -->
                                <div v-if="r.multi_customer" class="mt-0.5 flex flex-wrap items-center gap-1">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-px text-[10px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-800/60"
                                        title="SPR ini memuat baris atas nama lebih dari satu customer">
                                        <TriangleAlert class="h-2.5 w-2.5" /> {{ r.customers.length }} nama
                                    </span>
                                    <span v-for="n in r.customers.filter((x) => x !== r.customer)" :key="n" class="text-[11px] text-neutral-500">{{ n }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">
                                {{ fmtRp(r.invoice_total) }}
                                <span class="block text-xs text-neutral-400">{{ r.invoice_count }} invoice</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-warning-600">
                                {{ fmtRp(r.um_total) }}
                                <span class="block text-xs text-neutral-400">{{ r.um_count }} UM</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-semibold" :class="r.sisa > 0 ? 'text-primary-600' : 'text-success-600'">{{ fmtRp(r.sisa) }}</td>
                        </tr>
                        <tr v-if="open.includes(r.row_key)" class="bg-neutral-50/70 dark:bg-neutral-900/40">
                            <td colspan="8" class="px-6 py-3">
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <p class="mb-1 text-xs font-semibold uppercase text-neutral-500">Invoice Piutang</p>
                                        <table class="w-full text-xs">
                                            <tbody>
                                                <tr v-for="i in r.invoices" :key="'i' + i.id" class="border-b border-neutral-200 last:border-0 dark:border-neutral-800">
                                                    <td class="py-1 pr-2 font-mono">{{ i.number }}</td>
                                                    <!-- Nama per baris: menjawab "punya siapa" saat satu SPR memuat beberapa nama. -->
                                                    <td class="py-1 pr-2" :class="i.customer && i.customer !== r.customer ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-neutral-500'">{{ i.customer ?? '—' }}</td>
                                                    <td class="py-1 pr-2 text-neutral-500">{{ fmtDate(i.date) }}</td>
                                                    <td class="py-1 pr-2 text-neutral-500"><span class="block max-w-[220px] truncate" :title="i.description ?? ''">{{ i.description ?? '—' }}</span></td>
                                                    <td class="py-1 text-right font-mono">{{ fmtRp(i.outstanding) }}</td>
                                                </tr>
                                                <tr v-if="!r.invoices.length"><td colspan="5" class="py-1 text-neutral-400">Tidak ada invoice piutang</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div>
                                        <p class="mb-1 text-xs font-semibold uppercase text-neutral-500">UM Penjualan Dikompensasi</p>
                                        <table class="w-full text-xs">
                                            <tbody>
                                                <tr v-for="u in r.ums" :key="'u' + u.id" class="border-b border-neutral-200 last:border-0 dark:border-neutral-800">
                                                    <td class="py-1 pr-2">{{ u.source_label }}</td>
                                                    <!-- Nama per baris: menjawab "punya siapa" saat satu SPR memuat beberapa nama. -->
                                                    <td class="py-1 pr-2" :class="u.customer && u.customer !== r.customer ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-neutral-500'">{{ u.customer ?? '—' }}</td>
                                                    <td class="py-1 pr-2 text-neutral-500">{{ fmtDate(u.date) }}</td>
                                                    <td class="py-1 pr-2 text-neutral-500"><span class="block max-w-[200px] truncate" :title="u.note ?? ''">{{ u.note ?? '—' }}</span></td>
                                                    <td class="py-1 text-right font-mono text-warning-600">{{ fmtRp(u.amount) }}</td>
                                                    <td class="py-1 pl-2 text-right">
                                                        <button type="button" @click="batalKompensasi(u)" :disabled="membatalkan"
                                                                title="Batalkan kompensasi — baris kembali ke antrean UM Penjualan"
                                                                class="text-neutral-400 hover:text-danger-600 disabled:opacity-40">
                                                            <Undo2 class="h-3.5 w-3.5" />
                                                        </button>
                                                    </td>
                                                </tr>
                                                <tr v-if="!r.ums.length"><td colspan="4" class="py-1 text-neutral-400">Belum ada UM dikompensasi</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </TableTopScroll>

        <p v-if="rows.length" class="text-xs text-neutral-500">Menampilkan {{ rows.length }} dari {{ semua.length }} kelompok SPR<span v-if="T.hasFilter.value"> (tersaring)</span>.</p>

        <EmptyState v-else-if="T.hasFilter.value" title="Tidak ada hasil" description="Tidak ada SPR yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        <EmptyState v-else title="Belum ada yang bisa direkonsiliasi"
            description="Belum ada invoice piutang maupun UM Penjualan yang dikompensasi. Kompensasikan UM dulu di layar UM Penjualan.">
            <template #icon><Scale class="h-12 w-12" /></template>
        </EmptyState>
    </div>
</template>
