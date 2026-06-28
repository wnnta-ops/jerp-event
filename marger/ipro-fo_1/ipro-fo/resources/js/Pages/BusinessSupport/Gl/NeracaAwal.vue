<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText, CheckCircle2, AlertTriangle, FileText } from 'lucide-vue-next';

const props = defineProps<{
    year: number;
    assets: Array<any>;
    liabilities: Array<any>;
    equity: Array<any>;
    unclosed: number;
    totals: { assets: number; liabilities: number; equity: number; liab_equity: number; balanced: boolean };
    priorClosed: boolean;
}>();

const year = ref(props.year);
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i + 1);
function changeYear() { router.get(route('bs.closing.neraca'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.closing.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Tutup Buku</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> Neraca Awal <span class="text-base font-normal text-neutral-400">Tahun {{ year }}</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.closing.saldoawal', { year })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><FileText class="h-4 w-4" /> Jurnal Saldo Awal</Link>
                <select v-model.number="year" @change="changeYear" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Saldo carry-forward per <b>1 Januari {{ year }}</b> = saldo kumulatif akun permanen s/d 31 Desember {{ year - 1 }}. Diturunkan otomatis dari General Ledger.</p>

        <div v-if="priorClosed" class="card flex items-center gap-3 p-4 ring-1 ring-success-200 dark:ring-success-900">
            <CheckCircle2 class="h-6 w-6 text-success-600" />
            <div class="text-sm"><span class="font-semibold">Tahun {{ year - 1 }} sudah ditutup.</span> Laba bersih telah masuk ke Laba Ditahan (Ekuitas) — neraca awal lengkap.</div>
        </div>
        <div v-else class="card flex items-center gap-3 p-4 ring-1 ring-amber-200 dark:ring-amber-900">
            <AlertTriangle class="h-6 w-6 text-amber-600" />
            <div class="text-sm"><span class="font-semibold">Tahun {{ year - 1 }} belum ditutup.</span> Laba berjalan ditampilkan sebagai pos sementara "Laba belum ditutup". Lakukan Tutup Buku agar masuk ke Laba Ditahan permanen.</div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Aset -->
            <div class="card overflow-hidden">
                <div class="border-b border-neutral-200 bg-neutral-50 px-4 py-3 font-semibold dark:border-neutral-800 dark:bg-neutral-800">AKTIVA (Aset)</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="a in assets" :key="a.code"><td class="px-4 py-2"><span class="font-mono text-xs text-neutral-400">{{ a.code }}</span> {{ a.name }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(a.amount) }}</td></tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-primary-400 bg-neutral-50 font-bold dark:bg-neutral-800"><td class="px-4 py-2.5">TOTAL AKTIVA</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.assets) }}</td></tr></tfoot>
                </table>
            </div>
            <!-- Pasiva -->
            <div class="card overflow-hidden">
                <div class="border-b border-neutral-200 bg-neutral-50 px-4 py-3 font-semibold dark:border-neutral-800 dark:bg-neutral-800">PASIVA (Kewajiban + Ekuitas)</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-if="liabilities.length" class="bg-neutral-50/50 dark:bg-neutral-800/30"><td colspan="2" class="px-4 py-1.5 text-xs font-semibold uppercase text-neutral-500">Kewajiban</td></tr>
                        <tr v-for="l in liabilities" :key="l.code"><td class="px-4 py-2"><span class="font-mono text-xs text-neutral-400">{{ l.code }}</span> {{ l.name }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.amount) }}</td></tr>
                        <tr class="bg-neutral-50/50 dark:bg-neutral-800/30"><td colspan="2" class="px-4 py-1.5 text-xs font-semibold uppercase text-neutral-500">Ekuitas</td></tr>
                        <tr v-for="e in equity" :key="e.code"><td class="px-4 py-2"><span class="font-mono text-xs text-neutral-400">{{ e.code }}</span> {{ e.name }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(e.amount) }}</td></tr>
                        <tr v-if="Math.abs(unclosed) > 0.5" class="text-amber-700 dark:text-amber-400"><td class="px-4 py-2 italic">Laba belum ditutup (sementara)</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(unclosed) }}</td></tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-primary-400 bg-neutral-50 font-bold dark:bg-neutral-800"><td class="px-4 py-2.5">TOTAL PASIVA</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.liab_equity) }}</td></tr></tfoot>
                </table>
            </div>
        </div>

        <div class="card flex items-center justify-between p-4" :class="totals.balanced ? 'ring-1 ring-success-200 dark:ring-success-900' : 'ring-1 ring-danger-200 dark:ring-danger-900'">
            <span class="flex items-center gap-2 font-semibold"><CheckCircle2 v-if="totals.balanced" class="h-5 w-5 text-success-600" /><AlertTriangle v-else class="h-5 w-5 text-danger-600" /> {{ totals.balanced ? 'Neraca seimbang (Aktiva = Pasiva)' : 'Neraca tidak seimbang — periksa jurnal' }}</span>
            <span class="font-mono text-sm">{{ fmtRp(totals.assets) }} = {{ fmtRp(totals.liab_equity) }}</span>
        </div>
    </div>
</template>
