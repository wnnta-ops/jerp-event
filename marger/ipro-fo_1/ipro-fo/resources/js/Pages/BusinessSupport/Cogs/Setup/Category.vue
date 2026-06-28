<!-- COGS Setup: Set Akun Budget — master CRUD kategori budget → komponen HPP & COA default -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Tag, Plus, Pencil, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  components: Record<string, string>;
  accounts: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
  code: '',
  name: '',
  component: '',
  default_account_id: '' as string | number,
  is_active: true,
});

function openCreate() {
  editingId.value = null;
  form.reset();
  showModal.value = true;
}
function openEdit(row: any) {
  editingId.value = row.id;
  form.code = row.code;
  form.name = row.name;
  form.component = row.component;
  form.default_account_id = row.default_account_id ?? '';
  form.is_active = !!row.is_active;
  showModal.value = true;
}
function submit() {
  const o = { onSuccess: () => { showModal.value = false; } };
  editingId.value
    ? form.put(route('bs.cogs.category.update', editingId.value), o)
    : form.post(route('bs.cogs.category.store'), o);
}
function destroy(id: number) {
  if (confirm('Hapus?')) router.delete(route('bs.cogs.category.destroy', id), { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Tag class="h-6 w-6 text-primary-600" /> Set Akun Budget</h1>
        <p class="mt-1 text-sm text-neutral-500">Kategori Budget → komponen HPP &amp; COA default.</p>
      </div>
      <Button @click="openCreate"><Plus class="h-4 w-4" /> Tambah</Button>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Kode</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Komponen HPP</th>
            <th class="px-3 py-2">COA</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="row in rows" :key="row.id">
            <td class="px-3 py-2 font-medium">{{ row.code }}</td>
            <td class="px-3 py-2">{{ row.name }}</td>
            <td class="px-3 py-2">{{ components[row.component] ?? row.component }}</td>
            <td class="px-3 py-2">{{ row.default_account ? row.default_account.code + ' ' + row.default_account.name : '—' }}</td>
            <td class="px-3 py-2"><Badge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
            <td class="px-3 py-2">
              <div class="flex justify-end gap-1">
                <Button size="sm" variant="ghost" @click="openEdit(row)"><Pencil class="h-4 w-4" /></Button>
                <Button size="sm" variant="ghost" @click="destroy(row.id)"><Trash2 class="h-4 w-4 text-red-600" /></Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="!rows.length" title="Belum ada kategori" description="Tambahkan kategori budget untuk memetakan komponen HPP & COA default." />
    </div>

    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
        <div class="card w-full max-w-md space-y-4">
          <h2 class="text-lg font-semibold">{{ editingId ? 'Edit Kategori' : 'Tambah Kategori' }}</h2>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-sm font-medium">Kode <span class="text-red-500">*</span></label>
              <input v-model="form.code" :class="inputCls" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-500">*</span></label>
              <input v-model="form.name" :class="inputCls" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium">Komponen HPP <span class="text-red-500">*</span></label>
              <select v-model="form.component" :class="inputCls">
                <option value="" disabled>Pilih komponen</option>
                <option v-for="(lbl, key) in components" :key="key" :value="key">{{ lbl }}</option>
              </select>
              <p v-if="form.errors.component" class="mt-1 text-xs text-red-500">{{ form.errors.component }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium">COA Default</label>
              <select v-model="form.default_account_id" :class="inputCls">
                <option value="">—</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
              </select>
              <p v-if="form.errors.default_account_id" class="mt-1 text-xs text-red-500">{{ form.errors.default_account_id }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" v-model="form.is_active" class="rounded border-neutral-300 dark:border-neutral-700" />
              Aktif
            </label>
          </div>

          <div class="flex justify-end gap-2">
            <Button variant="ghost" @click="showModal = false">Batal</Button>
            <Button :disabled="form.processing" @click="submit">Simpan</Button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
