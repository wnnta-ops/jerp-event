<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ReceiptText, ArrowUpCircle, ArrowDownCircle, Scale, ChevronRight, Landmark, Printer, BookText, Gauge } from 'lucide-vue-next';

const props = defineProps<{
    period: { year: number; month: number };
    keluaran: { dpp: number; ppn: number; jurnal_ppn: number; entry_ppn: number };
    masukan: { dpp: number; ppn: number };
    selisih: number;
    gl: { keluaran: number; masukan: number };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.rekap'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const kurangBayar = props.selisih >= 0;
// GL akun PPN = superset (termasuk PPN penjualan properti, saldo awal). Selisih = sumber di luar faktur AR/AP.
const gapKel = Math.round((props.gl.keluaran - props.keluaran.ppn) * 100) / 100;
const gapMas = Math.round((props.gl.masukan - props.masukan.ppn) * 100) / 100;
</script>
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-primary-600" /> Faktur Pajak <span class="text-base font-normal text-neutral-400">(Rekap PPN — SPT Masa)</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.tax.dashboard', { year: period.year })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Gauge class="h-4 w-4" /> Dashboard</Link>
                <Link :href="route('bs.tax.pph', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Landmark class="h-4 w-4" /> Rekap PPh</Link>
                <a :href="route('bs.tax.cetak.ppn', { year: period.year, month: period.month })" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Printer class="h-4 w-4" /> SPT PPN</a>
                <a :href="route('bs.tax.cetak.buku', { year: period.year, month: period.month })" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Buku Pembantu</a>
                <select v-model.number="month" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select>
                <select v-model.number="year" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Masa Pajak <b>{{ months[period.month-1] }} {{ period.year }}</b> — PPN Keluaran (penjualan) dikurangi PPN Masukan (pembelian) menentukan kurang/lebih bayar.</p>

        <div class="grid gap-3 md:grid-cols-3">
            <Link :href="route('bs.tax.keluaran', { year: period.year, month: period.month })" class="card p-5 transition hover:ring-2 hover:ring-success-300">
                <div class="flex items-center justify-between"><div class="flex items-center gap-2 text-sm font-semibold text-success-700 dark:text-success-300"><ArrowUpCircle class="h-5 w-5" /> PPN Keluaran</div><ChevronRight class="h-4 w-4 text-neutral-300" /></div>
                <div class="mt-2 text-2xl font-bold text-success-600">{{ fmtRp(keluaran.ppn) }}</div>
                <div class="mt-1 text-xs text-neutral-400">DPP {{ fmtRp(keluaran.dpp) }}</div>
                <div class="mt-1 text-[11px] text-neutral-400">Mutasi jurnal {{ fmtRp(keluaran.jurnal_ppn) }} · Entri approval {{ fmtRp(keluaran.entry_ppn) }}</div>
            </Link>
            <Link :href="route('bs.tax.masukan', { year: period.year, month: period.month })" class="card p-5 transition hover:ring-2 hover:ring-danger-300">
                <div class="flex items-center justify-between"><div class="flex items-center gap-2 text-sm font-semibold text-danger-700 dark:text-danger-300"><ArrowDownCircle class="h-5 w-5" /> PPN Masukan</div><ChevronRight class="h-4 w-4 text-neutral-300" /></div>
                <div class="mt-2 text-2xl font-bold text-danger-600">{{ fmtRp(masukan.ppn) }}</div>
                <div class="mt-1 text-xs text-neutral-400">DPP {{ fmtRp(masukan.dpp) }}</div>
            </Link>
            <div class="card p-5" :class="kurangBayar ? 'ring-1 ring-amber-300 dark:ring-amber-800' : 'ring-1 ring-success-300 dark:ring-success-800'">
                <div class="flex items-center gap-2 text-sm font-semibold"><Scale class="h-5 w-5" :class="kurangBayar ? 'text-amber-600' : 'text-success-600'" /> PPN {{ kurangBayar ? 'Kurang Bayar' : 'Lebih Bayar' }}</div>
                <div class="mt-2 text-2xl font-bold" :class="kurangBayar ? 'text-amber-600' : 'text-success-600'">{{ fmtRp(Math.abs(selisih)) }}</div>
                <div class="mt-1 text-xs text-neutral-400">{{ kurangBayar ? 'Disetor ke kas negara' : 'Dapat dikompensasi masa berikutnya' }}</div>
            </div>
        </div>

        <!-- Cross-check GL -->
        <div class="card p-5 text-sm">
            <h2 class="mb-2 font-semibold">Cross-check Buku Besar (mutasi akun PPN, jurnal posted)</h2>
            <table class="w-full">
                <thead><tr class="text-left text-xs uppercase text-neutral-400"><th class="py-1">Akun PPN</th><th class="py-1 text-right">Saldo GL (semua sumber)</th><th class="py-1 text-right">Dari faktur AR/AP</th><th class="py-1 text-right">Selisih (sumber lain)</th></tr></thead>
                <tbody>
                    <tr class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1.5">PPN Keluaran</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(gl.keluaran) }}</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(keluaran.ppn) }}</td>
                        <td class="py-1.5 text-right font-mono" :class="Math.abs(gapKel) < 1 ? 'text-success-600' : 'text-neutral-400'">{{ Math.abs(gapKel) < 1 ? '— ✓' : fmtRp(gapKel) }}</td>
                    </tr>
                    <tr class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1.5">PPN Masukan</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(gl.masukan) }}</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(masukan.ppn) }}</td>
                        <td class="py-1.5 text-right font-mono" :class="Math.abs(gapMas) < 1 ? 'text-success-600' : 'text-neutral-400'">{{ Math.abs(gapMas) < 1 ? '— ✓' : fmtRp(gapMas) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-xs text-neutral-400">Saldo akun PPN di GL adalah <b>superset</b> — mencakup PPN dari semua modul (mis. penjualan properti/kontrak &amp; saldo awal). Rekap di sini hanya faktur AR/AP. Selisih = PPN dari sumber lain di luar AR/AP.</p>
        </div>
    </div>
</template>
