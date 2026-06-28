<!-- Business Support · Data Center · Supplier · Repair Data -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Wrench } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ issues: Array<{ key: string; label: string; count: number }> }>();

function runRepair() {
  if (!confirm('Jalankan perbaikan data?')) return;
  router.post(route('bs.dc.supplier.repair.run'), {}, { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link
      :href="route('bs.dc.home')"
      class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"
    >
      <ArrowLeft class="h-4 w-4" /> Data Center
    </Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Wrench class="h-6 w-6 text-primary-600" /> Repair Data Supplier
      </h1>
      <Button @click="runRepair">
        <Wrench class="h-4 w-4" /> Jalankan Repair
      </Button>
    </div>

    <div class="card text-sm text-neutral-600 dark:text-neutral-300">
      Membuatkan kode (SUP-####) untuk supplier tanpa kode. Duplikat kode &amp; tanpa rekening hanya dilaporkan.
    </div>

    <div v-if="issues.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Masalah</th>
            <th class="px-3 py-2 text-right">Jumlah</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="i in issues" :key="i.key">
            <td class="px-3 py-2">{{ i.label }}</td>
            <td class="px-3 py-2 text-right">
              <Badge :variant="i.count > 0 ? 'warning' : 'success'">{{ i.count }}</Badge>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState
      v-else
      title="Tidak ada masalah"
      description="Data supplier tidak memerlukan perbaikan saat ini."
    />
  </div>
</template>
