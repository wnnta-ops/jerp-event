<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    accounts: Array<{ id: number; code: string; name: string }>;
    account: { id: number; code: string; name: string } | null;
    opening: number;
    rows: Array<any>;
    filters: Record<string, any>;
}>();

const accountId = ref(props.filters.account_id ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
function display() { router.get(route('bs.gl.ledger'), { account_id: accountId.value || undefined, from: from.value || undefined, to: to.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Buku Besar (General Ledger)</h1>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[280px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun (ketik kode / nama)</label><SearchSelect v-model="accountId" :options="accounts" placeholder="— cari akun —" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari</label><input v-model="from" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label><input v-model="to" type="date" :class="inputCls" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div v-if="account" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <span class="font-mono font-semibold">{{ account.code }}</span> — {{ account.name }}
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-right">Saldo</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr class="bg-neutral-50/50 dark:bg-neutral-800/30"><td colspan="5" class="px-4 py-2 text-right text-neutral-500">Saldo Awal</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(opening) }}</td></tr>
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.number }}</td>
                        <td class="px-4 py-2.5">{{ r.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.debit > 0 ? fmtRp(r.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.credit > 0 ? fmtRp(r.credit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.balance) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada mutasi pada periode ini" />
        </div>
        <EmptyState v-else title="Pilih akun" description="Pilih akun lalu klik Display untuk menampilkan buku besar." />
    </div>
</template>
