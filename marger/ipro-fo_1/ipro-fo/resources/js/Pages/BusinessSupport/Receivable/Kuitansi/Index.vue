<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, ArrowLeft, ReceiptText, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ kuitansis: any; filters: { status?: string; search?: string }; summary: { active: number; count: number } }>();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('bs.ar.kuitansi.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const alat: Record<string, string> = { tunai: 'Tunai', transfer: 'Transfer', giro: 'Cek/Giro' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-success-600" /> Kuitansi Piutang</h1>
            <Link :href="route('bs.ar.kuitansi.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Terima Piutang</Button></Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Diterima (aktif)</div><div class="mt-1 text-xl font-bold text-success-700">{{ fmtRp(summary.active) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Kuitansi</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. kuitansi…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua</option><option value="active">Aktif</option><option value="void">Void</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Kuitansi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Alat</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="k in kuitansis.data" :key="k.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.ar.kuitansi.show', k.id))">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ k.kuitansi_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(k.kuitansi_date) }}</td>
                        <td class="px-4 py-2.5">{{ k.customer?.full_name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ alat[k.alat_transaksi] }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(k.total) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="k.status === 'void' ? 'danger' : 'success'" size="sm">{{ k.status === 'void' ? 'Void' : 'Aktif' }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!kuitansis.data?.length" title="Belum ada kuitansi" description="Klik Terima Piutang untuk membuat voucher penerimaan customer." />
        </div>
    </div>
</template>
