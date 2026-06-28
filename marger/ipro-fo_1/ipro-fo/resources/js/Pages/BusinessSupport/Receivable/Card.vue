<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, BookText } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ customers: Array<any>; customerId: number | null; entries: Array<any>; outstanding: number }>();
const selected = ref(props.customerId ?? '');
function display() { router.get(route('bs.ar.card'), { customer_id: selected.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-success-600" /> Kartu Piutang</h1>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[240px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Customer</label>
                <select v-model="selected" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">— pilih customer —</option><option v-for="c in customers" :key="c.id" :value="c.id">{{ c.full_name }}</option></select>
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <div v-if="customerId" class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Saldo Piutang</div><div class="font-mono text-lg font-bold text-success-600">Rp {{ fmtRp(outstanding) }}</div></div>
        </div>

        <div v-if="customerId" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Tagihan</th><th class="px-4 py-3 text-right">Terima</th><th class="px-4 py-3 text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(e, i) in entries" :key="i">
                        <td class="px-4 py-2.5">{{ fmtDate(e.date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ e.ref }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ e.desc }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ e.debit ? fmtRp(e.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ e.credit ? fmtRp(e.credit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(e.balance) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!entries?.length" title="Belum ada transaksi" description="Customer ini belum memiliki invoice piutang." />
        </div>
        <EmptyState v-else title="Pilih customer" description="Pilih customer untuk melihat kartu piutangnya." />
    </div>
</template>
