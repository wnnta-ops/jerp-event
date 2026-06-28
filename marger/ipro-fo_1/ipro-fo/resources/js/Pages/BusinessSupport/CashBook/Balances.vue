<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Landmark } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ rows: Array<any>; total: number; asOf: string }>();
const asOf = ref(props.asOf);
function applyAsOf() {
    router.get(route('bs.cashbook.balances'), { as_of: asOf.value || undefined }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> Saldo Rekening</h1>
        <p class="text-sm text-neutral-500">Saldo kas &amp; bank dihitung langsung dari jurnal terposting (General Ledger).</p>

        <!-- #84: pilih saldo per tanggal (s/d) -->
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm dark:border-neutral-800 dark:bg-neutral-800/50">
            <label for="as_of" class="font-medium text-neutral-600 dark:text-neutral-300">Saldo per tanggal</label>
            <input id="as_of" v-model="asOf" @change="applyAsOf" type="date" title="Tampilkan saldo s/d tanggal ini" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            <span class="text-neutral-500">Menampilkan saldo s/d <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ fmtDate(asOf) }}</span>.</span>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-400">{{ r.code }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="r.balance >= 0 ? '' : 'text-danger-600'">{{ fmtRp(r.balance) }}</td>
                        <td class="px-4 py-2.5 text-right"><Link :href="route('bs.cashbook.index', { account_id: r.id, to: asOf })" class="text-xs text-primary-600 hover:underline">Mutasi →</Link></td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800"><tr><td colspan="2" class="px-4 py-3 text-right">Total Kas &amp; Bank</td><td class="px-4 py-3 text-right font-mono">{{ fmtRp(total) }}</td><td></td></tr></tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Belum ada rekening" description="Rekening kas/bank diambil dari Chart of Account (KAS DAN SETARA KAS)." />
        </div>
    </div>
</template>
