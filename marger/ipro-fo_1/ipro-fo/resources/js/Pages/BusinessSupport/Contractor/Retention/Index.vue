<!-- Progress Retensi — SPK with retention held -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Unlock, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  summary: { count: number; total: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const stBadge:Record<string,any>={baru:'warning',approved:'success',rejected:'danger',active:'info',closed:'success',cancelled:'danger',open:'warning',unpaid:'warning',paid:'success'};
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Unlock class="h-6 w-6 text-amber-600" /> Progress Retensi</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} SPK dengan retensi tertahan · Rp {{ fmtRp(summary.total) }}</p>
      </div>
      <Link :href="route('bs.spk.retention.create')">
        <Button><Plus class="h-4 w-4" /> Registrasi Retensi</Button>
      </Link>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. SPK</th>
            <th class="px-3 py-2">Kontraktor</th>
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2 text-right">Nilai Kontrak</th>
            <th class="px-3 py-2 text-right">Retensi Ditahan</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-medium">{{ r.spk_number }}</td>
            <td class="px-3 py-2">{{ r.supplier }}</td>
            <td class="px-3 py-2">{{ r.project }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.contract_value) }}</td>
            <td class="px-3 py-2 text-right font-medium text-amber-600">{{ fmtRp(r.retention_held) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status] || 'info'">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else title="Tidak ada retensi tertahan" description="Belum ada SPK dengan retensi yang sedang ditahan." />
  </div>
</template>
