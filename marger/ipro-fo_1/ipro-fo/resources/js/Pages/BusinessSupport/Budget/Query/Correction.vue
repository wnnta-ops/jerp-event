<!-- BusinessSupport/Budget/Query/Correction.vue — Laporan Koreksi Budget -->
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
  costCenters: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const year = ref<number>(props.filters.year);
const cc = ref<number | null>(props.filters.cost_center_id);

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger' };

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}
function apply() {
  router.get(route('bs.budget.query.correction'), { year: year.value, cost_center_id: cc.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Laporan Koreksi Budget</h1>

    <div class="card p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-xs uppercase text-neutral-500">Tahun</label>
          <input v-model.number="year" type="number" :class="inputCls" />
        </div>
        <div class="min-w-48">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Cost Center</label>
          <select v-model="cc" :class="inputCls">
            <option :value="''">Semua</option>
            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <Button @click="apply">Tampilkan</Button>
      </div>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. Koreksi</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Ref. Budget</th>
            <th class="px-3 py-2">Akun</th>
            <th class="px-3 py-2">Cost Center</th>
            <th class="px-3 py-2 text-right">Nilai Lama</th>
            <th class="px-3 py-2 text-right">Nilai Baru</th>
            <th class="px-3 py-2 text-right">Selisih</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.doc_no">
            <td class="px-3 py-2 font-medium">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.ref }}</td>
            <td class="px-3 py-2">{{ r.account }}</td>
            <td class="px-3 py-2">{{ r.cost_center }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.old_amount) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.new_amount) }}</td>
            <td class="px-3 py-2 text-right" :class="r.selisih >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(r.selisih) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Tidak ada data koreksi" description="Belum ada koreksi budget untuk filter yang dipilih." />
  </div>
</template>
