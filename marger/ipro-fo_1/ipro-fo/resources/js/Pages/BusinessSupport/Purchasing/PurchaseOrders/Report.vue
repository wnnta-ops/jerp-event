<script setup lang="ts">
/**
 * Report PO — jumlah PO per supplier beserta rincian nomor PO-nya, termasuk
 * WAKTU PEMBUATAN (created_at) dan WAKTU CETAK (printed_at) tiap PO.
 * Tiap supplier bisa dibuka-tutup; pencarian & rentang tanggal tersedia.
 */
import { ref, reactive, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ClipboardList, Search, ChevronDown, FileSpreadsheet, Printer, FilterX } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Ord = {
    id: number; po_number: string; po_date: string | null; project: string | null; status: string;
    total: number; created_at: string | null; printed_at: string | null; print_count: number;
};
type Group = {
    supplier_id: number | null; supplier_code: string | null; supplier: string;
    po_count: number; total: number; printed_count: number; orders: Ord[];
};

const props = defineProps<{
    groups: Group[];
    filters: { supplier?: string; from?: string; to?: string };
    chosen: boolean;
    supplierOptions: Array<{ id: string; label: string; code: string | null }>;
    summary: { suppliers: number; po_count: number; total: number; printed: number };
}>();

const supplier = ref(props.filters.supplier ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
const search = ref('');

function applyRange() {
    router.get(route('bs.po.report'), {
        supplier: supplier.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true });
}
function resetRange() { supplier.value = ''; from.value = ''; to.value = ''; search.value = ''; applyRange(); }
const labelSupplier = computed(() => {
    if (props.filters.supplier === 'all') return 'Semua Supplier';
    return props.supplierOptions.find(s => s.id === props.filters.supplier)?.label ?? '';
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function fmtDateTime(d: string | null) {
    if (!d) return '—';
    return new Date(d.replace(' ', 'T')).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
const statusVariant: Record<string, any> = { draft: 'neutral', approved: 'success', closed: 'info' };

// Pencarian menyaring supplier maupun nomor PO (grup ikut tersaring bila ada PO yang cocok).
const filtered = computed<Group[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.groups;
    return props.groups
        .map((g) => {
            if (g.supplier.toLowerCase().includes(q) || (g.supplier_code ?? '').toLowerCase().includes(q)) return g;
            const orders = g.orders.filter((o) => o.po_number.toLowerCase().includes(q) || (o.project ?? '').toLowerCase().includes(q));
            if (!orders.length) return null;
            // Ringkasan header WAJIB ikut dihitung ulang dari baris yang tersisa — kalau tidak,
            // header memakai angka seluruh PO supplier sehingga bisa mustahil ("6 dari 1 PO
            // sudah dicetak") dan nilainya tidak cocok dengan kolom Total yang tampil/diekspor.
            return {
                ...g,
                orders,
                po_count: orders.length,
                total: Math.round(orders.reduce((s, o) => s + Number(o.total ?? 0), 0) * 100) / 100,
                printed_count: orders.filter((o) => o.printed_at).length,
            };
        })
        .filter((g): g is Group => g !== null);
});

// Semua grup default TERBUKA; entri baru muncul saat pertama kali di-toggle.
// Toggle dihitung dari isOpen() — bukan dari open[key] mentah — karena nilai awalnya
// undefined, sehingga `!open[key]` akan bernilai true dan grup gagal tertutup di klik pertama.
const open = reactive<Record<string, boolean>>({});
function isOpen(k: string | number) { return open[String(k)] !== false; }
function toggle(k: string | number) { open[String(k)] = !isOpen(k); }

function exportExcel() {
    const esc = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const rows = filtered.value.flatMap((g) => g.orders.map((o) => '<tr>'
        + `<td>${esc(g.supplier)}</td><td>${esc(o.po_number)}</td><td>${esc(fmtDate(o.po_date))}</td>`
        + `<td>${esc(o.project ?? '')}</td><td>${esc(o.status)}</td>`
        + `<td>${Number(o.total ?? 0).toLocaleString('id-ID')}</td>`
        + `<td>${esc(fmtDateTime(o.created_at))}</td><td>${esc(fmtDateTime(o.printed_at))}</td><td>${o.print_count}</td>`
        + '</tr>').join('')).join('');
    const html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
        + '<table border="1"><tr style="background:#c2410c;color:#fff">'
        + '<th>Supplier</th><th>No. PO</th><th>Tgl PO</th><th>Proyek</th><th>Status</th><th>Total</th><th>Waktu Dibuat</th><th>Waktu Dicetak</th><th>Jml Cetak</th>'
        + `</tr>${rows}</table></body></html>`;
    const url = URL.createObjectURL(new Blob([html], { type: 'application/vnd.ms-excel' }));
    const a = document.createElement('a');
    a.href = url; a.download = `Report-PO-${new Date().toISOString().slice(0, 10)}.xls`;
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
}
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> Report PO <span class="text-base font-normal text-neutral-400">(Rekap Purchase Order per Supplier)</span></h1>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="exportExcel" :disabled="!chosen || !filtered.length" title="Pilih supplier dan tampilkan dulu" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
                <Link :href="route('bs.po.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700">Purchase Order</Link>
            </div>
        </div>

        <div v-if="chosen" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card border-l-4 border-primary-500 p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Supplier</div><div class="mt-1 text-xl font-bold">{{ summary.suppliers }}</div></div>
            <div class="card border-l-4 border-sky-500 p-4"><div class="text-xs uppercase text-neutral-400">Jumlah PO</div><div class="mt-1 text-xl font-bold">{{ summary.po_count }}</div></div>
            <div class="card border-l-4 border-emerald-500 p-4"><div class="text-xs uppercase text-neutral-400">Nilai PO</div><div class="mt-1 text-lg font-bold text-emerald-600">{{ fmtRp(summary.total) }}</div></div>
            <div class="card border-l-4 border-amber-500 p-4"><div class="flex items-center gap-1 text-xs uppercase text-neutral-400"><Printer class="h-3.5 w-3.5 text-amber-500" /> Sudah Dicetak</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.printed }}<span class="text-sm font-normal text-neutral-400"> / {{ summary.po_count }}</span></div></div>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <!-- Supplier dipilih lebih dulu; tanpa itu laporan tidak menampilkan apa pun. -->
            <div class="min-w-[240px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label>
                <select v-model="supplier" :class="[inputCls, 'w-full']">
                    <option value="">— pilih supplier —</option>
                    <option value="all">Semua Supplier</option>
                    <option v-for="s in supplierOptions" :key="s.id" :value="s.id">{{ s.label }}<template v-if="s.code"> ({{ s.code }})</template></option>
                </select>
            </div>
            <div class="relative min-w-[220px] flex-1">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari no. PO / proyek</label>
                <Search class="pointer-events-none absolute left-3 top-[34px] h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" type="text" :disabled="!chosen" placeholder="Ketik untuk menyaring…" :class="[inputCls, 'w-full pl-9 disabled:opacity-50']" />
            </div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tgl PO</label><input v-model="from" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label><input v-model="to" type="date" :class="inputCls" /></div>
            <button @click="applyRange" :disabled="!supplier" title="Pilih supplier terlebih dahulu" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-40">Display</button>
            <button v-if="supplier || from || to || search" @click="resetRange" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700"><FilterX class="h-4 w-4" /> Reset</button>
        </div>

        <!-- Belum memilih supplier → tidak ada data yang ditarik maupun ditampilkan. -->
        <div v-if="!chosen" class="card flex flex-col items-center gap-2 px-6 py-12 text-center">
            <ClipboardList class="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
            <p class="font-semibold">Pilih supplier terlebih dahulu</p>
            <p class="max-w-md text-sm text-neutral-500">Tentukan supplier pada kotak di atas — atau pilih <b>Semua Supplier</b> untuk seluruhnya — lalu klik <b>Display</b> untuk menampilkan rekap PO-nya.</p>
        </div>

        <p v-else class="text-xs text-neutral-500">Menampilkan <b>{{ labelSupplier }}</b><template v-if="filters.from || filters.to"> · PO {{ filters.from || '…' }} s/d {{ filters.to || '…' }}</template>.</p>

        <!-- Satu kartu per supplier: jumlah PO + rincian nomor PO -->
        <div v-for="g in (chosen ? filtered : [])" :key="g.supplier_id ?? 'none'" class="card overflow-hidden">
            <button type="button" @click="toggle(g.supplier_id ?? 'none')" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                <span class="min-w-0">
                    <span class="block font-semibold">{{ g.supplier }}<span v-if="g.supplier_code" class="ml-2 font-mono text-xs text-neutral-400">{{ g.supplier_code }}</span></span>
                    <span class="text-xs text-neutral-400">{{ g.printed_count }} dari {{ g.po_count }} PO sudah dicetak</span>
                </span>
                <span class="ml-auto flex items-center gap-4">
                    <span class="text-right"><span class="block text-xl font-bold tabular-nums">{{ g.po_count }}</span><span class="text-[10px] uppercase text-neutral-400">PO</span></span>
                    <span class="text-right"><span class="block font-mono text-sm font-semibold">{{ fmtRp(g.total) }}</span><span class="text-[10px] uppercase text-neutral-400">nilai</span></span>
                    <ChevronDown class="h-5 w-5 shrink-0 text-neutral-400 transition-transform duration-300" :class="isOpen(g.supplier_id ?? 'none') ? 'rotate-180' : ''" />
                </span>
            </button>
            <div v-show="isOpen(g.supplier_id ?? 'none')" class="overflow-x-auto border-t border-neutral-100 dark:border-neutral-800">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-4 py-2.5">No. PO</th><th class="px-4 py-2.5">Tgl PO</th><th class="px-4 py-2.5">Proyek</th>
                            <th class="px-4 py-2.5 text-center">Status</th><th class="px-4 py-2.5 text-right">Total</th>
                            <th class="whitespace-nowrap px-4 py-2.5">Waktu Dibuat</th>
                            <th class="whitespace-nowrap px-4 py-2.5">Waktu Dicetak</th>
                            <th class="px-4 py-2.5 text-center">Cetak</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="o in g.orders" :key="o.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="whitespace-nowrap px-4 py-2"><Link :href="route('bs.po.show', o.id)" class="font-mono font-semibold text-primary-600 hover:underline">{{ o.po_number }}</Link></td>
                            <td class="whitespace-nowrap px-4 py-2">{{ fmtDate(o.po_date) }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ o.project ?? '—' }}</td>
                            <td class="px-4 py-2 text-center"><Badge :variant="statusVariant[o.status] ?? 'neutral'" size="sm">{{ o.status }}</Badge></td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-mono">{{ fmtRp(o.total) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-neutral-500">{{ fmtDateTime(o.created_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-2" :class="o.printed_at ? '' : 'text-neutral-400'">{{ fmtDateTime(o.printed_at) }}</td>
                            <td class="px-4 py-2 text-center">{{ o.print_count || '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <a :href="route('bs.po.print', { ids: o.id })" class="inline-flex items-center gap-1 text-xs text-primary-600 hover:underline"><Printer class="h-3.5 w-3.5" /> Cetak</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template v-if="chosen">
            <EmptyState v-if="!groups.length" title="Tidak ada PO" description="Supplier ini belum punya Purchase Order pada rentang tanggal yang dipilih." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada PO yang cocok dengan pencarian." />
        </template>
    </div>
</template>
