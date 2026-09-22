<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeft, BookText, FileSpreadsheet, Printer } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import PilihPosisiHutang from '@/Components/UI/PilihPosisiHutang.vue';

const props = defineProps<{ view?: string; suppliers: Array<any>; supplierId: number | null; dateFrom?: string | null; dateTo?: string | null; entries: Array<any>; openingBalance?: number; summary: Array<any>; outstanding: number; posisi?: string; posisiOpsi?: Array<{ nilai: string; label: string }> }>();
const selected = ref<any>(props.supplierId ?? '');
const dateFrom = ref(props.dateFrom ?? '');
const dateTo = ref(props.dateTo ?? '');
const posisi = ref(props.posisi ?? 'semua');
// #2: reaktif terhadap prop (preserveState membuat const biasa basi → toggle Summary tak berfungsi).
const mode = computed(() => (props.view === 'summary' ? 'summary' : 'detail'));

function go(view: string) {
    router.get(route('bs.ap.card'), { view, supplier_id: selected.value || undefined, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined, posisi: posisi.value }, { preserveState: true, replace: true });
}
function display() { go(mode.value); }
function switchMode(m: string) { go(m); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }

// Parameter ekspor DISAMAKAN dengan yang dipakai layar (go()). Kalau berbeda, berkasnya
// berisi periode atau supplier lain daripada yang sedang dilihat — sama-sama masuk akal
// dilihat sendiri, dan tidak ada yang menyadarinya sampai dua angka dibandingkan.
//
// Nilai yang sedang DIKETIK di kotak filter sengaja tidak dipakai: layar baru berubah
// setelah Tampilkan ditekan, jadi ekspor mengikuti apa yang benar-benar tampil.
const paramEkspor = computed(() => ({
    view: mode.value,
    supplier_id: props.supplierId || undefined,
    date_from: props.dateFrom || undefined,
    date_to: props.dateTo || undefined,
    posisi: props.posisi ?? 'semua',
}));
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// #2: total ringkasan (grand total seluruh supplier yang ditampilkan).
const summaryTotals = computed(() => {
    const list = props.summary ?? [];
    return {
        invoiced: list.reduce((s: number, r: any) => s + Number(r.invoiced || 0), 0),
        paid: list.reduce((s: number, r: any) => s + Number(r.paid || 0), 0),
        outstanding: list.reduce((s: number, r: any) => s + Number(r.outstanding || 0), 0),
    };
});
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.section', { section: 'query' })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Query — Account Payable</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-danger-600" /> Kartu Hutang</h1>
            <div class="flex gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
                <button @click="switchMode('detail')" class="rounded px-3 py-1" :class="mode === 'detail' ? 'bg-primary-600 text-white' : ''">Detail</button>
                <button @click="switchMode('summary')" class="rounded px-3 py-1" :class="mode === 'summary' ? 'bg-primary-600 text-white' : ''">Summary</button>
            </div>
        </div>
        <p class="text-xs text-neutral-500"><b>Tagihan</b> &amp; <b>Bayar</b> ditampilkan NET (setelah PPN &amp; PPh). Saldo Hutang (Detail) &amp; Outstanding (Summary) memakai basis &amp; cutoff yang sama, jadi nilainya konsisten.</p>

        <!-- Filter (tersedia di kedua mode) -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[240px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier <span v-if="mode === 'summary'" class="text-neutral-400">(opsional — kosong = semua)</span></label>
                <SearchSelect v-model="selected" :options="suppliers" placeholder="— pilih supplier —" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label>
                <input v-model="dateFrom" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label>
                <input v-model="dateTo" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <PilihPosisiHutang v-model="posisi" :opsi="props.posisiOpsi" />
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <a :href="route('bs.ap.card.export-excel', paramEkspor)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
            <a :href="route('bs.ap.card.export-pdf', paramEkspor)" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-danger-300 bg-danger-50 px-3 text-sm font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-4 w-4" /> PDF</a>
            <div v-if="mode === 'summary'" class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Total Outstanding</div><div class="font-mono text-lg font-bold text-danger-600">Rp {{ fmtRp(summaryTotals.outstanding) }}</div></div>
            <div v-else-if="supplierId" class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Saldo Hutang</div><div class="font-mono text-lg font-bold text-danger-600">Rp {{ fmtRp(outstanding) }}</div></div>
        </div>

        <!-- SUMMARY: rekap per supplier -->
        <div v-if="mode === 'summary'" class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Supplier</th><th class="px-4 py-3 text-right">Tertagih</th><th class="px-4 py-3 text-right">Terbayar</th><th class="px-4 py-3 text-right">Outstanding</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(s, i) in summary" :key="i">
                        <td class="px-4 py-2.5 font-medium">{{ s.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(s.invoiced) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(s.paid) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold text-danger-600">{{ fmtRp(s.outstanding) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="summary?.length" class="border-t-2 border-neutral-300 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                    <tr class="font-bold">
                        <td class="px-4 py-3">TOTAL <span class="ml-1 text-xs font-normal text-neutral-400">({{ summary.length }} supplier)</span></td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(summaryTotals.invoiced) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(summaryTotals.paid) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-danger-600">{{ fmtRp(summaryTotals.outstanding) }}</td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!summary?.length" title="Tidak ada hutang outstanding" description="Tidak ada supplier dengan saldo hutang berjalan pada filter ini." />
        </div>

        <!-- DETAIL: kartu per supplier -->
        <template v-else>
            <div v-if="supplierId" class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Bayar</th><th class="px-4 py-3 text-right">Tagihan</th><th class="px-4 py-3 text-right">Saldo</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <!-- Saldo Awal = posisi hutang NET sebelum 'Dari Tanggal' -->
                        <tr v-if="entries?.length || openingBalance" class="bg-primary-50/50 dark:bg-neutral-800/40">
                            <td class="px-4 py-2.5">{{ dateFrom ? fmtDate(dateFrom) : '' }}</td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5 font-medium text-neutral-600 dark:text-neutral-300">Saldo Awal</td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(openingBalance ?? 0) }}</td>
                        </tr>
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
                <EmptyState v-if="!entries?.length && !openingBalance" title="Belum ada transaksi" description="Supplier ini belum memiliki invoice hutang pada periode ini." />
            </div>
            <EmptyState v-else title="Pilih supplier" description="Pilih supplier untuk melihat kartu hutangnya, atau beralih ke Summary untuk rekap semua supplier." />
        </template>
    </div>
</template>
