<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, ArrowLeft, Coins, Search, CalendarClock, BookText, Tags, ReceiptText, FileText, Eraser } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    invoices: any;
    filters: { status?: string; search?: string };
    summary: { open: number; count_open: number; count_total: number };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

function applyFilter() {
    router.get(route('bs.ar.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Coins class="h-6 w-6 text-success-600" /> Account Receivable <span class="text-base font-normal text-neutral-400">(Piutang Usaha)</span></h1>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('bs.ar.categories.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Tags class="h-4 w-4" /> Kategori</Link>
                <Link :href="route('bs.ar.aging')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><CalendarClock class="h-4 w-4" /> Aging</Link>
                <Link :href="route('bs.ar.card')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Kartu</Link>
                <Link :href="route('bs.ar.memorial.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileText class="h-4 w-4" /> Memorial</Link>
                <Link :href="route('bs.ar.penghapusan.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Eraser class="h-4 w-4" /> Hapus</Link>
                <Link :href="route('bs.ar.kuitansi.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><ReceiptText class="h-4 w-4" /> Kuitansi</Link>
                <Link :href="route('bs.ar.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Invoice</Button></Link>
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

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Invoice</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in invoices.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.ar.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.invoice_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.customer?.full_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="(r.total - r.paid_amount) > 0 ? 'text-success-600' : 'text-neutral-400'">{{ fmtRp(r.total - r.paid_amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'paid' ? 'success' : 'warning'" size="sm">{{ r.status === 'paid' ? 'Lunas' : 'Open' }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!invoices.data?.length" title="Belum ada tagihan piutang" description="Klik Catat Piutang untuk menagih ke customer." />
        </div>
    </div>
</template>
