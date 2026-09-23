<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart, FileSpreadsheet, FilterX, Search, BookText } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import PilihPosisiHutang from '@/Components/UI/PilihPosisiHutang.vue';

type Row = {
    id: number; memorial_number: string; memorial_date: string | null;
    invoice_id: number | null; invoice_number: string | null; invoice_description: string | null;
    supplier: string | null; amount: number; reason: string | null; counter_account: string | null;
    journal_id: number | null; journal_number: string | null;
};
const props = defineProps<{ rows: Row[]; total: number; posisi?: string; posisiOpsi?: Array<{ nilai: string; label: string }> }>();

// Penyaring posisi dikerjakan SERVER, bukan di klien seperti filter lain di layar ini:
// posisi sebuah penghapusan ada pada invoice yang dihapusnya, dan baris yang dikirim ke sini
// tidak membawa kategori invoice-nya. Karena props.rows sudah tersaring, computed filtered,
// angka Total, dan tombol Excel di bawah ikut benar tanpa perubahan apa pun.
const posisi = ref(props.posisi ?? 'semua');
function muatPosisi() {
    router.get(route('bs.ap.penghapusan.report'), { posisi: posisi.value }, { preserveState: true, preserveScroll: true, replace: true });
}

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Nilai tampilan per-kolom — dipakai untuk render, opsi filter & pencocokan filter agar konsisten.
const dInvoice = (r: Row) => r.invoice_number ?? '—';
const dKet = (r: Row) => r.invoice_description ?? '—';
const dSupplier = (r: Row) => r.supplier ?? '—';
const dNilai = (r: Row) => fmtRp(r.amount);

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.rows) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optInvoice = computed(() => distinct(dInvoice));
const optKet = computed(() => distinct(dKet));
const optSupplier = computed(() => distinct(dSupplier));
const optNilai = computed(() => distinct(dNilai));

