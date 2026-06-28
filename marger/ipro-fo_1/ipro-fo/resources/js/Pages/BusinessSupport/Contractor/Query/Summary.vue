<!-- Summary SPK — grouped contract/progress/retention totals -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  filters: { project_id: any; jenis: any };
  projects: Array<any>;
  jenisOpts: Record<string, string>;
  rows: Array<any>;
  total: { contract: number; progress: number; retention: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const proj = ref(props.filters?.project_id ?? '');
const jen = ref(props.filters?.jenis ?? '');
function apply() {
  router.get(route('bs.spk.query.summary'), {
    project_id: proj.value || undefined,
    jenis: jen.value || undefined,
  }, { preserveState: true, replace: true });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-amber-600" /> Summary SPK</h1>

    <!-- Filter bar -->
    <div class="card p-4">
      <div class="grid gap-3 sm:grid-cols-3">
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
          <select v-model="proj" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">Jenis</label>
          <select v-model="jen" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="(lbl, key) in jenisOpts" :key="key" :value="key">{{ lbl }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <Button type="button" @click="apply">Tampilkan</Button>
        </div>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="grid gap-3 sm:grid-cols-3">
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Kontrak</div>
        <div class="mt-1 text-xl font-bold">{{ fmtRp(total.contract) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Progress</div>
        <div class="mt-1 text-xl font-bold">{{ fmtRp(total.progress) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Retensi</div>
        <div class="mt-1 text-xl font-bold">{{ fmtRp(total.retention) }}</div>
      </div>
    </div>

    <!-- Table -->
    <EmptyState v-if="!rows.length" title="Belum ada data" description="Tidak ada ringkasan SPK untuk filter yang dipilih." />
    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Group</th>
            <th class="px-3 py-2 text-right">Jml SPK</th>
            <th class="px-3 py-2 text-right">Kontrak</th>
            <th class="px-3 py-2 text-right">Progress</th>
            <th class="px-3 py-2 text-right">Retensi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r, i) in rows" :key="i">
            <td class="px-3 py-2">{{ r.group }}</td>
            <td class="px-3 py-2 text-right">{{ r.count }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.contract) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.progress) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.retention) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
