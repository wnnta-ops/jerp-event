<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart, FileSpreadsheet } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import PilihPosisiHutang from '@/Components/UI/PilihPosisiHutang.vue';

const props = defineProps<{ rows: Array<any>; suppliers: Array<any>; filters: any; pulled?: boolean; posisiOpsi?: Array<{ nilai: string; label: string }> }>();

const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo = ref(props.filters?.date_to ?? '');
const supplierId = ref<any>(props.filters?.supplier_id ?? '');
const posisi = ref(props.filters?.posisi ?? 'semua');

function params() {
    return { date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined, supplier_id: supplierId.value || undefined, posisi: posisi.value };
}
// Tarik data (preview) — data baru ditampilkan setelah periode dipilih & tombol ini diklik.
function tarik() {
    router.get(route('bs.ap.report'), { ...params(), pull: 1 }, { preserveState: true, replace: true });
}
// URL export mengikuti periode/supplier yang sedang dipilih.
const exportUrl = computed(() => route('bs.ap.export', params()));

function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
function dash(v: any) { return (v === null || v === undefined || v === '') ? '—' : v; }

const totals = computed(() => ({
    total: props.rows.reduce((s: number, r: any) => s + Number(r.total || 0), 0),
    paid: props.rows.reduce((s: number, r: any) => s + Number(r.paid || 0), 0),
    sisa: props.rows.reduce((s: number, r: any) => s + Number(r.sisa || 0), 0),
}));
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Invoice Hutang</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Laporan Invoice Hutang</h1>
        </div>
        <p class="text-sm text-neutral-500">Pilih <b>periode tanggal</b> (dan supplier bila perlu), klik <b>Tampilkan</b> untuk menarik data, lalu <b>Export Excel</b> untuk mengunduh.</p>

        <!-- Filter periode — wajib dipilih sebelum penarikan data -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label>
                <input v-model="dateFrom" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label>
                <input v-model="dateTo" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div class="min-w-[240px] flex-1">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier <span class="text-neutral-400">(opsional — kosong = semua)</span></label>
                <SearchSelect v-model="supplierId" :options="suppliers" placeholder="— semua supplier —" />
            </div>
            <PilihPosisiHutang v-model="posisi" :opsi="props.posisiOpsi" />
            <button @click="tarik" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <a :href="exportUrl" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Export Excel</a>
        </div>

        <!-- Hasil (preview) -->
        <div v-if="pulled" class="card overflow-x-auto">
            <table class="min-w-[1180px] w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">No Invoice</th>
                        <th class="px-3 py-3">Tanggal</th>
                        <th class="px-3 py-3">Tgl Diterima</th>
                        <th class="px-3 py-3">Jatuh Tempo</th>
                        <th class="px-3 py-3 text-right">Aging Hutang</th>
                        <th class="px-3 py-3 text-right">Aging JT</th>
                        <th class="px-3 py-3">Supplier</th>
                        <th class="px-3 py-3">Keterangan</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Total</th>
                        <th class="px-3 py-3 text-right">Terbayar</th>
                        <th class="px-3 py-3 text-right">Sisa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2 font-mono text-xs">{{ r.invoice_no }}</td>
                        <td class="px-3 py-2">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-3 py-2">{{ fmtDate(r.receive_date) }}</td>
                        <td class="px-3 py-2">{{ fmtDate(r.due_date) }}</td>
                        <td class="px-3 py-2 text-right">{{ dash(r.aging_hutang) }}</td>
                        <td class="px-3 py-2 text-right" :class="(r.aging_jt ?? 0) > 0 ? 'text-danger-600' : 'text-neutral-400'">{{ dash(r.aging_jt) }}</td>
                        <td class="px-3 py-2">{{ r.supplier }}</td>
                        <td class="px-3 py-2 text-neutral-500">{{ dash(r.description) }}</td>
                        <td class="px-3 py-2">{{ r.status }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(r.total) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(r.paid) }}</td>
                        <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtRp(r.sisa) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows?.length" class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <td class="px-3 py-3" colspan="9">TOTAL <span class="ml-1 text-xs font-normal text-neutral-400">({{ rows.length }} invoice)</span></td>
                        <td class="px-3 py-3 text-right font-mono">{{ fmtRp(totals.total) }}</td>
                        <td class="px-3 py-3 text-right font-mono">{{ fmtRp(totals.paid) }}</td>
                        <td class="px-3 py-3 text-right font-mono text-danger-600">{{ fmtRp(totals.sisa) }}</td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada invoice" description="Tidak ada invoice hutang pada periode / supplier yang dipilih." />
        </div>
        <EmptyState v-else title="Pilih periode laporan" description="Tentukan Dari / Sampai Tanggal (dan supplier bila perlu), lalu klik Tampilkan untuk menarik data." />
    </div>
</template>