// Filter: rentang tanggal + global search + checklist per-kolom (kosong = semua).
const dateFrom = ref('');
const dateTo = ref('');
const search = ref('');
const col = reactive<Record<string, string[]>>({ invoice: [], ket: [], supplier: [], nilai: [] });
const hasFilter = computed(() => Boolean(search.value || dateFrom.value || dateTo.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; dateFrom.value = ''; dateTo.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

const filtered = computed<Row[]>(() => props.rows.filter((r) => {
    const md = r.memorial_date ?? '';
    if (dateFrom.value && md < dateFrom.value) return false;
    if (dateTo.value && md > dateTo.value) return false;
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [r.memorial_number, fmtDate(r.memorial_date), dInvoice(r), dKet(r), dSupplier(r), r.reason, dNilai(r), r.journal_number].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.invoice.length && !col.invoice.includes(dInvoice(r))) return false;
    if (col.ket.length && !col.ket.includes(dKet(r))) return false;
    if (col.supplier.length && !col.supplier.includes(dSupplier(r))) return false;
    if (col.nilai.length && !col.nilai.includes(dNilai(r))) return false;
    return true;
}));
const total = computed(() => filtered.value.reduce((s, r) => s + Number(r.amount), 0));

function exportExcel() {
    const esc = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const num = (v: number) => Number(v ?? 0).toLocaleString('id-ID');
    const body = filtered.value.map((r) => '<tr>'
        + `<td>${esc(r.memorial_number)}</td>`
        + `<td>${esc(fmtDate(r.memorial_date))}</td>`
        + `<td>${esc(r.invoice_number)}</td>`
        + `<td>${esc(r.invoice_description)}</td>`
        + `<td>${esc(r.supplier)}</td>`
        + `<td>${esc(r.reason)}</td>`
        + `<td>${esc(num(r.amount))}</td>`
        + `<td>${esc(r.journal_number)}</td>`
        + '</tr>').join('');
    const html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
        + '<table border="1"><tr style="background:#b45309;color:#fff">'
        + '<th>No. Memo</th><th>Tanggal</th><th>No. Invoice</th><th>Keterangan Invoice</th><th>Supplier</th><th>Alasan Penghapusan</th><th>Nilai Dihapus</th><th>Jurnal GL</th>'
        + `</tr>${body}</table></body></html>`;
    const url = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `Laporan-Penghapusan-Hutang-${new Date().toISOString().slice(0, 10)}.xls`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.penghapusan.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Penghapusan Nilai Hutang</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-amber-600" /> Laporan Penghapusan Hutang</h1>
        <p class="text-sm text-neutral-500">Rincian &amp; history seluruh penghapusan nilai hutang, tertaut ke jurnal General Ledger.</p>

        <!-- Filter: rentang tanggal + pencarian + reset + export; ringkasan total mengikuti filter. -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label>
                <input v-model="dateFrom" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label>
                <input v-model="dateTo" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div class="relative min-w-[220px] flex-1">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <Search class="pointer-events-none absolute left-3 top-[34px] h-4 w-4 text-neutral-400" />
                <input v-model="search" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <PilihPosisiHutang v-model="posisi" :opsi="props.posisiOpsi" label="" @ubah="muatPosisi" />
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset</button>
            <button type="button" @click="exportExcel" title="Unduh sesuai tampilan terfilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
            <div class="ml-auto text-right">
                <div class="text-xs uppercase text-neutral-400">Total Dihapus</div>
                <div class="flex items-baseline justify-end gap-2 font-mono text-lg font-bold text-amber-600">{{ fmtRp(total) }}<span class="text-xs font-normal text-neutral-400">{{ filtered.length }} memo</span></div>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. Memo</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Tanggal</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">No. Invoice</th><th class="px-4 pt-3 pb-1.5">Keterangan Invoice</th><th class="px-4 pt-3 pb-1.5">Supplier</th><th class="px-4 pt-3 pb-1.5">Alasan Penghapusan</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5 text-right">Nilai Dihapus</th><th class="whitespace-nowrap px-4 pt-3 pb-1.5">Jurnal GL</th>
                    </tr>
                    <!-- Filter checklist per-kolom (No.Invoice, Keterangan, Supplier, Nilai) -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2 text-[10px] font-normal normal-case text-neutral-400">pakai rentang tanggal ↑</th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.invoice" :options="optInvoice" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.ket" :options="optKet" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.supplier" :options="optSupplier" /></th>
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.nilai" :options="optNilai" align="right" /></th>
                        <th class="px-3 pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">{{ r.memorial_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.memorial_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs">
                            <Link v-if="r.invoice_id" :href="route('bs.ap.show', r.invoice_id)" class="text-primary-600 hover:underline dark:text-primary-400">{{ r.invoice_number ?? '—' }}</Link>
                            <span v-else>{{ r.invoice_number ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[200px] truncate" :title="r.invoice_description ?? ''">{{ r.invoice_description ?? '—' }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ r.supplier ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-600 dark:text-neutral-300"><span class="block max-w-[260px] truncate" :title="r.reason ?? ''">{{ r.reason }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(r.amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">
                            <Link v-if="r.journal_id" :href="route('bs.gl.journal.show', r.journal_id)" class="inline-flex items-center gap-1 font-mono text-xs text-primary-600 hover:underline dark:text-primary-400"><BookText class="h-3.5 w-3.5" /> {{ r.journal_number ?? ('#' + r.journal_id) }}</Link>
                            <span v-else class="text-neutral-400">—</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="filtered.length" class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <td class="px-4 py-3" colspan="6">TOTAL <span class="ml-1 text-xs font-normal text-neutral-400">({{ filtered.length }} memo)</span></td>
                        <td class="px-4 py-3 text-right font-mono text-amber-600">{{ fmtRp(total) }}</td>
                        <td class="px-4 py-3"></td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!props.rows.length" title="Belum ada penghapusan" description="Hutang yang dihapus akan tercatat di sini beserta keterangan invoice, alasan & jurnal GL-nya." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada penghapusan yang cocok dengan filter. Klik Reset untuk menampilkan semua." />
        </div>
    </div>
</template>
