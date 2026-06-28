<!-- COGS Query: Report Budget -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  filters: { project_id: number | null; year: number };
  projects: Array<any>;
  rows: Array<any>;
  total: number;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const proj = ref<number | ''>(props.filters.project_id ?? '');
const year = ref<number>(props.filters.year);

function apply() {
  router.get(route('bs.cogs.query.budget'), {
    project_id: proj.value || undefined,
    year: year.value,
  }, { preserveState: true, replace: true });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Cost of Good Sold
    </Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold">
      <FileBarChart class="h-6 w-6 text-primary-600" /> Report Budget
    </h1>

    <div class="card">
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Proyek</label>
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

    <div class="card">
      <div class="text-sm text-neutral-500">Total Budget</div>
      <div class="text-2xl font-bold">{{ fmtRp(total) }}</div>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Lokasi</th>
            <th class="px-3 py-2">Kategori</th>
            <th class="px-3 py-2">Komponen</th>
            <th class="px-3 py-2">COA</th>
            <th class="px-3 py-2 text-right">Alokasi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r, i) in rows" :key="i">
            <td class="px-3 py-2">{{ r.location }}</td>
            <td class="px-3 py-2">{{ r.category }}</td>
            <td class="px-3 py-2">{{ r.component }}</td>
            <td class="px-3 py-2">{{ r.account ?? '—' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else title="Tidak ada data" description="Tidak ada budget untuk filter yang dipilih." />
  </div>
</template>
