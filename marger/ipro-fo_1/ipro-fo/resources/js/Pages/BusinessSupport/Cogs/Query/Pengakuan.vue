<!-- Report Pengakuan HPP RealCost — COGS recognition query (HPP & revenue recognized per unit). -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  filters: { project_id: number | string | null; year: number | string | null };
  projects: Array<any>;
  rows: Array<any>;
  total: { hpp: number; revenue: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger', generated: 'info', posted_persediaan: 'warning', posted_hpp: 'success', saldo_awal: 'neutral', realisasi: 'info' };

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const proj = ref(props.filters.project_id ?? '');
const year = ref(props.filters.year ?? new Date().getFullYear());

function apply() {
  router.get(route('bs.cogs.query.pengakuan'), { project_id: proj.value || undefined, year: year.value }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Report Pengakuan HPP RealCost</h1>

    <div class="card flex flex-wrap items-end gap-3">
      <div class="min-w-[200px] flex-1">
        <label class="mb-1 block text-xs font-medium text-neutral-500">Proyek</label>
        <select v-model="proj" :class="inputCls">
          <option value="">Semua</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>
      <div class="w-32">
        <label class="mb-1 block text-xs font-medium text-neutral-500">Tahun</label>
        <input v-model="year" type="number" :class="inputCls" />
      </div>
      <Button @click="apply">Tampilkan</Button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div class="card">
        <p class="text-xs uppercase text-neutral-500">HPP Diakui</p>
        <p class="mt-1 text-2xl font-bold">{{ fmtRp(total.hpp) }}</p>
      </div>
      <div class="card">
        <p class="text-xs uppercase text-neutral-500">Pendapatan Diakui</p>
        <p class="mt-1 text-2xl font-bold">{{ fmtRp(total.revenue) }}</p>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">CogsID</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2 text-right">Total HPP</th>
            <th class="px-3 py-2 text-right">Progress</th>
            <th class="px-3 py-2 text-right">HPP Diakui</th>
            <th class="px-3 py-2 text-right">Pendapatan Diakui</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.cogs_id">
            <td class="px-3 py-2 font-mono text-xs">{{ r.cogs_id }}</td>
            <td class="px-3 py-2">{{ r.period }}</td>
            <td class="px-3 py-2">{{ r.unit }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.total_hpp) }}</td>
            <td class="px-3 py-2 text-right">{{ r.progress }}%</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.hpp_recognized) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.revenue_recognized) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status] || 'neutral'">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="!rows.length" title="Belum ada data" description="Tidak ada pengakuan HPP untuk filter yang dipilih." />
    </div>
  </div>
</template>
