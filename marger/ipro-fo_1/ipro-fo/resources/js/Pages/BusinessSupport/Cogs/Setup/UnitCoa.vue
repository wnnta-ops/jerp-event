<!-- COGS Setup: Set COA per Jenis Unit (project + unit type → account mapping) -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Boxes, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  projects: Array<any>;
  accounts: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const form = useForm({
  project_id: '',
  unit_type: '',
  account_id: '',
});

function projName(id: number): string {
  return props.projects.find((p) => p.id === id)?.name ?? '—';
}

function submit() {
  form.post(route('bs.cogs.unitcoa.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
}

function destroy(id: number) {
  if (confirm('Hapus?')) router.delete(route('bs.cogs.unitcoa.destroy', id), { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Boxes class="h-6 w-6 text-primary-600" /> Set COA Jenis Unit</h1>

    <div class="card">
      <form @submit.prevent="submit" class="grid grid-cols-1 gap-3 md:grid-cols-4">
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
          <select v-model="form.project_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">Jenis Unit *</label>
          <input v-model="form.unit_type" type="text" :class="inputCls" required />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-neutral-500">COA *</label>
          <select v-model="form.account_id" :class="inputCls" required>
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>
        <div class="flex items-end">
          <Button type="submit" :disabled="form.processing" class="w-full md:w-auto">
            <Plus class="h-4 w-4" /> Tambah
          </Button>
        </div>
      </form>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Jenis Unit</th>
            <th class="px-3 py-2">COA</th>
            <th class="px-3 py-2 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="row in rows" :key="row.id">
            <td class="px-3 py-2">{{ projName(row.project_id) }}</td>
            <td class="px-3 py-2">{{ row.unit_type }}</td>
            <td class="px-3 py-2">
              <span class="font-medium">{{ row.account?.code }}</span>
              <span class="text-neutral-500"> — {{ row.account?.name }}</span>
            </td>
            <td class="px-3 py-2 text-right">
              <Button variant="ghost" size="sm" @click="destroy(row.id)">
                <Trash2 class="h-4 w-4 text-red-600" /> Hapus
              </Button>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="!rows.length" title="Belum ada mapping" description="Tambahkan COA untuk jenis unit pada form di atas." />
    </div>
  </div>
</template>
