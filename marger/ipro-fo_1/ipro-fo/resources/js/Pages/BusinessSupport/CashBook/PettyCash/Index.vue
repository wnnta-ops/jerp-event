<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Wallet, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ transactions: any; saldo: number; summary: any; filters: Record<string, any>; warning?: string }>();
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
function applyFilter() {
    router.get(route('bs.pettycash.index'), { from: from.value || undefined, to: to.value || undefined }, { preserveState: true, replace: true });
}
function resetFilter() { from.value = ''; to.value = ''; applyFilter(); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-primary-600" /> Kas Kecil (Petty Cash)</h1>
            <Link :href="route('bs.pettycash.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambah</Button></Link>
        </div>
        <div v-if="warning" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">{{ warning }}</div>
        <!-- #53: filter periode -->
        <div class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-[11px] font-medium text-neutral-500">Dari Tanggal</label><input v-model="from" @change="applyFilter" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-[11px] font-medium text-neutral-500">Sampai Tanggal</label><input v-model="to" @change="applyFilter" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <button v-if="filters.from || filters.to" type="button" @click="resetFilter" class="h-9 rounded-lg border border-neutral-300 px-3 text-sm text-neutral-500 hover:bg-neutral-50 dark:border-neutral-700">Reset</button>
            <span v-if="filters.from || filters.to" class="text-xs text-neutral-400">Menampilkan periode {{ fmtDate(filters.from) }} – {{ fmtDate(filters.to) }}</span>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs text-neutral-500">Saldo Kas Kecil{{ filters.to ? ' (s.d. ' + fmtDate(filters.to) + ')' : '' }}</div><div class="font-mono text-xl font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(saldo) }}</div></div>
            <div class="card p-4"><div class="text-xs text-neutral-500">Total Masuk{{ filters.from || filters.to ? ' (periode)' : '' }}</div><div class="font-mono text-lg text-success-600">{{ fmtRp(summary.in) }}</div></div>
            <div class="card p-4"><div class="text-xs text-neutral-500">Total Keluar{{ filters.from || filters.to ? ' (periode)' : '' }}</div><div class="font-mono text-lg text-danger-600">{{ fmtRp(summary.out) }}</div></div>
        </div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No</th><th class="px-4 py-2.5">Tanggal</th><th class="px-4 py-2.5">Keterangan</th><th class="px-4 py-2.5 text-right">Mutasi Masuk</th><th class="px-4 py-2.5 text-right">Mutasi Keluar</th><th class="px-4 py-2.5 text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in transactions.data" :key="t.id">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ t.trx_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(t.trx_date) }}</td>
                        <td class="px-4 py-2.5">{{ t.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ t.masuk > 0 ? fmtRp(t.masuk) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ t.keluar > 0 ? fmtRp(t.keluar) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="(t.running_saldo ?? 0) >= 0 ? 'text-neutral-700 dark:text-neutral-200' : 'text-danger-600'">{{ t.running_saldo != null ? fmtRp(t.running_saldo) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!transactions.data?.length" title="Belum ada transaksi kas kecil" description="Klik Tambah untuk top-up atau catat pengeluaran kas kecil." />
        </div>
    </div>
</template>
