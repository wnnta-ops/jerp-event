<!-- Data Center / Supplier — Kategori Supplier master CRUD -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Tag, Plus, Pencil, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ rows: Array<any> }>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const showModal = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
  code: '',
  name: '',
  is_active: true,
  notes: '',
});

function openCreate() {
  editingId.value = null;
  form.reset();
  form.is_active = true;
  showModal.value = true;
}

function openEdit(row: any) {
  editingId.value = row.id;
  form.code = row.code ?? '';
  form.name = row.name ?? '';
  form.is_active = !!row.is_active;
  form.notes = row.notes ?? '';
  showModal.value = true;
}

function submit() {
  if (editingId.value) {
    form.put(route('bs.dc.supplier.category.update', editingId.value), {
      preserveScroll: true,
      onSuccess: () => (showModal.value = false),
    });
  } else {
    form.post(route('bs.dc.supplier.category.store'), {
      preserveScroll: true,
      onSuccess: () => (showModal.value = false),
    });
  }
}

function destroy(id: number) {
  if (!confirm('Hapus data ini?')) return;
  router.delete(route('bs.dc.supplier.category.destroy', id), { preserveScroll: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Data Center
    </Link>

    <div class="flex items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Tag class="h-6 w-6 text-primary-600" /> Kategori Supplier
      </h1>
      <Button @click="openCreate">
        <Plus class="h-4 w-4" /> Tambah
      </Button>
    </div>

    <div v-if="props.rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase text-neutral-500 bg-neutral-50 dark:bg-neutral-800">
            <th class="px-3 py-2">Kode</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2">Catatan</th>
            <th class="px-3 py-2 text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="row in props.rows" :key="row.id">
            <td class="px-3 py-2 font-medium">{{ row.code }}</td>
            <td class="px-3 py-2">{{ row.name }}</td>
            <td class="px-3 py-2">
              <Badge :variant="row.is_active ? 'success' : 'neutral'">
                {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
              </Badge>
            </td>
            <td class="px-3 py-2 text-neutral-500">{{ row.notes }}</td>
            <td class="px-3 py-2">
              <div class="flex items-center justify-end gap-2">
                <Button size="sm" variant="ghost" @click="openEdit(row)">
                  <Pencil class="h-4 w-4" /> Edit
                </Button>
                <Button size="sm" variant="ghost" @click="destroy(row.id)">
                  <Trash2 class="h-4 w-4" /> Hapus
                </Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Belum ada kategori" description="Tambahkan kategori supplier untuk mulai mengelompokkan data." />

    <Teleport to="body">
      <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
        <div class="card w-full max-w-md space-y-4">
          <h2 class="text-lg font-semibold">{{ editingId ? 'Edit Kategori' : 'Tambah Kategori' }}</h2>

          <form class="space-y-3" @submit.prevent="submit">
            <div>
              <label class="mb-1 block text-sm font-medium">Kode <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :class="inputCls" required />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium">Nama <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text" :class="inputCls" required />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium">Catatan</label>
              <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
              <p v-if="form.errors.notes" class="mt-1 text-xs text-red-500">{{ form.errors.notes }}</p>
            </div>

            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-neutral-300 dark:border-neutral-700" />
              Aktif
            </label>

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
