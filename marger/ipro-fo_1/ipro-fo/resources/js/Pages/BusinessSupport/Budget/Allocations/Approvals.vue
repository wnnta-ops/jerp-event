<!-- Approval Alokasi Budget — batch approval page -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  canApprove: boolean;
  summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

const sel = ref<number[]>([]);
function toggle(id: number) { const i = sel.value.indexOf(id); i >= 0 ? sel.value.splice(i, 1) : sel.value.push(id); }
function allChecked() { return props.rows.length > 0 && sel.value.length === props.rows.length; }
function toggleAll() { sel.value = allChecked() ? [] : props.rows.map((r: any) => r.id); }
function decide(decision: 'approve' | 'reject') {
  if (!sel.value.length) return;
  router.post(route('bs.budget.allocation.approve-batch'), { ids: sel.value, decision }, { preserveScroll: true, onSuccess: () => { sel.value = []; } });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Alokasi Budget</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} alokasi menunggu persetujuan · Rp {{ fmtRp(summary.total) }}</p>
      </div>
      <div v-if="canApprove" class="flex items-center gap-2">
        <Button class="bg-green-600 text-white hover:bg-green-700" :disabled="sel.length === 0" @click="decide('approve')">Setujui</Button>
        <Button class="bg-red-600 text-white hover:bg-red-700" :disabled="sel.length === 0" @click="decide('reject')">Tolak</Button>
      </div>
    </div>

    <EmptyState v-if="rows.length === 0" title="Tidak ada alokasi menunggu persetujuan" description="Semua alokasi budget sudah diproses." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="px-3 py-2">No. Alokasi</th>
            <th class="px-3 py-2">Tahun</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Akun</th>
            <th class="px-3 py-2">Cost Center</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2 text-right">Total Budget</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2"><input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="px-3 py-2 font-medium">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ r.year }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.account }}</td>
            <td class="px-3 py-2">{{ r.cost_center }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.total_budget) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
