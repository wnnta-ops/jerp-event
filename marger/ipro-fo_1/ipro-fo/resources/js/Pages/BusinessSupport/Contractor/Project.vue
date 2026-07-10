<!-- Setup Project — contractor project master CRUD with modal -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, FolderGit2, Plus, Pencil, Trash2, ChevronRight } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ projects: Array<any> }>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ code: '', name: '', dept: '', is_active: true, notes: '' });

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    open.value = true;
}
function openEdit(r: any) {
    editingId.value = r.id;
    form.clearErrors();
    form.code = r.code;
    form.name = r.name;
    form.dept = r.dept ?? '';
    form.is_active = !!r.is_active;
    form.notes = r.notes ?? '';
    open.value = true;
}
function close() { open.value = false; }
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => close() };
    if (editingId.value) form.put(route('bs.spk.project.update', editingId.value), opts);
    else form.post(route('bs.spk.project.store'), opts);
}
function destroy(id: number) {
    if (!confirm('Hapus project ini?')) return;
    router.delete(route('bs.spk.project.destroy', id), { preserveScroll: true });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FolderGit2 class="h-6 w-6 text-amber-600" /> Setup Project</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Project</Button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Dept</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in projects" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.spk.project.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold"><span class="inline-flex items-center gap-1.5 text-primary-600"><ChevronRight class="h-3.5 w-3.5" />{{ r.code }}</span></td>
                        <td class="px-4 py-2.5">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.dept || '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.is_active ? 'success' : 'danger'" size="sm">{{ r.is_active ? 'active' : 'inactive' }}</Badge></td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end gap-2">
                                <button @click.stop="openEdit(r)" class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-primary-600 dark:hover:bg-neutral-800"><Pencil class="h-4 w-4" /></button>
                                <button @click.stop="destroy(r.id)" class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!projects?.length" title="Belum ada project" description="Klik Tambah Project untuk membuat master project kontraktor." />
        </div>

        <Teleport to="body">
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="close">
                <div class="card w-full max-w-md p-5">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit Project' : 'Tambah Project' }}</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kode <span class="text-red-500">*</span></label>
                            <input v-model="form.code" :class="inputCls" required />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama <span class="text-red-500">*</span></label>
                            <input v-model="form.name" :class="inputCls" required />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Dept</label>
                            <input v-model="form.dept" :class="inputCls" />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-neutral-300 dark:border-neutral-700" />
                            Aktif
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label>
                            <textarea v-model="form.notes" rows="3" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="secondary" @click="close">Batal</Button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
