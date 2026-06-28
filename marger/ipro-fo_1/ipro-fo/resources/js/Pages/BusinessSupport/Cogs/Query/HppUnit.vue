<!-- Report HPP Unit RealCost — per-unit accumulated revenue & HPP query -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  filters: { project_id: number | null; year: number | null };
  projects: Array<any>;
  rows: Array<any>;
  total: { revenue: number; hpp: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const proj = ref<number | string>(props.filters.project_id ?? '');
const year = ref<number | null>(props.filters.year ?? new Date().getFullYear());

function apply() {
  router.get(route('bs.cogs.query.hpp-unit'), {
    project_id: proj.value || undefined,
    year: year.value || undefined,
  }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Report HPP Unit RealCost</h1>

    <!-- Filter bar -->
    <div class="card">
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-56">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Project</label>
          <select v-model="proj" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div class="w-32">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Tahun</label>
          <input v-model.number="year" type="number" :class="inputCls" />
        </div>
        <Button @click="apply">Tampilkan</Button>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div class="card">
        <div class="text-xs uppercase text-neutral-500">Akumulasi Pendapatan</div>
        <div class="mt-1 text-2xl font-bold">{{ fmtRp(total.revenue) }}</div>
      </div>
      <div class="card">
        <div class="text-xs uppercase text-neutral-500">Akumulasi HPP</div>
        <div class="mt-1 text-2xl font-bold">{{ fmtRp(total.hpp) }}</div>
      </div>
    </div>

    <!-- Table -->
    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2 text-right">Nilai Kontrak</th>
            <th class="px-3 py-2 text-right">Total HPP</th>
            <th class="px-3 py-2 text-right">Progress</th>
            <th class="px-3 py-2 text-right">Akum. Pendapatan</th>
            <th class="px-3 py-2 text-right">Akum. HPP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r, i) in rows" :key="i">
            <td class="px-3 py-2 font-medium">{{ r.unit }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.nilai_kontrak) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.total_hpp) }}</td>
            <td class="px-3 py-2 text-right">{{ r.progress }}%</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.revenue_accum) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.hpp_accum) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else title="Belum ada data" description="Tidak ada data HPP unit untuk filter yang dipilih." />
  </div>
</template>
