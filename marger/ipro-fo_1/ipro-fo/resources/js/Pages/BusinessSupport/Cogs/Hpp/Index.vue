<!-- HPP RealCost — paginated index of HPP recognition records -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Calculator, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: any;
  filters: { search?: string };
  statuses: Record<string, string>;
}>();

const search = ref(props.filters.search ?? '');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger', generated: 'info', posted_persediaan: 'warning', posted_hpp: 'success', saldo_awal: 'neutral', realisasi: 'info' };

function doSearch() {
  router.get(route('bs.cogs.hpp.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> HPP RealCost</h1>

    <div class="card flex flex-wrap items-center gap-2">
      <div class="relative flex-1 min-w-[200px]">
        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
        <input v-model="search" type="text" placeholder="Cari..." class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="doSearch" />
      </div>
      <Button variant="secondary" @click="doSearch">Cari</Button>
      <Link :href="route('bs.cogs.hpp.generate')"><Button>Generate HPP</Button></Link>
      <Link :href="route('bs.cogs.hpp.post-persediaan')"><Button variant="secondary">Posting Persediaan</Button></Link>
      <Link :href="route('bs.cogs.hpp.post-hpp')"><Button variant="secondary">Posting HPP</Button></Link>
    </div>

    <EmptyState v-if="!rows.data.length" title="Belum ada data HPP" description="Generate HPP terlebih dahulu untuk melihat data di sini." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">CogsID</th>
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2 text-right">Total HPP</th>
            <th class="px-3 py-2">Progress</th>
            <th class="px-3 py-2 text-right">HPP Diakui</th>
            <th class="px-3 py-2 text-right">Pendapatan Diakui</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows.data" :key="r.id">
            <td class="px-3 py-2 font-mono text-xs">{{ r.cogs_id }}</td>
            <td class="px-3 py-2">{{ r.unit_code }}</td>
            <td class="px-3 py-2">{{ r.period_month + '/' + r.period_year }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.total_hpp) }}</td>
            <td class="px-3 py-2">{{ r.progress_pct + '%' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.hpp_recognized) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.revenue_recognized) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ statuses[r.status] }}</Badge></td>
          </tr>
        </tbody>
      </table>

      <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1 p-3 text-sm">
        <template v-for="(l, i) in rows.links" :key="i">
          <Link v-if="l.url" :href="l.url" v-html="l.label" class="rounded px-3 py-1" :class="l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700'" preserve-scroll />
          <span v-else v-html="l.label" class="rounded px-3 py-1 text-neutral-400" />
        </template>
      </div>
    </div>
  </div>
</template>
