<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Ban } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    categories: any;
    filters: Record<string, any>;
}>();

const search = ref(props.filters.search ?? '');
function display() {
    router.get(route('kpr.setup.reject-bank.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ name: '', sort_order: 0, is_active: true });

function openCreate() {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    showModal.value = true;
}
function openEdit(c: any) {
    editingId.value = c.id;
    form.name = c.name;
    form.sort_order = c.sort_order ?? 0;
    form.is_active = !!c.is_active;
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    if (editingId.value) form.put(route('kpr.setup.reject-bank.update', editingId.value), opts);
    else form.post(route('kpr.setup.reject-bank.store'), opts);
}
function destroy(c: any) {
    if (!confirm(`Hapus kategori "${c.name}"?`)) return;
    router.delete(route('kpr.setup.reject-bank.destroy', c.id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Ban class="h-6 w-6 text-primary-600" /> Kategori Reject Bank
            </h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Add New</Button>
        </div>
        <p class="text-sm text-neutral-500">Setting KPR — daftar alasan/kategori <b>penolakan KPR oleh bank</b>, dipakai sebagai pilihan alasan saat proses KPR ditolak.</p>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[200px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <input v-model="search" type="text" placeholder="Nama kategori" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" />
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3 w-16 text-center">Urutan</th>
                        <th class="px-4 py-3">Nama Kategori</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in categories.data" :key="c.id">
                        <td class="px-4 py-3 text-center tabular-nums text-neutral-500">{{ c.sort_order }}</td>
                        <td class="px-4 py-3 font-medium">{{ c.name }}</td>
                        <td class="px-4 py-3"><Badge :variant="c.is_active ? 'success' : 'neutral'" dot>{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800" title="Edit"><Pencil class="h-4 w-4" /></button>
                                <button @click="destroy(c)" class="text-danger-500 hover:text-danger-700" title="Hapus"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!categories.data?.length" title="Belum ada kategori" description="Klik Add New untuk menambah alasan penolakan bank." />
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Reject Bank</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama Kategori *</label>
                            <input v-model="form.name" type="text" required placeholder="mis. RPC KURANG" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="w-28">
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Urutan</label>
                                <input v-model.number="form.sort_order" type="number" min="0" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                            </div>
                            <label class="mt-5 flex items-center gap-2 text-sm">
                                <input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif
                            </label>
                        </div>
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
