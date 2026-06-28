<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpCircle, Download, Check } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    period: { year: number; month: number };
    totals: { dpp: number; ppn: number; count: number; ar_ppn: number; prop_ppn: number };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.keluaran'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

// NSFP edit lokal
const edited = ref<Record<number, string>>({});
const savedId = ref<number | null>(null);
function saveNsfp(row: any) {
    const val = edited.value[row.id] ?? row.tax_invoice_no ?? '';
    if (val === (row.tax_invoice_no ?? '')) return;
    router.post(route('bs.tax.nsfp'), { type: 'keluaran', invoice_id: row.id, tax_invoice_no: val }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { row.tax_invoice_no = val; savedId.value = row.id; setTimeout(() => { if (savedId.value === row.id) savedId.value = null; }, 1500); },
    });
}
const inputCls = 'h-8 w-44 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.tax.rekap', { year: period.year, month: period.month })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Rekap PPN</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ArrowUpCircle class="h-6 w-6 text-success-600" /> Faktur Pajak Keluaran <span class="text-base font-normal text-neutral-400">(PPN Penjualan)</span></h1>
            <div class="flex gap-2">
                <select v-model.number="month" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select>
                <select v-model.number="year" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <a :href="route('bs.tax.export.keluaran', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> CSV</a>
                <a :href="route('bs.tax.efaktur.csv', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur CSV</a>
                <a :href="route('bs.tax.efaktur.xml', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur XML</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total DPP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(totals.dpp) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total PPN Keluaran</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(totals.ppn) }}</div><div class="mt-1 text-[11px] text-neutral-400">AR {{ fmtRp(totals.ar_ppn) }} · Properti {{ fmtRp(totals.prop_ppn) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Faktur</div><div class="mt-1 text-xl font-bold">{{ totals.count }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. Faktur Pajak</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">NPWP</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">No. Dokumen</th><th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.source + r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2">
                            <div v-if="r.editable" class="flex items-center gap-1"><input :value="edited[r.id] ?? r.tax_invoice_no ?? ''" @input="edited[r.id] = ($event.target as HTMLInputElement).value" @blur="saveNsfp(r)" @keyup.enter="saveNsfp(r)" placeholder="000.000-00.00000000" :class="inputCls" /><Check v-if="savedId === r.id" class="h-4 w-4 text-success-500" /></div>
                            <span v-else class="font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ r.tax_invoice_no ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-2.5"><Badge :variant="r.source === 'properti' ? 'primary' : 'neutral'" size="sm">{{ r.source === 'properti' ? 'Properti' : 'AR' }}</Badge></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.npwp }}</td>
                        <td class="px-4 py-2.5">{{ r.partner }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500"><Link v-if="r.source === 'ar'" :href="route('bs.ar.show', r.id)" class="text-primary-600 hover:underline">{{ r.doc_no }}</Link><span v-else>{{ r.doc_no }}</span></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.dpp) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(r.ppn) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800"><td colspan="6" class="px-4 py-2.5 text-right">Total</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.dpp) }}</td><td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(totals.ppn) }}</td></tr></tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada faktur keluaran" description="Tidak ada invoice piutang ber-PPN pada masa pajak ini." />
        </div>
    </div>
</template>
