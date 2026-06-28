<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    accounts: Array<{ id: number; code: string; name: string }>;
    accountId: number | null;
    opening: number;
    entries: Array<any>;
    closing: number;
    filters: { from: string | null; to: string | null };
}>();

const account = ref(props.accountId ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
function display() { router.get(route('bs.cashbook.ledger'), { account_id: account.value || undefined, from: from.value || undefined, to: to.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Rekening Ledger</h1>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[260px] flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Rekening Kas/Bank</label><SearchSelect v-model="account" :options="accounts" placeholder="— pilih rekening —" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari</label><input v-model="from" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label><input v-model="to" type="date" :class="inputCls" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <div v-if="accountId" class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Saldo Akhir</div><div class="font-mono text-lg font-bold">Rp {{ fmtRp(closing) }}</div></div>
        </div>

        <div v-if="accountId" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr class="bg-neutral-50/60 dark:bg-neutral-800/40"><td class="px-4 py-2 font-medium" colspan="5">Saldo Awal</td><td class="px-4 py-2 text-right font-mono font-semibold">{{ fmtRp(opening) }}</td></tr>
                    <tr v-for="(e, i) in entries" :key="i">
                        <td class="px-4 py-2">{{ fmtDate(e.date) }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ e.ref }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ e.desc }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ e.debit ? fmtRp(e.debit) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ e.credit ? fmtRp(e.credit) : '' }}</td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">{{ fmtRp(e.balance) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-neutral-200 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800"><tr><td class="px-4 py-2.5" colspan="5">Saldo Akhir</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(closing) }}</td></tr></tfoot>
            </table>
            <EmptyState v-if="!entries?.length" title="Tidak ada mutasi" description="Rekening ini belum punya mutasi pada rentang tsb." />
        </div>
        <EmptyState v-else title="Pilih rekening" description="Pilih rekening kas/bank untuk melihat buku besarnya." />
    </div>
</template>
