<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Plus, ArrowLeft, Coins, Search, CalendarClock, BookText, Tags, ReceiptText, FileText, Eraser, Printer } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = {
    id: number; invoice_number: string; invoice_date: string | null; due_date: string | null;
    customer: string | null; customer_code: string | null; customer_id: number | null;
    booking_id: number | null; spr: string | null; unit: string | null;
    unit_type: string | null; description: string | null;
    dpp: number; ppn: number; total: number; outstanding: number; paid_amount: number; status: string;
};
type SprOpt = { id: number; label: string };
const props = defineProps<{
    invoices: { data: Row[]; links?: Array<any>; total?: number };
    sprOptions: Record<string, SprOpt[]>;
    filters: { status?: string; search?: string };
    summary: { open: number; count_open: number; count_total: number };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

function applyFilter() {
    selected.value = []; // hasil filter berubah → centang lama bisa tak lagi tampil
    router.get(route('bs.ar.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}

// ── Pilih massal untuk cetak bukti piutang ──
const selected = ref<number[]>([]);
const pageIds = computed<number[]>(() => (props.invoices.data ?? []).map((r) => r.id));
const allChecked = computed<boolean>({
    get: () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
    set: (v: boolean) => { selected.value = v ? [...pageIds.value] : []; },
});
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';

// ── Kaitkan SPR pada invoice yang sudah tercatat ──
// Kolom booking_id baru, jadi seluruh invoice lama bernilai NULL; tanpa jalan ini mereka
// tidak akan pernah masuk Rekonsiliasi per SPR.
const savingSpr = ref(false);
function optionsFor(r: Row): SprOpt[] { return props.sprOptions?.[String(r.customer_id ?? '')] ?? []; }
function simpanSpr(r: Row, value: string) {
    if (savingSpr.value) return;
    savingSpr.value = true;
    router.patch(route('bs.ar.link-spr', r.id), { booking_id: value === '' ? null : Number(value) }, {
        preserveScroll: true,
        onFinish: () => { savingSpr.value = false; },
    });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Coins class="h-6 w-6 text-success-600" /> Account Receivable <span class="text-base font-normal text-neutral-400">(Piutang Usaha)</span></h1>
            <!-- "Kategori" & "Invoice" (registrasi manual) DIHILANGKAN: invoice piutang dibentuk
                 otomatis dari Booked Revenue, tidak bisa ditambah manual. -->
            <div class="flex flex-wrap gap-2">
                <Link :href="route('bs.ar.aging')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><CalendarClock class="h-4 w-4" /> Aging</Link>
                <Link :href="route('bs.ar.card')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Kartu</Link>
                <Link :href="route('bs.ar.memorial.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileText class="h-4 w-4" /> Memorial</Link>
                <Link :href="route('bs.ar.penghapusan.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Eraser class="h-4 w-4" /> Hapus</Link>
                <Link :href="route('bs.ar.kuitansi.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><ReceiptText class="h-4 w-4" /> Kuitansi</Link>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Piutang (Open)</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(summary.open) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Tagihan Open</div><div class="mt-1 text-xl font-bold">{{ summary.count_open }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Tagihan</div><div class="mt-1 text-xl font-bold">{{ summary.count_total }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. / keterangan…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua status</option><option value="open">Open</option><option value="paid">Lunas</option>
            </select>
        </div>

        <!-- Bilah aksi massal — sticky agar tetap terjangkau saat menggulir. -->
        <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> piutang dipilih</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a :href="route('bs.ar.print-bulk', { ids: selected.join(',') })" target="_blank" rel="noopener"
                   title="Cetak bukti piutang untuk semua baris terpilih — 1 halaman per invoice."
                   class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak Piutang ({{ selected.length }})</a>
                <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="w-10 px-3 py-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :disabled="!pageIds.length" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua baris'" /></th>
                    <th class="px-4 py-3">No. Invoice</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">No. SPR</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th>
                    <th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Sisa</th>
                    <th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-center">Cetak</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in invoices.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="selected.includes(r.id) ? 'bg-primary-500/10' : ''">
                        <td class="px-3 py-2.5"><input v-model="selected" type="checkbox" :value="r.id" :class="cbCls" title="Pilih untuk cetak bukti piutang" /></td>
                        <td class="cursor-pointer px-4 py-2.5 font-mono font-semibold" @click="router.get(route('bs.ar.show', r.id))">{{ r.invoice_number }}</td>
                        <td class="cursor-pointer whitespace-nowrap px-4 py-2.5" @click="router.get(route('bs.ar.show', r.id))">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="cursor-pointer px-4 py-2.5" @click="router.get(route('bs.ar.show', r.id))">{{ r.customer ?? '—' }}</td>
                        <!-- Select langsung (pola sama dengan Rekening Ledger): tanpa mode-edit,
                             sehingga tidak ada urutan change/blur yang bisa membuang perubahan. -->
                        <td class="whitespace-nowrap px-4 py-2.5 text-xs">
                            <select v-if="optionsFor(r).length" :value="r.booking_id ?? ''" :disabled="savingSpr"
                                    @change="simpanSpr(r, ($event.target as HTMLSelectElement).value)"
                                    title="Kaitkan invoice ini ke SPR — dasar pengelompokan Rekonsiliasi Piutang"
                                    class="h-7 max-w-[170px] rounded border border-neutral-300 bg-white px-1 font-mono text-xs disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">— tanpa SPR —</option>
                                <option v-for="o in optionsFor(r)" :key="o.id" :value="o.id">{{ o.label }}</option>
                            </select>
                            <span v-else class="font-mono text-neutral-400" title="Customer ini belum punya SPR yang bisa dikaitkan">{{ r.spr ?? '—' }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5">
                            <span>{{ r.unit ?? '—' }}</span>
                            <span v-if="r.unit_type" class="block text-xs text-neutral-400">{{ r.unit_type }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.description }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.dpp) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.ppn) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono" :class="r.outstanding > 0 ? 'text-success-600' : 'text-neutral-400'">{{ fmtRp(r.outstanding) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'paid' ? 'success' : 'warning'" size="sm">{{ r.status === 'paid' ? 'Lunas' : 'Open' }}</Badge></td>
                        <td class="px-4 py-2.5 text-center">
                            <a :href="route('bs.ar.print', r.id)" target="_blank" rel="noopener" title="Cetak bukti piutang ini" class="inline-flex text-primary-600 hover:text-primary-800"><Printer class="h-4 w-4" /></a>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!invoices.data?.length" title="Belum ada tagihan piutang" description="Klik Catat Piutang untuk menagih ke customer." />
        </div>

        <!-- Pager — daftar dipaginasi 50/halaman; tanpa ini baris ke-51 dst. tak terjangkau. -->
        <div v-if="invoices.data?.length" class="flex flex-wrap items-center gap-1">
            <p class="mr-2 text-xs text-neutral-500">Menampilkan {{ invoices.data.length }} dari {{ invoices.total ?? invoices.data.length }} baris</p>
            <template v-if="(invoices.links?.length ?? 0) > 3">
                <component :is="l.url ? Link : 'span'" v-for="l in invoices.links" :key="l.label" :href="l.url" v-html="l.label"
                    class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
            </template>
        </div>
    </div>
</template>
