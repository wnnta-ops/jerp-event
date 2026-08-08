<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, Search, FilterX, ArrowRightLeft } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import SortableTh from '@/Components/UI/SortableTh.vue';
import { useTableColumns } from '@/Composables/useTableColumns';

type Row = {
    row_key: string; source_type: string; source_label: string; source_id: number;
    booking_id: number | null; customer_id: number | null; customer: string | null;
    spr: string | null; unit: string | null; doc_date: string | null;
    journal_number: string | null; kpr_number: string | null; note: string | null;
    dpp: number; ppn: number; ppn_pasti: boolean; total: number;
};
const props = defineProps<{
    rows: Row[];
    summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Filter & urut per kolom, seluruhnya di sisi klien — server sudah mengirim semua baris.
const semua = computed(() => props.rows ?? []);
const T = useTableColumns<Row>(semua, [
    { key: 'sumber',   value: (r) => r.source_label ?? '' },
    { key: 'tgl',      value: (r) => fmtDate(r.doc_date), sort: (r) => r.doc_date ?? '' },
    { key: 'customer', value: (r) => r.customer ?? '—' },
    { key: 'spr',      value: (r) => r.spr ?? '—' },
    { key: 'unit',     value: (r) => r.unit ?? '—' },
    { key: 'ket',      value: (r) => r.note ?? '—' },
    { key: 'jurnal',   value: (r) => r.journal_number ?? '—' },
    { key: 'dpp',      value: (r) => fmtRp(r.dpp),   sort: (r) => Number(r.dpp) || 0 },
    { key: 'ppn',      value: (r) => fmtRp(r.ppn),   sort: (r) => Number(r.ppn) || 0 },
    { key: 'total',    value: (r) => fmtRp(r.total), sort: (r) => Number(r.total) || 0 },
]);
const rows = T.result;

// ── Pilih massal untuk kompensasi ──
// Kunci baris memakai row_key (mis. "retensi-12"), BUKAN id: ketiga sumber punya id sendiri-sendiri
// sehingga id 12 bisa muncul di lebih dari satu sumber.
const selected = ref<string[]>([]);
// Hanya baris yang SEDANG TAMPIL yang ikut "pilih semua" — kalau memakai seluruh data,
// mencentang kotak kepala akan diam-diam ikut memilih baris yang tersaring keluar.
const pageKeys = computed<string[]>(() => rows.value.map((r) => r.row_key));
const allChecked = computed<boolean>({
    get: () => pageKeys.value.length > 0 && pageKeys.value.every((k) => selected.value.includes(k)),
    set: (v: boolean) => { selected.value = v ? [...pageKeys.value] : []; },
});
const selectedTotal = computed(() => semua.value.filter((r) => selected.value.includes(r.row_key)).reduce((s, r) => s + Number(r.total || 0), 0));
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';

const submitting = ref(false);
function kompensasi() {
    if (!selected.value.length || submitting.value) return;
    submitting.value = true;
    router.post(route('bs.ar.um.compensate'), { keys: selected.value }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
        onFinish: () => { submitting.value = false; },
    });
}

