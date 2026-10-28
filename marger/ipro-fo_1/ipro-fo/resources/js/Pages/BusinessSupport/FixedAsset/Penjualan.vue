<script setup lang="ts">
import { reactive, ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Tag, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    rows: Array<any>;
    categories: Record<string, string>;
    filters: { date_from?: string; date_to?: string; category?: string };
    total: { count: number; cost: number; book: number; proceeds: number; gain: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtRpc(v: any) { return (v === null || v === undefined) ? '—' : fmtRp(v); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo = ref(props.filters?.date_to ?? '');
function params() { return { date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }; }
function reload() { router.get(route('bs.fa.penjualan'), params(), { preserveState: true, replace: true }); }
// Excel = yang sedang tampil: periode yang sudah diterapkan (props.filters) + id baris hasil
// saringan kolom (bila ada saringan).
function exportXls() {
    const ada = Object.values(f).some((v) => v !== '');
    window.location.href = route('bs.fa.penjualan.export', {
        date_from: props.filters?.date_from || undefined,
        date_to: props.filters?.date_to || undefined,
        ids: ada ? (filtered.value.map((r) => r.id).join(',') || '0') : undefined,
    });
}

const catOptions = Object.entries(props.categories).map(([id, label]) => ({ id, label }));
const jenisOptions = [{ id: 'sold', label: 'Jual' }, { id: 'disposed', label: 'Hapus' }];

// Filter per-kolom (client).
const f = reactive({ no: '', nama: '', kategori: '', tgl: '', jenis: '' });
function inc(hay: any, n: string) { return String(hay ?? '').toLowerCase().includes(n.toLowerCase()); }
const filtered = computed(() => props.rows.filter((r) =>
    (!f.no || inc(r.asset_number, f.no)) &&
    (!f.nama || inc(r.name, f.nama)) &&
    (!f.kategori || r.category === f.kategori) &&
    (!f.tgl || inc(fmtDate(r.disposed_date), f.tgl) || inc(r.disposed_date, f.tgl)) &&
    (!f.jenis || r.status === f.jenis)
));
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const fInput = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Aset Tetap</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Tag class="h-6 w-6 text-primary-600" /> Penjualan / Pelepasan Aset</h1>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Lepas (dari)</label><input v-model="dateFrom" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">s/d</label><input v-model="dateTo" type="date" :class="inputCls" /></div>
            <button @click="reload" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <button @click="exportXls" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Aset</div><div class="mt-1 text-lg font-bold text-primary-600">{{ total.count }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Harga Perolehan</div><div class="mt-1 text-base font-bold">{{ fmtRp(total.cost) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Buku Lepas</div><div class="mt-1 text-base font-bold">{{ fmtRp(total.book) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Hasil Jual</div><div class="mt-1 text-base font-bold text-success-700">{{ fmtRp(total.proceeds) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Laba/(Rugi)</div><div class="mt-1 text-base font-bold" :class="total.gain >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(total.gain) }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <div class="flex items-center justify-between px-4 py-3 text-sm font-semibold"><span>Daftar Penjualan / Pelepasan</span><span class="text-xs font-normal text-neutral-400">{{ filtered.length }} dari {{ rows.length }}</span></div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. Aset</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3">Tgl Perolehan</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Nilai Buku</th><th class="px-4 py-3">Tgl Lepas</th><th class="px-4 py-3 text-center">Jenis</th><th class="px-4 py-3 text-right">Hasil Jual</th><th class="px-4 py-3 text-right">Laba/(Rugi)</th>
                    </tr>
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"><input v-model="f.no" :class="fInput" placeholder="No…" /></th>
                        <th class="px-2 py-2"><input v-model="f.nama" :class="fInput" placeholder="Nama…" /></th>
                        <th class="px-2 py-2"><div class="min-w-[9rem]"><SearchSelect v-model="f.kategori" :options="catOptions" placeholder="Semua" /></div></th>
                        <th class="px-2 py-2"></th><th class="px-2 py-2"></th><th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><input v-model="f.tgl" :class="fInput" placeholder="Tgl…" /></th>
                        <th class="px-2 py-2"><div class="min-w-[7rem]"><SearchSelect v-model="f.jenis" :options="jenisOptions" placeholder="Semua" /></div></th>
                        <th class="px-2 py-2"></th><th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.fa.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.asset_number }}</td>
                        <td class="px-4 py-2.5">{{ r.name }}</td>
                        <td class="px-4 py-2.5">{{ r.category_label }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.acquisition_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.cost) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.book) }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.disposed_date) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'sold' ? 'primary' : 'danger'" size="sm">{{ r.status === 'sold' ? 'Jual' : 'Hapus' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.status === 'sold' ? fmtRp(r.proceeds) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold" :class="r.gain === null ? 'text-neutral-400' : (r.gain >= 0 ? 'text-success-700' : 'text-danger-600')">{{ fmtRpc(r.gain) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada penjualan / pelepasan aset" description="Aset yang dijual atau dihapus akan muncul di sini." />
            <div v-else-if="!filtered.length" class="px-4 py-8 text-center text-sm text-neutral-400">Tidak ada data sesuai filter kolom.</div>
        </div>
    </div>
</template>
