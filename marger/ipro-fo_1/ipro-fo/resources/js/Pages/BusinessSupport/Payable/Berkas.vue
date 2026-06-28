<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, FolderCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ berkas: Array<any> }>();
const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', is_mandatory: true, is_active: true });
function openCreate() { editingId.value = null; form.reset(); showModal.value = true; }
function openEdit(b: any) { editingId.value = b.id; Object.assign(form, { code: b.code, name: b.name, is_mandatory: !!b.is_mandatory, is_active: !!b.is_active }); showModal.value = true; }
function submit() { const opts = { onSuccess: () => { showModal.value = false; } }; editingId.value ? form.put(route('bs.ap.berkas.update', editingId.value), opts) : form.post(route('bs.ap.berkas.store'), opts); }
function destroy(b: any) { if (confirm(`Hapus berkas ${b.code}?`)) router.delete(route('bs.ap.berkas.destroy', b.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FolderCheck class="h-6 w-6 text-danger-600" /> Setup Kelengkapan Berkas</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Berkas</Button>
        </div>
        <p class="text-sm text-neutral-500">Daftar dokumen wajib untuk invoice hutang (mis. Faktur, Kuitansi, BAP, Surat Jalan). Muncul sebagai checklist di tiap invoice.</p>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Berkas</th><th class="px-4 py-3 text-center">Wajib</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="b in berkas" :key="b.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ b.code }}</td>
                        <td class="px-4 py-2.5">{{ b.name }}</td>
                        <td class="px-4 py-2.5 text-center">{{ b.is_mandatory ? '✓' : '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="b.is_active ? 'success' : 'neutral'" size="sm">{{ b.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2"><button @click="openEdit(b)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button><button @click="destroy(b)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!berkas.length" title="Belum ada kelengkapan berkas" description="Klik Berkas untuk menambah dokumen wajib." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kelengkapan Berkas</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required placeholder="mis. FAKTUR-PAJAK" :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Berkas *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div class="flex gap-5"><label class="flex items-center gap-2 text-sm"><input v-model="form.is_mandatory" type="checkbox" class="rounded" /> Wajib</label><label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label></div>
                        <div class="flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Simpan</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