const sourceVariant: Record<string, string> = { retensi: 'warning', pencairan: 'info', utj_dp: 'success' };
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Invoice Piutang</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-primary-600" /> UM Penjualan</h1>
            <p class="text-sm text-neutral-500">
                Uang muka penjualan yang <strong>sudah disetujui &amp; diposting</strong>, berasal dari
                <strong>Approval Retensi</strong> (DAJAM &amp; Pencairan KPR) dan <strong>Approval UM Penjualan</strong> (UTJ/DP).
                Centang barisnya lalu klik <strong>Kompensasi dengan AR</strong> — baris akan berpindah ke
                <strong>Rekonsiliasi Piutang</strong> sebagai pengurang tagihan customer.
            </p>
            <p class="text-sm text-neutral-500">Kompensasi di sini <strong>tidak memposting jurnal</strong>; jurnal kompensasi tetap dibuat terpisah oleh Accounting.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Belum Dikompensasi</p><p class="mt-1 text-2xl font-bold">{{ summary.count }}<span class="ml-1 text-sm font-normal text-neutral-400">baris</span></p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Nilai UM</p><p class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(summary.total) }}</p></div>
        </div>

        <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> baris dipilih · <b class="tabular-nums">{{ fmtRp(selectedTotal) }}</b></span>
            <div class="ml-auto flex flex-wrap gap-2">
                <button type="button" @click="kompensasi" :disabled="submitting"
                        title="Tandai baris terpilih sebagai dikompensasi dengan piutang — baris pindah ke Rekonsiliasi Piutang."
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                    <ArrowRightLeft class="h-4 w-4" /> {{ submitting ? 'Memproses…' : `Kompensasi dengan AR (${selected.length})` }}
                </button>
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
                        <th class="px-4 pb-1 pt-3"><SortableTh label="Sumber" :state="T.sortState('sumber')" @sort="T.toggleSort('sumber')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Tanggal" :state="T.sortState('tgl')" @sort="T.toggleSort('tgl')" /></th>
                        <th class="px-4 pb-1 pt-3"><SortableTh label="Customer" :state="T.sortState('customer')" @sort="T.toggleSort('customer')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. SPR" :state="T.sortState('spr')" @sort="T.toggleSort('spr')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Unit" :state="T.sortState('unit')" @sort="T.toggleSort('unit')" /></th>
                        <th class="px-4 pb-1 pt-3"><SortableTh label="Keterangan" :state="T.sortState('ket')" @sort="T.toggleSort('ket')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. Jurnal" :state="T.sortState('jurnal')" @sort="T.toggleSort('jurnal')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="DPP" align="right" :state="T.sortState('dpp')" @sort="T.toggleSort('dpp')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="PPN" align="right" :state="T.sortState('ppn')" @sort="T.toggleSort('ppn')" /></th>
                        <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Total UM" align="right" :state="T.sortState('total')" @sort="T.toggleSort('total')" /></th>
                    </tr>
                    <!-- Baris 2: filter per kolom — ketik untuk mencari, panah untuk memilih dari daftar. -->
                    <tr>
                        <th class="px-3 pb-2"></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.sumber" v-model:text="T.txt.sumber" :options="T.options.value.sumber" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.tgl" v-model:text="T.txt.tgl" :options="T.options.value.tgl" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.customer" v-model:text="T.txt.customer" :options="T.options.value.customer" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.spr" v-model:text="T.txt.spr" :options="T.options.value.spr" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.unit" v-model:text="T.txt.unit" :options="T.options.value.unit" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.ket" v-model:text="T.txt.ket" :options="T.options.value.ket" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.jurnal" v-model:text="T.txt.jurnal" :options="T.options.value.jurnal" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.dpp" v-model:text="T.txt.dpp" :options="T.options.value.dpp" align="right" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.ppn" v-model:text="T.txt.ppn" :options="T.options.value.ppn" align="right" /></th>
                        <th class="px-2 pb-2"><ColumnFilter v-model="T.col.total" v-model:text="T.txt.total" :options="T.options.value.total" align="right" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.row_key" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="selected.includes(r.row_key) ? 'bg-primary-500/10' : ''">
                        <td class="px-3 py-2.5"><input v-model="selected" type="checkbox" :value="r.row_key" :class="cbCls" title="Pilih untuk dikompensasi dengan AR" /></td>
                        <td class="px-4 py-2.5"><Badge :variant="(sourceVariant[r.source_type] as any) ?? 'neutral'" size="sm">{{ r.source_label }}</Badge></td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.doc_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.spr ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">
                            <span class="block max-w-[260px] truncate" :title="r.note ?? ''">{{ r.note ?? '—' }}</span>
                            <span v-if="r.kpr_number" class="block text-xs text-neutral-400">KPR {{ r.kpr_number }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.journal_number ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.dpp) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">
                            {{ fmtRp(r.ppn) }}
                            <span v-if="!r.ppn_pasti" class="ml-0.5 cursor-help text-neutral-400" title="Perkiraan PPN inklusif 11% — sumbernya (retensi/pencairan) tidak menyimpan pecahan DPP/PPN tersendiri.">≈</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <p v-if="rows.length" class="text-xs text-neutral-500">Menampilkan {{ rows.length }} dari {{ semua.length }} baris<span v-if="T.hasFilter.value"> (tersaring)</span>.</p>

        <EmptyState v-else-if="T.hasFilter.value" title="Tidak ada hasil" description="Tidak ada UM Penjualan yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        <EmptyState v-else title="Tidak ada UM Penjualan menunggu kompensasi"
            description="Semua uang muka yang sudah diposting telah dikompensasi — lihat hasilnya di Rekonsiliasi Piutang.">
            <template #icon><Wallet class="h-12 w-12" /></template>
        </EmptyState>
    </div>
</template>
