<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus, ArrowLeft, Building, Search, Play } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    assets: any;
    categories: Record<string, string>;
    filters: { category?: string; status?: string; search?: string };
    summary: { cost: number; accum: number; book_value: number };
}>();

const search = ref(props.filters.search ?? '');
const category = ref(props.filters.category ?? '');
const status = ref(props.filters.status ?? '');
function applyFilter() {
    router.get(route('bs.fa.index'), { search: search.value || undefined, category: category.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { active: 'success', fully_depreciated: 'neutral', disposed: 'danger', sold: 'primary' };
const statusLabel: Record<string, string> = { active: 'Aktif', fully_depreciated: 'Habis Susut', disposed: 'Dihapus', sold: 'Terjual' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Building class="h-6 w-6 text-primary-600" /> Aset Tetap <span class="text-base font-normal text-neutral-400">(Fixed Asset)</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.fa.run')"><Button variant="secondary"><template #icon-left><Play class="h-4 w-4" /></template>Jalankan Penyusutan</Button></Link>
                <Link :href="route('bs.fa.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Daftar Aset</Button></Link>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Harga Perolehan</div><div class="mt-1 text-lg font-bold">{{ fmtRp(summary.cost) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Akumulasi Penyusutan</div><div class="mt-1 text-lg font-bold text-danger-600">{{ fmtRp(summary.accum) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Buku</div><div class="mt-1 text-lg font-bold text-success-700">{{ fmtRp(summary.book_value) }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. / nama aset…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="category" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua kategori</option><option v-for="(label, val) in categories" :key="val" :value="val">{{ label }}</option></select>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua status</option><option value="active">Aktif</option><option value="fully_depreciated">Habis Susut</option><option value="disposed">Dihapus</option><option value="sold">Terjual</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3">Perolehan</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Akum.</th><th class="px-4 py-3 text-right">Nilai Buku</th><th class="px-4 py-3 text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="a in assets.data" :key="a.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.fa.show', a.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ a.asset_number }}</td>
                        <td class="px-4 py-2.5">{{ a.name }}</td>
                        <td class="px-4 py-2.5">{{ categories[a.category] }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(a.acquisition_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(a.acquisition_cost) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(a.accumulated_depreciation) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(a.book_value) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="statusBadge[a.status] ?? 'neutral'" size="sm">{{ statusLabel[a.status] }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!assets.data?.length" title="Belum ada aset tetap" description="Klik Daftar Aset untuk mencatat bangunan, kendaraan, atau inventaris." />
        </div>
    </div>
</template>
