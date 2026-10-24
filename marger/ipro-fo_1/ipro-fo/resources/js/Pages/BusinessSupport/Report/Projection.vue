<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, TrendingUp, CalendarRange, FileSpreadsheet, Printer } from 'lucide-vue-next';
import PilihPosisiHutang from '@/Components/UI/PilihPosisiHutang.vue';

const props = defineProps<{ mode: string; view?: string; buckets: Array<{ key: string; label: string }>; rows: any[]; totals: any; filters: { from: string; to: string; posisi?: string }; periodItems: any[]; periodTotal: number; posisiOpsi?: Array<{ nilai: string; label: string }> }>();
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const view = ref(props.view === 'summary' ? 'summary' : 'detail');
const posisi = ref(props.filters.posisi ?? 'semua');
function go(mode: string) {
    // Posisi hutang tidak dibawa ke cabang piutang: di sana sumbernya BsArInvoice, yang tidak
    // punya kategori invoice AP sama sekali.
    const p = mode === 'hutang' ? { posisi: posisi.value } : {};
    router.get(route('bs.projection'), { mode, from: from.value || undefined, to: to.value || undefined, ...p }, { preserveState: true, replace: true });
}
function applyRange() { go(props.mode); }
function fmt(v: number) { return v ? Number(v).toLocaleString('id-ID') : ''; }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const isAp = props.mode === 'hutang';
// Unduhan = isi layar: mode, posisi, Detail/Summary, rentang periode yang SUDAH dimuat.
const paramEkspor = computed(() => ({ mode: props.mode, view: view.value, from: props.filters.from, to: props.filters.to, ...(isAp ? { posisi: props.filters.posisi ?? 'semua' } : {}) }));
</script>
<template>
    <div class="space-y-4">
        <h1 class="flex items-center gap-2 text-2xl font-bold"><TrendingUp class="h-6 w-6 text-primary-600" /> Proyeksi {{ isAp ? 'Hutang' : 'Piutang' }}</h1>

        <div class="flex flex-wrap gap-2">
            <button @click="go('hutang')" class="rounded-lg border px-3 py-1.5 text-sm font-medium" :class="mode === 'hutang' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'">Proyeksi Hutang (AP)</button>
            <button @click="go('piutang')" class="rounded-lg border px-3 py-1.5 text-sm font-medium" :class="mode === 'piutang' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'">Proyeksi Piutang (AR)</button>
            <PilihPosisiHutang v-if="isAp" v-model="posisi" :opsi="props.posisiOpsi" label="" @ubah="applyRange" />
            <a :href="route('bs.projection.excel', paramEkspor)" class="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
            <a :href="route('bs.projection.pdf', paramEkspor)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 bg-danger-50 px-3 py-1.5 text-sm font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-4 w-4" /> PDF</a>
            <div class="flex gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
                <button @click="view = 'detail'" class="rounded px-3 py-1" :class="view === 'detail' ? 'bg-primary-600 text-white' : ''">Detail</button>
                <button @click="view = 'summary'" class="rounded px-3 py-1" :class="view === 'summary' ? 'bg-primary-600 text-white' : ''">Summary</button>
            </div>
        </div>

        <!-- SUMMARY: rekap per party (total saja) -->
        <div v-if="view === 'summary'" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Rekap {{ isAp ? 'Hutang per Supplier' : 'Piutang per Customer' }}</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">{{ isAp ? 'Supplier' : 'Customer' }}</th><th class="px-4 py-2.5 text-right text-danger-500">Jatuh Tempo</th><th class="px-4 py-2.5 text-right">Total</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="px-4 py-1.5 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-1.5 text-right font-mono" :class="r.overdue ? 'text-danger-600' : ''">{{ fmt(r.overdue) }}</td>
                        <td class="px-4 py-1.5 text-right font-mono font-semibold">{{ fmt(r.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-600 dark:bg-neutral-800"><tr><td class="px-4 py-2.5">TOTAL</td><td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmt(totals.overdue) }}</td><td class="px-4 py-2.5 text-right font-mono text-primary-700">{{ fmt(totals.total) }}</td></tr></tfoot>
            </table>
            <p v-if="!rows.length" class="py-8 text-center text-sm text-neutral-400">Tidak ada {{ isAp ? 'hutang' : 'piutang' }} terbuka.</p>
        </div>

        <div v-if="view === 'detail'" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">{{ isAp ? 'Jadwal Pembayaran Hutang per Supplier' : 'Jadwal Pelunasan Piutang per Customer' }} — Jatuh Tempo + 12 bulan</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="sticky left-0 bg-neutral-50 px-4 py-2.5 dark:bg-neutral-800">{{ isAp ? 'Supplier' : 'Customer' }}</th>
                        <th v-for="b in buckets" :key="b.key" class="px-3 py-2.5 text-right" :class="b.key === 'overdue' ? 'text-danger-500' : ''">{{ b.label }}</th>
                        <th class="px-4 py-2.5 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i">
                        <td class="sticky left-0 bg-white px-4 py-1.5 font-medium dark:bg-neutral-900">{{ r.name }}</td>
                        <td v-for="b in buckets" :key="b.key" class="px-3 py-1.5 text-right font-mono" :class="b.key === 'overdue' && r[b.key] ? 'text-danger-600' : ''">{{ fmt(r[b.key]) }}</td>
                        <td class="px-4 py-1.5 text-right font-mono font-semibold">{{ fmt(r.total) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-600 dark:bg-neutral-800">
                    <tr>
                        <td class="sticky left-0 bg-neutral-50 px-4 py-2.5 dark:bg-neutral-800">TOTAL</td>
                        <td v-for="b in buckets" :key="b.key" class="px-3 py-2.5 text-right font-mono" :class="b.key === 'overdue' ? 'text-danger-600' : ''">{{ fmt(totals[b.key]) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-primary-700">{{ fmt(totals.total) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p v-if="!rows.length" class="py-8 text-center text-sm text-neutral-400">Tidak ada {{ isAp ? 'hutang' : 'piutang' }} terbuka.</p>
        </div>

        <!-- #131: proyeksi item per rentang tanggal yang dipilih -->
        <div v-if="view === 'detail'" class="card overflow-x-auto">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><CalendarRange class="h-4 w-4 text-primary-600" /> Item {{ isAp ? 'Hutang' : 'Piutang' }} Jatuh Tempo per Periode</div>
                <div class="flex items-center gap-2 text-sm">
                    <input v-model="from" type="date" @change="applyRange" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                    <span class="text-neutral-400">s/d</span>
                    <input v-model="to" type="date" @change="applyRange" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">Jatuh Tempo</th><th class="px-4 py-2.5">{{ isAp ? 'Supplier' : 'Customer' }}</th><th class="px-4 py-2.5">No. Invoice</th><th class="px-4 py-2.5 text-right">Nominal</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(it, i) in periodItems" :key="i">
                        <td class="px-4 py-1.5" :class="it.overdue ? 'font-semibold text-danger-600' : ''">{{ fmtDate(it.due) }}<span v-if="it.overdue" class="ml-1 text-[10px]">(lewat)</span></td>
                        <td class="px-4 py-1.5">{{ it.party }}</td>
                        <td class="px-4 py-1.5 font-mono text-xs">{{ it.ref }}</td>
                        <td class="px-4 py-1.5 text-right font-mono">{{ fmt(it.amount) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-600 dark:bg-neutral-800"><tr>
                    <td colspan="3" class="px-4 py-2.5 text-right">Total Periode ({{ fmtDate(filters.from) }} — {{ fmtDate(filters.to) }})</td>
                    <td class="px-4 py-2.5 text-right font-mono text-primary-700">{{ fmt(periodTotal) }}</td>
                </tr></tfoot>
            </table>
            <p v-if="!periodItems.length" class="py-8 text-center text-sm text-neutral-400">Tidak ada {{ isAp ? 'hutang' : 'piutang' }} jatuh tempo pada periode ini.</p>
        </div>

        <p class="text-xs text-neutral-400">Kolom <b>Jatuh Tempo</b> = sudah lewat tanggal (overdue). Bulan ke depan = proyeksi arus kas {{ isAp ? 'keluar' : 'masuk' }}. Sumber: {{ isAp ? 'invoice hutang terbuka' : 'jadwal angsuran / invoice piutang terbuka' }}.</p>
    </div>
</template>
