<script setup lang="ts">
// Business Support · Data Center · Supplier — Laporan Master Supplier
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  filters: { search?: string; status?: string };
  summary: { total: number; active: number };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function apply() {
  router.get(
    route('bs.dc.supplier.report'),
    {
      search: search.value || undefined,
      status: status.value === '' ? undefined : status.value,
    },
    { preserveState: true, replace: true },
  );
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Data Center
    </Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold">
      <FileBarChart class="h-6 w-6 text-primary-600" /> Laporan Master Supplier
    </h1>

    <div class="card">
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Cari</label>
          <input v-model="search" :class="inputCls" placeholder="Kode / nama supplier" @keyup.enter="apply" />
        </div>
        <div class="w-40">
          <label class="mb-1 block text-xs uppercase text-neutral-500">Status</label>
          <select v-model="status" :class="inputCls">
            <option value="">Semua</option>
            <option value="1">Aktif</option>
            <option value="0">Nonaktif</option>
          </select>
        </div>
        <Button @click="apply">Tampilkan</Button>
      </div>
      <p class="mt-3 text-sm text-neutral-500">{{ summary.total }} supplier · {{ summary.active }} aktif</p>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Kode</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Kategori</th>
            <th class="px-3 py-2">Kota</th>
            <th class="px-3 py-2">Telp</th>
            <th class="px-3 py-2">NPWP</th>
            <th class="px-3 py-2">Bank</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-mono text-xs">
              <Link :href="route('bs.dc.supplier.show', r.id)" class="text-primary-600 hover:underline">{{ r.code }}</Link>
            </td>
            <td class="px-3 py-2">{{ r.name }}</td>
            <td class="px-3 py-2">{{ r.category }}</td>
            <td class="px-3 py-2">{{ r.city }}</td>
            <td class="px-3 py-2">{{ r.phone }}</td>
            <td class="px-3 py-2">{{ r.npwp }}</td>
            <td class="px-3 py-2">{{ r.bank_name }} {{ r.bank_account_no }}</td>
            <td class="px-3 py-2">
              <Badge :variant="r.is_active ? 'success' : 'neutral'">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Belum ada supplier" description="Tidak ada data supplier yang cocok dengan filter." />
  </div>
</template>
