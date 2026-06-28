<!-- Mutasi Progress Bangunan — index list of building progress mutations -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Shuffle, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: any;
  statuses: Record<string, string>;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger', active: 'info', closed: 'success', cancelled: 'danger', open: 'warning', unpaid: 'warning', paid: 'success' };
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><Shuffle class="h-6 w-6 text-amber-600" /> Mutasi Progress Bangunan</h1>
      <div class="flex items-center gap-2">
        <Link :href="route('bs.spk.mutation.approvals')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Approval</Link>
        <Link :href="route('bs.spk.mutation.create')">
          <Button><Plus class="h-4 w-4" /> Registrasi Mutasi</Button>
        </Link>
      </div>
    </div>

    <div v-if="rows.data?.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">SPK</th>
            <th class="px-3 py-2">Dari</th>
            <th class="px-3 py-2">Ke</th>
            <th class="px-3 py-2">Cost Center</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows.data" :key="r.id">
            <td class="px-3 py-2 whitespace-nowrap">{{ fmtDate(r.mutation_date) }}</td>
            <td class="px-3 py-2">{{ r.work_order?.spk_number }}</td>
            <td class="px-3 py-2">{{ r.from_account?.code }}</td>
            <td class="px-3 py-2">{{ r.to_account?.code }}</td>
            <td class="px-3 py-2">{{ r.to_cost_center?.name ?? '—' }}</td>
            <td class="px-3 py-2 text-right whitespace-nowrap">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ statuses[r.status] }}</Badge></td>
          </tr>
        </tbody>
      </table>

      <div v-if="rows.links?.length>3" class="flex flex-wrap gap-1 p-3 text-sm">
        <template v-for="(l,i) in rows.links" :key="i">
          <Link v-if="l.url" :href="l.url" v-html="l.label" class="rounded px-3 py-1" :class="l.active?'bg-primary-600 text-white':'border border-neutral-300 dark:border-neutral-700'" preserve-scroll />
          <span v-else v-html="l.label" class="rounded px-3 py-1 text-neutral-400" />
        </template>
      </div>
    </div>

    <EmptyState v-else title="Belum ada mutasi" description="Belum ada data mutasi progress bangunan." />
  </div>
</template>
