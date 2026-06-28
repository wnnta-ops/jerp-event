<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, ClipboardList, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    sips: any;
    statuses: Record<string, string>;
    filters: { status?: string; search?: string };
    summary: { pending: number; approved: number; total: number };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('bs.sip.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const badge: Record<string, any> = { submitted: 'info', printed: 'warning', approved: 'success', cancelled: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> SIP <span class="text-base font-normal text-neutral-400">(Standing Instruction Payment)</span></h1>
            <Link :href="route('bs.sip.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Buat SIP</Button></Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum Disetujui</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.pending }}</div><div class="text-[11px] text-neutral-400">Diajukan / Dicetak</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Disetujui</div><div class="mt-1 text-xl font-bold text-success-600">{{ summary.approved }}</div><div class="text-[11px] text-neutral-400">semua pihak TTD</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Nilai SIP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(summary.total) }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. SIP / judul…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua status</option><option v-for="(label, val) in statuses" :key="val" :value="val">{{ label }}</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. SIP</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Bank Sumber</th><th class="px-4 py-3">Judul</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in sips.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.sip.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.sip_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.sip_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.cash_account?.name }}</td>
                        <td class="px-4 py-2.5">{{ r.title ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="badge[r.status] ?? 'neutral'" size="sm">{{ statuses[r.status] }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!sips.data?.length" title="Belum ada SIP" description="Klik Buat SIP untuk mengajukan instruksi pembayaran tagihan & bon per bank sumber." />
        </div>
    </div>
</template>
