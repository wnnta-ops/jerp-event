<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';
import { Plus, ArrowLeft, Building, Play, Boxes, TrendingDown, Tag, Trash2, Layers, Upload } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    assets: Array<any>;
    categories: Record<string, string>;
    statuses: Record<string, string>;
    byCategory: Array<any>;
    byStatus: Array<any>;
    summary: { count: number; cost: number; accum: number; book_value: number; dep_this_year: number; dep_year: number; sold_count: number; sold_proceeds: number; disposed_count: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { active: 'success', fully_depreciated: 'neutral', disposed: 'danger', sold: 'primary' };

const catOptions = computed(() => Object.entries(props.categories).map(([id, label]) => ({ id, label })));
const statusOptions = computed(() => Object.entries(props.statuses).map(([id, label]) => ({ id, label })));

// ── Filter per-kolom (dropdown-search + typing) ──
const f = reactive({ no: '', nama: '', kategori: '', tgl: '', status: '' });
function inc(hay: any, n: string) { return String(hay ?? '').toLowerCase().includes(n.toLowerCase()); }
const filtered = computed(() => props.assets.filter((a) =>
    (!f.no || inc(a.asset_number, f.no)) &&
    (!f.nama || inc(a.name, f.nama)) &&
    (!f.kategori || a.category === f.kategori) &&
    (!f.tgl || inc(fmtDate(a.acquisition_date), f.tgl) || inc(a.acquisition_date, f.tgl)) &&
    (!f.status || a.status === f.status)
));
const fInput = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
const catTint: Record<string, string> = { bangunan: 'text-sky-600', kendaraan: 'text-violet-600', inventaris: 'text-amber-600', tanah: 'text-emerald-600' };
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Building class="h-6 w-6 text-primary-600" /> Aset Tetap <span class="text-base font-normal text-neutral-400">(Fixed Asset)</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.fa.run')"><Button variant="secondary"><template #icon-left><Play class="h-4 w-4" /></template>Jalankan Penyusutan</Button></Link>
                <Link :href="route('bs.fa.upload.index')"><Button variant="secondary"><template #icon-left><Upload class="h-4 w-4" /></template>Upload Massal</Button></Link>
                <Link :href="route('bs.fa.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Daftar Aset</Button></Link>
            </div>
        </div>

        <!-- Ringkasan seluruh modul -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Boxes class="h-4 w-4" /> Total Aset</div><div class="mt-1 text-2xl font-bold text-primary-600">{{ summary.count }}</div><div class="text-[11px] text-neutral-500">{{ byCategory.length }} kategori</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Harga Perolehan</div><div class="mt-1 text-lg font-bold">{{ fmtRp(summary.cost) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Akumulasi Penyusutan</div><div class="mt-1 text-lg font-bold text-danger-600">{{ fmtRp(summary.accum) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Buku</div><div class="mt-1 text-lg font-bold text-success-700">{{ fmtRp(summary.book_value) }}</div></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><TrendingDown class="h-4 w-4" /> Penyusutan {{ summary.dep_year }}</div><div class="mt-1 text-lg font-bold text-amber-600">{{ fmtRp(summary.dep_this_year) }}</div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Tag class="h-4 w-4" /> Terjual</div><div class="mt-1 text-lg font-bold">{{ summary.sold_count }} <span class="text-sm font-normal text-neutral-500">· {{ fmtRp(summary.sold_proceeds) }}</span></div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Trash2 class="h-4 w-4" /> Dihapus</div><div class="mt-1 text-lg font-bold">{{ summary.disposed_count }}</div></div>
        </div>

        <!-- Ringkasan per kategori -->
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card overflow-hidden lg:col-span-2">
                <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800"><Layers class="h-4 w-4 text-primary-600" /> Ringkasan per Kategori</div>
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Kategori</th><th class="px-4 py-2.5 text-center">Jml</th><th class="px-4 py-2.5 text-right">Harga Perolehan</th><th class="px-4 py-2.5 text-right">Akumulasi</th><th class="px-4 py-2.5 text-right">Nilai Buku</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="c in byCategory" :key="c.key">
                            <td class="px-4 py-2.5 font-medium" :class="catTint[c.key]">{{ c.label }}</td>
                            <td class="px-4 py-2.5 text-center">{{ c.count }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(c.cost) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(c.accum) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(c.book) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="byCategory.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800"><td class="px-4 py-2.5">Total</td><td class="px-4 py-2.5 text-center">{{ summary.count }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(summary.cost) }}</td><td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(summary.accum) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(summary.book_value) }}</td></tr></tfoot>
                </table>
            </div>
            <div class="card overflow-hidden">
                <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">Per Status</div>
                <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <div v-for="st in byStatus" :key="st.key" class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <Badge :variant="statusBadge[st.key] ?? 'neutral'" size="sm">{{ st.label }}</Badge>
                        <span><b>{{ st.count }}</b> <span class="text-xs text-neutral-400">· {{ fmtRp(st.cost) }}</span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daftar Aset + filter per-kolom -->
        <div class="card overflow-x-auto">
            <div class="flex items-center justify-between px-4 py-3 text-sm font-semibold">
                <span>Daftar Aset</span>
                <span class="text-xs font-normal text-neutral-400">{{ filtered.length }} dari {{ assets.length }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No.</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3">Perolehan</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Akum.</th><th class="px-4 py-3 text-right">Nilai Buku</th><th class="px-4 py-3 text-center">Status</th>
                    </tr>
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"><input v-model="f.no" :class="fInput" placeholder="No…" /></th>
                        <th class="px-2 py-2"><input v-model="f.nama" :class="fInput" placeholder="Nama…" /></th>
                        <th class="px-2 py-2"><div class="min-w-[9rem]"><SearchSelect v-model="f.kategori" :options="catOptions" placeholder="Semua kategori" /></div></th>
                        <th class="px-2 py-2"><input v-model="f.tgl" :class="fInput" placeholder="Tanggal…" /></th>
                        <th class="px-2 py-2"></th><th class="px-2 py-2"></th><th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><div class="min-w-[8rem]"><SearchSelect v-model="f.status" :options="statusOptions" placeholder="Semua status" /></div></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="a in filtered" :key="a.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.fa.show', a.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ a.asset_number }}</td>
                        <td class="px-4 py-2.5">{{ a.name }}</td>
                        <td class="px-4 py-2.5">{{ a.category_label }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(a.acquisition_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(a.acquisition_cost) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(a.accumulated_depreciation) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(a.book_value) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[a.status] ?? 'neutral'" size="sm">{{ statuses[a.status] ?? a.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!assets.length" title="Belum ada aset tetap" description="Klik Daftar Aset untuk mencatat bangunan, kendaraan, atau inventaris." />
            <div v-else-if="!filtered.length" class="px-4 py-8 text-center text-sm text-neutral-400">Tidak ada aset sesuai filter kolom.</div>
        </div>
    </div>
</template>
