<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, FileSpreadsheet } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Ledger = {
    code: string; name: string; opening: number;
    rows: Array<{ date: string; number: string; description: string; debit: number; credit: number; balance: number }>;
    total_debit: number; total_credit: number; closing: number;
};
const props = defineProps<{
    accounts: Array<{ id: number; code: string; name: string }>;
    ledgers: Array<Ledger>;
    filters: Record<string, any>;
}>();

const fromCode = ref(props.filters.from_code ?? '');
const toCode = ref(props.filters.to_code ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

// SearchSelect memilih berdasarkan KODE akun (bukan id) → kirim from_code/to_code ke server.
const params = computed(() => ({
    from_code: fromCode.value || undefined,
    to_code: toCode.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
}));
function display() { router.get(route('bs.gl.ledger'), params.value, { preserveState: true, replace: true }); }
const excelUrl = computed(() => route('bs.gl.ledger.export', params.value));

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Buku Besar (General Ledger)</h1>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[240px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Dari COA (kode / nama)</label><SearchSelect v-model="fromCode" :options="accounts" option-value="code" placeholder="— akun awal —" :no-truncate="true" /></div>
            <div class="min-w-[240px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai COA (kode / nama)</label><SearchSelect v-model="toCode" :options="accounts" option-value="code" placeholder="— akun akhir (opsional) —" :no-truncate="true" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari</label><input v-model="from" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label><input v-model="to" type="date" :class="inputCls" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            <a :href="excelUrl" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-success-300 bg-success-50 px-4 text-sm font-semibold text-success-700 hover:bg-success-100 dark:border-success-500/40 dark:bg-success-500/10 dark:text-success-300">
                <FileSpreadsheet class="h-4 w-4" /> Excel
            </a>
        </div>

        <p v-if="fromCode || toCode" class="text-xs text-neutral-500">Kosongkan "Sampai COA" untuk satu akun; isi keduanya untuk rentang kode akun (mis. 50302020216 s/d 50302029999).</p>

        <div v-for="l in ledgers" :key="l.code" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <span class="font-mono font-semibold">{{ l.code }}</span> — {{ l.name }}
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-right">Saldo</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr class="bg-neutral-50/50 dark:bg-neutral-800/30"><td colspan="5" class="px-4 py-2 text-right text-neutral-500">Saldo Awal</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.opening) }}</td></tr>
                    <tr v-for="(r, i) in l.rows" :key="i">
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.number }}</td>
                        <td class="px-4 py-2.5">{{ r.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.debit > 0 ? fmtRp(r.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.credit > 0 ? fmtRp(r.credit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.balance) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                        <td colspan="3" class="px-4 py-2 text-right text-neutral-500">Total</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.total_debit) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.total_credit) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.closing) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <EmptyState v-if="(fromCode || toCode) && !ledgers.length" title="Tidak ada mutasi pada rentang & periode ini" />
        <EmptyState v-else-if="!fromCode && !toCode" title="Pilih akun" description="Pilih COA (satu akun atau rentang Dari–Sampai) lalu klik Display." />
    </div>
</template>
