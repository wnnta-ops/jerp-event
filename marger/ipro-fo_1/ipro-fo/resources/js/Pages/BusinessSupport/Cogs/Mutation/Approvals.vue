<!-- COGS · Approval Mutasi Budget (batch approve/reject) -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck, Check, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  canApprove: boolean;
  summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const sel = ref<number[]>([]);
function toggle(id: number) {
  const i = sel.value.indexOf(id);
  i === -1 ? sel.value.push(id) : sel.value.splice(i, 1);
}
const allChecked = () => props.rows.length > 0 && sel.value.length === props.rows.length;
function toggleAll() {
  sel.value = allChecked() ? [] : props.rows.map((r) => r.id);
}

function decide(d: 'approve' | 'reject') {
  if (!sel.value.length) return;
  router.post(route('bs.cogs.mutation.approve-batch'), { ids: sel.value, decision: d }, { preserveScroll: true, onSuccess: () => { sel.value = []; } });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Mutasi Budget</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} mutasi menunggu persetujuan · Rp {{ fmtRp(summary.total) }}</p>
      </div>
      <div v-if="canApprove" class="flex items-center gap-2">
        <Button variant="success" :disabled="!sel.length" @click="decide('approve')"><Check class="h-4 w-4" /> Setujui</Button>
        <Button variant="danger" :disabled="!sel.length" @click="decide('reject')"><X class="h-4 w-4" /> Tolak</Button>
      </div>
    </div>

    <EmptyState v-if="!rows.length" title="Tidak ada mutasi" description="Tidak ada mutasi budget yang menunggu persetujuan." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="px-3 py-2">No. Voucher</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Jenis</th>
            <th class="px-3 py-2">Dari</th>
            <th class="px-3 py-2">Ke</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2"><input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="px-3 py-2 font-medium">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.kind }}</td>
            <td class="px-3 py-2">{{ r.from }}</td>
            <td class="px-3 py-2">{{ r.to }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2 text-neutral-500">{{ r.note }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
