<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, Search } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rows: any; filters: { search?: string; status?: string } }>();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function apply() { router.get(route('setup-sales.reports.master-unit'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const sv: Record<string, any> = { available: 'success', booked: 'warning', sold: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('setup-sales.reports.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Laporan</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Building2 class="h-6 w-6 text-primary-600" /> Laporan Master Unit</h1>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari kode unit…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="status" @change="apply" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua status</option><option value="available">Tersedia</option><option value="booked">Booked</option><option value="sold">Terjual</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Project</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Cluster</th><th class="px-4 py-3 text-right">Harga Dasar</th><th class="px-4 py-3 text-right">Harga Jual</th><th class="px-4 py-3 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.unit_code }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.project ?? '—' }}</td>
                        <td class="px-4 py-2">{{ r.type ?? '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.cluster ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-neutral-500">{{ rb(r.base_price) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">{{ rb(r.current_price) }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="sv[r.status] ?? 'neutral'" size="sm">{{ r.status }}</Badge></td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Tidak ada unit.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
