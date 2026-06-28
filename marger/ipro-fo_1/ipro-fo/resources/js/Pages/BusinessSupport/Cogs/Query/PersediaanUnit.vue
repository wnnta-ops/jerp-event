<!-- COGS Query: Report Persediaan Unit RealCost -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  filters: { project_id: number | string | null; year: number | string | null };
  projects: Array<any>;
  rows: Array<any>;
  total: { kapitalisasi: number; hpp_diakui: number; saldo: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const proj = ref(props.filters.project_id ?? '');
const year = ref(props.filters.year ?? new Date().getFullYear());

function apply() {
  router.get(route('bs.cogs.query.persediaan-unit'), {
    project_id: proj.value || undefined,
    year: year.value || undefined,
  }, { preserveState: true, replace: true });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Report Persediaan Unit RealCost</h1>

    <!-- Filter bar -->
    <div class="card">
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
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
    </div>

    <!-- Summary cards -->
    <div class="grid gap-4 sm:grid-cols-3">
      <div class="card">
        <p class="text-xs uppercase text-neutral-500">Kapitalisasi</p>
        <p class="mt-1 text-xl font-bold">{{ fmtRp(total.kapitalisasi) }}</p>
      </div>
      <div class="card">
        <p class="text-xs uppercase text-neutral-500">HPP Diakui</p>
        <p class="mt-1 text-xl font-bold">{{ fmtRp(total.hpp_diakui) }}</p>
      </div>
      <div class="card">
        <p class="text-xs uppercase text-neutral-500">Saldo Persediaan</p>
        <p class="mt-1 text-xl font-bold">{{ fmtRp(total.saldo) }}</p>
      </div>
    </div>

    <!-- Table -->
    <EmptyState v-if="!rows.length" title="Belum ada data" description="Tidak ada persediaan unit untuk filter yang dipilih." />
    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2 text-right">Kapitalisasi</th>
            <th class="px-3 py-2 text-right">HPP Diakui</th>
            <th class="px-3 py-2 text-right">Saldo Persediaan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r, i) in rows" :key="i">
            <td class="px-3 py-2">{{ r.unit }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.kapitalisasi) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.hpp_diakui) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.saldo) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
