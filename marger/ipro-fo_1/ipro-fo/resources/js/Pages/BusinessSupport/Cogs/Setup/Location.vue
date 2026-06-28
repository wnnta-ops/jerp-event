<!-- COGS Setup: Lokasi — master CRUD for storage/cost locations -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, MapPin, Plus, Pencil, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  projects: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
  code: '',
  name: '',
  project_id: '' as string | number | '',
  is_active: true,
  notes: '',
});

function projectName(id: any): string {
  const p = props.projects.find((x: any) => x.id === id);
  return p ? p.name : '—';
}

function openCreate() {
  editingId.value = null;
  form.reset();
  showModal.value = true;
}

function openEdit(row: any) {
  editingId.value = row.id;
  form.code = row.code;
  form.name = row.name;
  form.project_id = row.project_id ?? '';
  form.is_active = !!row.is_active;
  form.notes = row.notes ?? '';
  showModal.value = true;
}

function submit() {
  const o = { onSuccess: () => { showModal.value = false; } };
  editingId.value
    ? form.put(route('bs.cogs.location.update', editingId.value), o)
    : form.post(route('bs.cogs.location.store'), o);
}

function destroy(id: number) {
  if (confirm('Hapus?')) router.delete(route('bs.cogs.location.destroy', id), { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div class="flex items-center justify-between">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><MapPin class="h-6 w-6 text-primary-600" /> Lokasi</h1>
      <Button @click="openCreate"><Plus class="h-4 w-4" /> Tambah</Button>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase text-neutral-500 bg-neutral-50 dark:bg-neutral-800">
            <th class="px-3 py-2">Kode</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-medium">{{ r.code }}</td>
            <td class="px-3 py-2">{{ r.name }}</td>
            <td class="px-3 py-2">{{ projectName(r.project_id) }}</td>
            <td class="px-3 py-2">
              <Badge :variant="r.is_active ? 'success' : 'neutral'">{{ r.is_active ? 'active' : 'inactive' }}</Badge>
            </td>
            <td class="px-3 py-2">
              <div class="flex items-center justify-end gap-2">
                <Button variant="ghost" size="sm" @click="openEdit(r)"><Pencil class="h-4 w-4" /> Edit</Button>
                <Button variant="ghost" size="sm" @click="destroy(r.id)"><Trash2 class="h-4 w-4" /> Hapus</Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Belum ada lokasi" description="Tambahkan lokasi untuk mulai mengelola data COGS." />

    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
        <div class="card w-full max-w-lg space-y-4">
          <h2 class="text-lg font-semibold">{{ editingId ? 'Edit Lokasi' : 'Tambah Lokasi' }}</h2>

          <form class="space-y-3" @submit.prevent="submit">
            <div>
              <label class="mb-1 block text-sm font-medium">Kode <span class="text-red-500">*</span></label>
              <input v-model="form.code" :class="inputCls" required />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-500">*</span></label>
              <input v-model="form.name" :class="inputCls" required />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium">Project</label>
              <select v-model="form.project_id" :class="inputCls">
                <option value="">—</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
              </select>
              <p v-if="form.errors.project_id" class="mt-1 text-xs text-red-500">{{ form.errors.project_id }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" v-model="form.is_active" class="rounded border-neutral-300 dark:border-neutral-700" />
              Aktif
            </label>

            <div>
              <label class="mb-1 block text-sm font-medium">Catatan</label>
              <textarea v-model="form.notes" rows="3" :class="inputCls + ' py-2 h-auto'"></textarea>
              <p v-if="form.errors.notes" class="mt-1 text-xs text-red-500">{{ form.errors.notes }}</p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
              <Button type="button" variant="ghost" @click="showModal = false">Batal</Button>
              <Button type="submit" :disabled="form.processing">Simpan</Button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </div>
</template>
