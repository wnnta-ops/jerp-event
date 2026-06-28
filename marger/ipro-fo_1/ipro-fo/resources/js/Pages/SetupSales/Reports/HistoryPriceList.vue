<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, History, Search, ArrowUp, ArrowDown } from 'lucide-vue-next';

const props = defineProps<{ logs: any; filters: { search?: string } }>();
const search = ref(props.filters.search ?? '');
function apply() { router.get(route('setup-sales.reports.history-price'), { search: search.value || undefined }, { preserveState: true, replace: true }); }
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('setup-sales.reports.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Laporan</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><History class="h-6 w-6 text-amber-600" /> History Price List</h1>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari kode unit…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3 text-right">Harga Lama</th><th class="px-4 py-3 text-right">Harga Baru</th><th class="px-4 py-3 text-right">Selisih</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Oleh</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in logs.data" :key="r.id">
                        <td class="px-4 py-2 font-mono text-xs">{{ r.at }}</td>
                        <td class="px-4 py-2 font-mono">{{ r.unit_code ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-neutral-500">{{ rb(r.old_price) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums font-medium">{{ rb(r.new_price) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums" :class="r.delta >= 0 ? 'text-success-600' : 'text-danger-600'">
                            <component :is="r.delta >= 0 ? ArrowUp : ArrowDown" class="inline h-3 w-3" />{{ rb(Math.abs(r.delta)) }}
                        </td>
                        <td class="px-4 py-2 text-xs text-neutral-500">{{ r.source }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.by ?? '—' }}</td>
                    </tr>
                    <tr v-if="!logs.data?.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Belum ada riwayat perubahan harga.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="logs.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in logs.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
