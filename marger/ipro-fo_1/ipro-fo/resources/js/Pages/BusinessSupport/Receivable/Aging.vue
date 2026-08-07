<script setup lang="ts">
/**
 * Aging Piutang — hanya piutang yang SUDAH lewat jatuh tempo, dikelompokkan per umur.
 * Warna mengikuti tingkat keparahan: makin tua tunggakannya makin merah.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, TriangleAlert, Users } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = { name: string; d30: number; d60: number; d90: number; d90p: number; total: number; max_overdue: number };
const props = defineProps<{
    rows: Row[];
    totals: { d30: number; d60: number; d90: number; d90p: number; total: number };
    belumJt: { amount: number; count: number };
}>();

// Satu sumber kebenaran untuk urutan, label, dan warna tiap kelompok umur.
const BUCKETS = [
    { key: 'd30',  label: '1–30 hari',  short: '1–30',  bar: 'bg-amber-400',  text: 'text-amber-600 dark:text-amber-400',   ring: 'border-amber-400',  soft: 'bg-amber-50 dark:bg-amber-950/25' },
    { key: 'd60',  label: '31–60 hari', short: '31–60', bar: 'bg-orange-500', text: 'text-orange-600 dark:text-orange-400', ring: 'border-orange-500', soft: 'bg-orange-50 dark:bg-orange-950/25' },
    { key: 'd90',  label: '61–90 hari', short: '61–90', bar: 'bg-rose-500',   text: 'text-rose-600 dark:text-rose-400',     ring: 'border-rose-500',   soft: 'bg-rose-50 dark:bg-rose-950/25' },
    { key: 'd90p', label: '> 90 hari',  short: '> 90',  bar: 'bg-red-700',    text: 'text-red-700 dark:text-red-400',       ring: 'border-red-700',    soft: 'bg-red-50 dark:bg-red-950/30' },
] as const;

const fmtRp = (v: number) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
const val = (o: any, k: string) => Number(o?.[k]) || 0;
const pct = (v: number, base: number) => (base > 0 ? (Number(v) || 0) / base * 100 : 0);
const pctLabel = (v: number) => (props.totals.total > 0 ? pct(v, props.totals.total).toFixed(1).replace('.', ',') + '%' : '—');

// Lebar batang tiap baris diukur terhadap tunggakan TERBESAR, bukan total keseluruhan,
// supaya perbandingan antar customer tetap terbaca walau nilainya timpang.
const maxRow = computed(() => props.rows.reduce((m, r) => Math.max(m, Number(r.total) || 0), 0));
// Kelompok terparah yang dipunyai sebuah baris — dipakai untuk penanda di kolom customer.
const worst = (r: Row) => [...BUCKETS].reverse().find(b => val(r, b.key) > 0) ?? null;
const cellCls = (r: Row, k: string) => (val(r, k) > 0 ? 'font-medium' : 'text-neutral-300 dark:text-neutral-600');
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-success-600" /> Aging Piutang</h1>
            <p class="mt-0.5 text-sm text-neutral-500">Piutang yang <b>sudah lewat jatuh tempo</b>, dikelompokkan menurut umur tunggakannya. Makin tua, makin merah.</p>
        </div>

        <template v-if="rows.length">
            <!-- Ringkasan per kelompok umur -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="b in BUCKETS" :key="b.key" class="card border-l-4 p-4" :class="b.ring">
                    <div class="text-xs font-medium uppercase tracking-wide text-neutral-400">{{ b.label }}</div>
                    <div class="mt-1 font-mono text-lg font-bold tabular-nums" :class="b.text">{{ fmtRp(val(totals, b.key)) }}</div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                        <div class="h-full rounded-full transition-all duration-500" :class="b.bar" :style="{ width: pct(val(totals, b.key), totals.total) + '%' }"></div>
                    </div>
                    <div class="mt-1 text-[11px] text-neutral-400">{{ pctLabel(val(totals, b.key)) }} dari total tunggakan</div>
                </div>
            </div>

            <!-- Komposisi keseluruhan dalam satu batang -->
            <div class="card p-4">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-sm font-semibold">Komposisi Tunggakan</span>
                    <span class="font-mono text-lg font-bold tabular-nums">Rp {{ fmtRp(totals.total) }}</span>
                </div>
                <div class="flex h-3 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                    <div v-for="b in BUCKETS" :key="b.key" class="h-full transition-all duration-500" :class="b.bar"
                        :style="{ width: pct(val(totals, b.key), totals.total) + '%' }" :title="`${b.label}: Rp ${fmtRp(val(totals, b.key))}`"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                    <span v-for="b in BUCKETS" :key="b.key" class="inline-flex items-center gap-1.5 text-[11px] text-neutral-500">
                        <span class="h-2 w-2 rounded-full" :class="b.bar"></span>{{ b.label }} · {{ pctLabel(val(totals, b.key)) }}
                    </span>
                </div>
            </div>
        </template>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3 text-left">Customer</th>
                        <th v-for="b in BUCKETS" :key="b.key" class="px-4 py-3 text-right">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" :class="b.bar"></span>{{ b.short }}</span>
                        </th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="w-32 px-4 py-3 text-left">Porsi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i" class="transition-colors duration-150 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ r.name }}</div>
                            <div v-if="worst(r)" class="mt-0.5 inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[10px] font-semibold"
                                :class="[worst(r)!.soft, worst(r)!.text]">
                                telat {{ r.max_overdue }} hari
                            </div>
                        </td>
                        <td v-for="b in BUCKETS" :key="b.key" class="px-4 py-2.5 text-right font-mono tabular-nums"
                            :class="[cellCls(r, b.key), val(r, b.key) > 0 ? b.text : '']">
                            {{ val(r, b.key) > 0 ? fmtRp(val(r, b.key)) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold tabular-nums">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5">
                            <!-- Batang bertumpuk: sekali lihat terbaca besar tunggakan sekaligus komposisi umurnya. -->
                            <div class="flex h-2 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800"
                                :style="{ width: Math.max(pct(r.total, maxRow), 4) + '%' }">
                                <div v-for="b in BUCKETS" :key="b.key" class="h-full" :class="b.bar"
                                    :style="{ width: pct(val(r, b.key), r.total) + '%' }"></div>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t-2 border-neutral-200 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <td class="px-4 py-3">Total · {{ rows.length }} customer</td>
                        <td v-for="b in BUCKETS" :key="b.key" class="px-4 py-3 text-right font-mono tabular-nums" :class="b.text">{{ fmtRp(val(totals, b.key)) }}</td>
                        <td class="px-4 py-3 text-right font-mono tabular-nums">{{ fmtRp(totals.total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada piutang yang lewat jatuh tempo" description="Semua invoice piutang lunas atau belum jatuh tempo." />
        </div>

        <p v-if="belumJt.count" class="flex items-start gap-2 rounded-xl bg-neutral-50 px-4 py-3 text-[11px] text-neutral-500 ring-1 ring-neutral-200 dark:bg-neutral-800/50 dark:ring-neutral-700">
            <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0 text-neutral-400" />
            <span>
                <b>{{ belumJt.count }} invoice</b> senilai <b class="font-mono">Rp {{ fmtRp(belumJt.amount) }}</b> belum jatuh tempo, jadi tidak ikut dihitung di sini —
                aging hanya memuat tunggakan yang sudah lewat tanggal jatuh temponya. Saldo piutang seluruhnya bisa dilihat di <b>Kartu Piutang</b>.
            </span>
        </p>
        <p v-else-if="rows.length" class="flex items-center gap-1.5 text-[11px] text-neutral-400">
            <Users class="h-3.5 w-3.5" /> Diurutkan dari tunggakan terbesar.
        </p>
    </div>
</template>
