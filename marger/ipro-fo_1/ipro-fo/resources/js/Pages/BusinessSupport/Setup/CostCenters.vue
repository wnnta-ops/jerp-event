<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Building2, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    costCenters: any;
    projects: Array<{ id: number; name: string }>;
    filters: Record<string, any>;
}>();

const search = ref(props.filters.search ?? '');
function display() { router.get(route('bs.cost-centers.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ project_id: '' as any, code: '', name: '', manager: '', is_active: true });

function openCreate() { editingId.value = null; form.reset(); form.is_active = true; showModal.value = true; }
function openEdit(c: any) {
    editingId.value = c.id; form.project_id = c.project_id ?? ''; form.code = c.code; form.name = c.name; form.manager = c.manager ?? ''; form.is_active = !!c.is_active;
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.cost-centers.update', editingId.value), opts) : form.post(route('bs.cost-centers.store'), opts);
}
function destroy(c: any) { if (!confirm(`Hapus cost center ${c.code}?`)) return; router.delete(route('bs.cost-centers.destroy', c.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Building2 class="h-6 w-6 text-primary-600" /> Cost Center / Divisi</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah</Button>
        </div>

        <div class="card flex items-end gap-3 p-4">
            <div class="flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="Kode / Nama" :class="inputCls" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Project</th><th class="px-4 py-3">Manager</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in costCenters.data" :key="c.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ c.code }}</td>
                        <td class="px-4 py-2.5">{{ c.name }}</td>
                        <td class="px-4 py-2.5">{{ c.project?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ c.manager ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="c.is_active ? 'success' : 'neutral'" size="sm">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2">
                            <button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(c)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!costCenters.data?.length" title="Belum ada cost center" />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Cost Center</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project</label><select v-model="form.project_id" :class="inputCls"><option value="">— (semua) —</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Manager</label><input v-model="form.manager" :class="inputCls" /></div>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
