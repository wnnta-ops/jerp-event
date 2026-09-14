<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, CreditCard, TrendingUp, TrendingDown, Wallet, ChevronRight } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

interface Alokasi { date: string | null; description: string | null; amount: number; bank: string | null }
interface Row {
    no: string; date: string | null; holder: string | null; purpose: string | null; card: string | null;
    penambahan: number; pemakaian: number; sisa: number; riwayat: Alokasi[];
}
const props = defineProps<{ rows: Row[] }>();

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

/* Filter per-kolom PERSIS seperti Kartu Bon Sementara — keempat kolom keterangannya memang
   kolom yang sama, jadi cara menyaringnya tidak boleh berbeda hanya karena layarnya lain. */
const fNo = ref<string[]>([]);
const fHolder = ref<string[]>([]);
const fPurpose = ref<string[]>([]);
const from = ref('');
const to = ref('');

const uniq = (arr: (string | null)[]) => [...new Set(arr.map((v) => v ?? ''))].sort((a, b) => a.localeCompare(b));
const noOptions = computed(() => uniq(props.rows.map((r) => r.no)));
const holderOptions = computed(() => uniq(props.rows.map((r) => r.holder)));
const purposeOptions = computed(() => uniq(props.rows.map((r) => r.purpose)));

const pass = (val: string, sel: string[]) => sel.length === 0 || sel.includes(val);
const filteredRows = computed(() => props.rows.filter((r) =>
    pass(r.no ?? '', fNo.value)
    && pass(r.holder ?? '', fHolder.value)
    && pass(r.purpose ?? '', fPurpose.value)
    && (!from.value || (r.date ?? '') >= from.value)
    && (!to.value || (r.date ?? '') <= to.value)));

const anyFilter = computed(() => fNo.value.length || fHolder.value.length || fPurpose.value.length || from.value || to.value);
function resetFilters() { fNo.value = []; fHolder.value = []; fPurpose.value = []; from.value = ''; to.value = ''; }

const totals = computed(() => ({
    penambahan: filteredRows.value.reduce((s, r) => s + Number(r.penambahan), 0),
    pemakaian: filteredRows.value.reduce((s, r) => s + Number(r.pemakaian), 0),
    sisa: filteredRows.value.reduce((s, r) => s + Number(r.sisa), 0),
    count: filteredRows.value.length,
}));

/* Riwayat dibuka per baris. Menampilkan semuanya sekaligus akan menenggelamkan daftar bonnya
   sendiri — yang dicari orang di sini lebih dulu adalah bon mana, baru rinciannya. */
const dibuka = ref<Record<string, boolean>>({});
function toggle(no: string) { dibuka.value[no] = ! dibuka.value[no]; }

