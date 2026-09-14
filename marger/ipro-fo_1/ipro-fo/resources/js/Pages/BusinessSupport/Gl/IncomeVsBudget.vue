<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, FileSpreadsheet } from 'lucide-vue-next';
import AnalisaKenaikan from '@/Components/UI/AnalisaKenaikan.vue';

type Row = {
    kind: string; label: string;
    realisasi: number | null; budget: number | null; variance: number | null;
    realisasi_pct: number | null; budget_pct: number | null;
};
const props = defineProps<{
    company: string;
    year: number;
    as_of: string;
    periode: string;
    date_from: string;
    date_to: string;
    budget_note: string;
    rows: Row[];
    totals: { realisasi: number; budget: number; variance: number };
    has_budget: boolean;
    analisa: any;
}>();

const dari = ref(props.date_from);
const sampai = ref(props.date_to);
function display() { router.get(route('bs.gl.lr-budget'), { date_from: dari.value, date_to: sampai.value }, { preserveState: true, replace: true, preserveScroll: true }); }
function excel() { window.location.href = route('bs.gl.lr-budget.export', { date_from: dari.value, date_to: sampai.value }); }

function fmt(v: number | null) {
    if (v === null || v === undefined) return '';
    const n = Number(v);
    const s = Math.abs(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `(${s})` : s;
}
function pct(v: number | null) { return (v === null || v === undefined) ? '' : `${Number(v).toFixed(1)}%`; }
const neg = (v: number | null) => (v ?? 0) < 0;

// Kelas baris per jenis + warna khusus untuk baris penegasan.
function rowCls(r: Row) {
    if (r.kind === 'header') return 'bg-neutral-50 font-bold uppercase text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200';
    if (r.kind === 'group') return 'text-xs font-semibold uppercase text-neutral-500';
    if (r.kind === 'subtotal') return 'border-t border-neutral-200 font-semibold dark:border-neutral-700';
    if (r.kind === 'subtotal2') return 'border-t-2 border-neutral-300 font-bold dark:border-neutral-600';
    if (r.kind === 'emphasis') return r.label.includes('GROSS') ? 'bg-amber-50 font-bold text-amber-700 dark:bg-amber-900/20' : 'bg-sky-50 font-bold text-sky-700 dark:bg-sky-900/20';
    if (r.kind === 'total') return (r.realisasi ?? 0) >= 0 ? 'bg-emerald-600 font-bold text-white' : 'bg-danger-600 font-bold text-white';
    return '';
}
const nameCls = (r: Row) => r.kind === 'line' ? 'pl-6' : (r.kind === 'group' ? 'pl-3' : 'pl-2');
const showVals = (r: Row) => r.kind !== 'header' && r.kind !== 'group';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Laba Rugi vs Budget</h1>
                <p class="mt-0.5 text-sm text-neutral-500">{{ company }} &middot; Periode {{ periode }}</p>
            </div>
            <div class="flex items-end gap-2">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Dari Tanggal</label>
                    <input v-model="dari" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Sampai Tanggal</label>
                    <input v-model="sampai" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <button @click="display" class="h-9 rounded-lg bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700">Display</button>
                <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
            </div>
        </div>

        <div v-if="!has_budget" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
            Belum ada data <b>Budget</b> untuk tahun {{ year }} — kolom Budget menampilkan 0 dan Variance = Realisasi. Input budget di modul <b>Budget Control</b>.
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead>
                    <tr class="text-xs uppercase">
                        <th rowspan="2" class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-left align-bottom text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800">Nama Akun</th>
                        <th colspan="2" class="border-b border-l border-neutral-200 bg-rose-100 px-4 py-2 text-center text-rose-800 dark:border-neutral-700 dark:bg-rose-900/30 dark:text-rose-200">Realisasi {{ year }}</th>
                        <th colspan="2" class="border-b border-l border-neutral-200 bg-amber-100 px-4 py-2 text-center text-amber-800 dark:border-neutral-700 dark:bg-amber-900/30 dark:text-amber-200">Budget {{ year }}</th>
                        <th rowspan="2" class="border-b border-l border-neutral-200 bg-emerald-100 px-4 py-2 text-right align-bottom text-emerald-800 dark:border-neutral-700 dark:bg-emerald-900/30 dark:text-emerald-200">Variance {{ year }}</th>
                    </tr>
                    <tr class="text-[10px] uppercase text-neutral-500">
                        <th class="border-b border-l border-neutral-200 bg-rose-50 px-4 py-1 text-right dark:border-neutral-700 dark:bg-rose-900/10">Nilai</th>
                        <th class="border-b border-neutral-200 bg-rose-50 px-2 py-1 text-right dark:border-neutral-700 dark:bg-rose-900/10">%</th>
                        <th class="border-b border-l border-neutral-200 bg-amber-50 px-4 py-1 text-right dark:border-neutral-700 dark:bg-amber-900/10">Nilai</th>
                        <th class="border-b border-neutral-200 bg-amber-50 px-2 py-1 text-right dark:border-neutral-700 dark:bg-amber-900/10">%</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i" :class="rowCls(r)">
                        <td class="px-4 py-1.5" :class="nameCls(r)">{{ r.label }}</td>
                        <template v-if="showVals(r)">
                            <td class="border-l border-neutral-100 px-4 py-1.5 text-right font-mono tabular-nums dark:border-neutral-800" :class="neg(r.realisasi) && r.kind !== 'total' ? 'text-danger-600' : ''">{{ fmt(r.realisasi) }}</td>
                            <td class="px-2 py-1.5 text-right font-mono text-xs" :class="r.kind === 'total' ? '' : 'text-neutral-400'">{{ pct(r.realisasi_pct) }}</td>
                            <td class="border-l border-neutral-100 px-4 py-1.5 text-right font-mono tabular-nums dark:border-neutral-800" :class="neg(r.budget) && r.kind !== 'total' ? 'text-danger-600' : ''">{{ fmt(r.budget) }}</td>
                            <td class="px-2 py-1.5 text-right font-mono text-xs" :class="r.kind === 'total' ? '' : 'text-neutral-400'">{{ pct(r.budget_pct) }}</td>
                            <td class="border-l border-neutral-100 px-4 py-1.5 text-right font-mono font-medium tabular-nums dark:border-neutral-800" :class="r.kind === 'total' ? '' : (neg(r.variance) ? 'text-danger-600' : 'text-emerald-600')">{{ fmt(r.variance) }}</td>
                        </template>
                        <template v-else><td colspan="5"></td></template>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-neutral-400">
            Variance = Realisasi − Budget. % = terhadap Jumlah Pendapatan Usaha (common-size) per kolom.
            Budget diambil dari akun P/L pada modul Budget Control (status ≠ ditolak).
            <!-- Prorata disebutkan terus terang: tanpa itu, realisasi satu triwulan yang
                 dibandingkan dengan anggaran setahun akan selalu terlihat "hemat". -->
            <span class="font-medium text-neutral-500 dark:text-neutral-400">{{ budget_note }}</span>
        </p>

        <!-- Di sini "kenaikan" berarti selisih terhadap ANGGARAN — laporan ini memang sudah
             punya pembandingnya sendiri, jadi tidak perlu periode pembanding. -->
        <AnalisaKenaikan v-if="has_budget" :analisa="analisa" judul="Analisa Selisih terhadap Anggaran" mode="budget" />
    </div>
</template>
