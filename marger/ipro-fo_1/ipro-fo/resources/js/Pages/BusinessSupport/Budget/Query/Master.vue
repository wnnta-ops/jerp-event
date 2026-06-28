<!-- Laporan Master Budget — Budget Control query report -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  filters: { year: number; cost_center_id: number | null };
  rows: Array<any>;
  monthLabels: string[];
  costCenters: Array<any>;
  total: number;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger' };

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const year = ref<number>(props.filters.year);
const cc = ref<number | null>(props.filters.cost_center_id);

function apply() {
  router.get(route('bs.budget.query.master'), { year: year.value, cost_center_id: cc.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Budget Control
    </Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold">
      <FileBarChart class="h-6 w-6 text-primary-600" /> Laporan Master Budget
    </h1>

    <div class="card p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">Tahun</label>
          <input v-model.number="year" type="number" :class="inputCls" />
        </div>
        <div class="min-w-[200px]">
          <label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label>
          <select v-model="cc" :class="inputCls">
            <option :value="''">Semua</option>
            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <Button @click="apply">Tampilkan</Button>
      </div>
    </div>

    <div class="card p-4">
      <span class="text-sm text-neutral-500">Total Anggaran:</span>
      <span class="ml-2 text-lg font-bold">{{ fmtRp(total) }}</span>
    </div>

    <EmptyState v-if="rows.length === 0" title="Belum ada data" description="Tidak ada master budget untuk filter yang dipilih." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-xs">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. Budget</th>
            <th class="px-3 py-2">Akun</th>
            <th class="px-3 py-2">Cost Center</th>
            <th v-for="(m, i) in monthLabels" :key="i" class="px-3 py-2 text-right">{{ m }}</th>
            <th class="px-3 py-2 text-right">Anggaran</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r, ri) in rows" :key="ri">
            <td class="px-3 py-2 font-medium">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ r.account }}</td>
            <td class="px-3 py-2">{{ r.cost_center }}</td>
            <td v-for="(m, i) in monthLabels" :key="i" class="px-3 py-2 text-right">{{ Number(r.months[i] ?? 0).toLocaleString('id-ID') }}</td>
            <td class="px-3 py-2 text-right font-medium">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