const inputCls = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-primary-600"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><CreditCard class="h-6 w-6 text-primary-600" /> Saldo Debit Card</h1>
                <p class="mt-1 max-w-3xl text-xs text-neutral-500">
                    Saldo kartu debit / VA dipecah <b>per nomor bon</b>.
                    <b>Penambahan</b> = nominal pembayaran Debit VA yang tersimpan ·
                    <b>Pemakaian</b> = Alokasi Kasbon bon itu ·
                    <b>Sisa Saldo</b> = Penambahan − Pemakaian.
                    Klik barisnya untuk melihat riwayat alokasinya.
                </p>
            </div>
            <button v-if="anyFilter" type="button" @click="resetFilters" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Reset filter</button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900/40 dark:bg-emerald-900/10">
                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-300"><TrendingUp class="h-4 w-4" /> Penambahan</div>
                <div class="mt-1 font-mono text-xl font-bold text-emerald-600 dark:text-emerald-400">Rp {{ fmtRp(totals.penambahan) }}</div>
            </div>
            <div class="card border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900/40 dark:bg-rose-900/10">
                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-rose-700 dark:text-rose-300"><TrendingDown class="h-4 w-4" /> Pemakaian</div>
                <div class="mt-1 font-mono text-xl font-bold text-rose-600 dark:text-rose-400">Rp {{ fmtRp(totals.pemakaian) }}</div>
            </div>
            <div class="card border-primary-200 bg-primary-50/60 p-4 dark:border-primary-900/50 dark:bg-primary-900/10">
                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-primary-700 dark:text-primary-300"><Wallet class="h-4 w-4" /> Sisa Saldo</div>
                <div class="mt-1 font-mono text-xl font-bold text-primary-600 dark:text-primary-400">Rp {{ fmtRp(totals.sisa) }}</div>
                <div class="text-xs text-neutral-400">{{ totals.count }} bon</div>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No Bon</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Pemegang</th><th class="px-4 py-3">Keperluan</th><th class="px-4 py-3 text-right">Penambahan</th><th class="px-4 py-3 text-right">Pemakaian</th><th class="px-4 py-3 text-right">Sisa Saldo</th>
                    </tr>
                    <!-- Baris filter per-kolom. Tiga kolom nilai tidak difilter → tiga <th> kosong. -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-3 py-2"><ColumnFilter v-model="fNo" :options="noOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"><div class="flex flex-col gap-1"><input v-model="from" type="date" :class="inputCls" title="Dari tanggal" /><input v-model="to" type="date" :class="inputCls" title="Sampai tanggal" /></div></th>
                        <th class="px-3 py-2"><ColumnFilter v-model="fHolder" :options="holderOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"><ColumnFilter v-model="fPurpose" :options="purposeOptions" placeholder="Semua" /></th>
                        <th class="px-3 py-2"></th><th class="px-3 py-2"></th><th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="r in filteredRows" :key="r.no">
                        <tr class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="toggle(r.no)">
                            <td class="px-4 py-2.5 font-mono text-xs">
                                <ChevronRight class="mr-1 inline h-3.5 w-3.5 transition-transform" :class="dibuka[r.no] ? 'rotate-90' : ''" />{{ r.no }}
                            </td>
                            <td class="px-4 py-2.5">{{ fmtDate(r.date) }}</td>
                            <td class="px-4 py-2.5">{{ r.holder }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-emerald-600 dark:text-emerald-400">{{ fmtRp(r.penambahan) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-rose-600 dark:text-rose-400">{{ fmtRp(r.pemakaian) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold text-primary-600 dark:text-primary-400">{{ fmtRp(r.sisa) }}</td>
                        </tr>
                        <tr v-if="dibuka[r.no]" class="bg-neutral-50/60 dark:bg-neutral-800/30">
                            <td colspan="7" class="px-4 py-3">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">Riwayat Alokasi Kasbon — {{ r.no }}<span v-if="r.card" class="ml-2 font-normal normal-case text-neutral-400">Kartu: {{ r.card }}</span></div>
                                <table v-if="r.riwayat.length" class="w-full text-xs">
                                    <thead><tr class="text-left uppercase text-neutral-400"><th class="py-1 pr-3">Tanggal</th><th class="py-1 pr-3">Keterangan</th><th class="py-1 pr-3">Bank Pengeluaran</th><th class="py-1 text-right">Jumlah</th></tr></thead>
                                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                        <tr v-for="(a, i) in r.riwayat" :key="i">
                                            <td class="py-1.5 pr-3">{{ fmtDate(a.date) }}</td>
                                            <td class="py-1.5 pr-3">{{ a.description ?? '—' }}</td>
                                            <td class="py-1.5 pr-3 text-neutral-500">{{ a.bank ?? '—' }}</td>
                                            <td class="py-1.5 text-right font-mono">{{ fmtRp(a.amount) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot><tr class="font-semibold"><td colspan="3" class="pt-1.5">Total Pemakaian</td><td class="pt-1.5 text-right font-mono text-rose-600 dark:text-rose-400">{{ fmtRp(r.pemakaian) }}</td></tr></tfoot>
                                </table>
                                <!-- Bon yang sudah dibayar tetapi belum pernah dialokasikan: seluruh nominalnya
                                     masih duduk di kartu, dan itulah keterangan yang dicari di sini. -->
                                <p v-else class="text-xs text-neutral-400">Belum ada Alokasi Kasbon — seluruh Rp {{ fmtRp(r.penambahan) }} masih menjadi saldo kartu.</p>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800"><tr>
                    <td class="px-4 py-3" colspan="4">TOTAL ({{ totals.count }} bon)</td>
                    <td class="px-4 py-3 text-right font-mono text-emerald-600 dark:text-emerald-400">{{ fmtRp(totals.penambahan) }}</td>
                    <td class="px-4 py-3 text-right font-mono text-rose-600 dark:text-rose-400">{{ fmtRp(totals.pemakaian) }}</td>
                    <td class="px-4 py-3 text-right font-mono text-primary-600 dark:text-primary-400">{{ fmtRp(totals.sisa) }}</td>
                </tr></tfoot>
            </table>
            <EmptyState v-if="!filteredRows.length" :title="rows.length ? 'Tidak ada data sesuai filter' : 'Belum ada pembayaran Debit VA'" description="Saldo kartu terbentuk dari bon sementara yang dibayar lewat Debit VA." />
        </div>
    </div>
</template>
