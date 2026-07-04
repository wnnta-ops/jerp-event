<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Table2 } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    year: number;
    periods: Array<{ key: string; label: string }>;
    groups: Array<any>;
    grand: { cost: number; cells: Array<{ expense: number; accum: number; book: number }> };
}>();

const year = ref(props.year);
function reload() { router.get(route('bs.fa.summary'), { year: year.value }, { preserveState: true, replace: true }); }
function n(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function life(m: number) { return m > 0 ? (m % 12 === 0 ? (m / 12) + ' Tahun' : m + ' Bln') : '—'; }
const th = 'px-2 py-2 text-[11px] font-semibold uppercase text-neutral-500 whitespace-nowrap';
const td = 'px-2 py-1.5 whitespace-nowrap';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Aset Tetap</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Table2 class="h-6 w-6 text-primary-600" /> Summary Aset — Jadwal Penyusutan</h1>
                <p class="text-sm text-neutral-500">Penyusutan garis lurus per kategori: tahunan (lampau) + bulanan {{ year }}. Nilai buku negatif = telah lewat masa manfaat.</p>
            </div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun (detail bulanan)</label><input v-model.number="year" @change="reload" type="number" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
        </div>

        <div v-if="groups.length" class="card overflow-x-auto p-0">
            <table class="min-w-max border-collapse text-xs">
                <thead>
                    <tr class="border-b border-neutral-200 bg-neutral-100 dark:border-neutral-700 dark:bg-neutral-800">
                        <th :class="th" rowspan="2">No</th>
                        <th :class="[th, 'text-left']" rowspan="2">Nama Aset</th>
                        <th :class="th" rowspan="2">Jumlah</th>
                        <th :class="[th, 'text-left']" rowspan="2">Nomer</th>
                        <th :class="th" rowspan="2">Tanggal</th>
                        <th :class="[th, 'text-right']" rowspan="2">Harga</th>
                        <th :class="th" rowspan="2">Masa Manfaat</th>
                        <th :class="th" rowspan="2">PIC</th>
                        <th :class="th" rowspan="2">Lokasi</th>
                        <th :class="[th, 'text-right']" rowspan="2">Peny./Bulan</th>
                        <th v-for="p in periods" :key="p.key" :class="[th, 'border-l border-neutral-300 text-center dark:border-neutral-600']" colspan="4">{{ p.label }}</th>
                    </tr>
                    <tr class="border-b border-neutral-200 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800/60">
                        <template v-for="p in periods" :key="p.key">
                            <th :class="[th, 'border-l border-neutral-300 text-right dark:border-neutral-600']">Jml Bln</th>
                            <th :class="[th, 'text-right']">Beban</th>
                            <th :class="[th, 'text-right']">Akumulasi</th>
                            <th :class="[th, 'text-right']">Nilai Buku</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <template v-for="g in groups" :key="g.category">
                        <tr class="bg-primary-50 font-bold text-primary-800 dark:bg-primary-900/20 dark:text-primary-200">
                            <td :class="td" :colspan="10 + periods.length * 4">{{ g.label }}</td>
                        </tr>
                        <tr v-for="a in g.assets" :key="a.no" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                            <td :class="[td, 'text-center']">{{ a.no }}</td>
                            <td :class="[td, 'max-w-[240px] truncate font-medium']" :title="a.name">{{ a.name }}</td>
                            <td :class="[td, 'text-center']">{{ a.quantity ?? '—' }}</td>
                            <td :class="[td, 'font-mono text-[10px]']">{{ a.asset_number }}</td>
                            <td :class="td">{{ fmtDate(a.acquisition_date) }}</td>
                            <td :class="[td, 'text-right font-mono']">{{ n(a.acquisition_cost) }}</td>
                            <td :class="[td, 'text-center']">{{ life(a.useful_life_months) }}</td>
                            <td :class="td">{{ a.pic ?? '—' }}</td>
                            <td :class="td">{{ a.location ?? '—' }}</td>
                            <td :class="[td, 'text-right font-mono']">{{ n(a.monthly) }}</td>
                            <template v-for="(c, i) in a.cells" :key="i">
                                <td :class="[td, 'border-l border-neutral-200 text-right text-neutral-400 dark:border-neutral-700']">{{ c.months || '' }}</td>
                                <td :class="[td, 'text-right font-mono']">{{ c.expense ? n(c.expense) : '' }}</td>
                                <td :class="[td, 'text-right font-mono text-neutral-500']">{{ c.accum ? n(c.accum) : '' }}</td>
                                <td :class="[td, 'text-right font-mono font-semibold', c.book < 0 ? 'text-danger-600' : '']">{{ n(c.book) }}</td>
                            </template>
                        </tr>
                        <tr class="border-y border-neutral-200 bg-neutral-100 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                            <td :class="td" colspan="5">SUB TOTAL {{ g.label }}</td>
                            <td :class="[td, 'text-right font-mono']">{{ n(g.subtotal.cost) }}</td>
                            <td :class="td" colspan="3"></td>
                            <td :class="[td, 'text-right font-mono']">{{ n(g.subtotal.monthly) }}</td>
                            <template v-for="(c, i) in g.subtotal.cells" :key="i">
                                <td :class="[td, 'border-l border-neutral-200 dark:border-neutral-700']"></td>
                                <td :class="[td, 'text-right font-mono']">{{ c.expense ? n(c.expense) : '' }}</td>
                                <td :class="[td, 'text-right font-mono']">{{ c.accum ? n(c.accum) : '' }}</td>
                                <td :class="[td, 'text-right font-mono']">{{ n(c.book) }}</td>
                            </template>
                        </tr>
                    </template>
                    <tr class="border-t-2 border-primary-300 bg-primary-100 font-bold dark:border-primary-800 dark:bg-primary-900/30">
                        <td :class="td" colspan="5">GRAND TOTAL</td>
                        <td :class="[td, 'text-right font-mono']">{{ n(grand.cost) }}</td>
                        <td :class="td" colspan="4"></td>
                        <template v-for="(c, i) in grand.cells" :key="i">
                            <td :class="[td, 'border-l border-primary-200 dark:border-primary-800']"></td>
                            <td :class="[td, 'text-right font-mono']">{{ c.expense ? n(c.expense) : '' }}</td>
                            <td :class="[td, 'text-right font-mono']">{{ c.accum ? n(c.accum) : '' }}</td>
                            <td :class="[td, 'text-right font-mono']">{{ n(c.book) }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-else title="Belum ada aset tetap" description="Daftarkan aset di Registrasi Aset untuk menampilkan jadwal penyusutan." />
    </div>
</template>
