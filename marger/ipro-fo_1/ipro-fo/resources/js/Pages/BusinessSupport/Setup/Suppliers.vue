<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Truck, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ suppliers: any; filters: Record<string, any> }>();

const search = ref(props.filters.search ?? '');
function display() { router.get(route('bs.suppliers.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({
    code: '', name: '', category: '', contact_person: '', phone: '', email: '',
    address: '', city: '', npwp: '', bank_name: '', bank_account_no: '', bank_account_name: '', is_active: true,
});

function openCreate() { editingId.value = null; form.reset(); form.is_active = true; showModal.value = true; }
function openEdit(s: any) {
    editingId.value = s.id;
    Object.assign(form, {
        code: s.code, name: s.name, category: s.category ?? '', contact_person: s.contact_person ?? '', phone: s.phone ?? '',
        email: s.email ?? '', address: s.address ?? '', city: s.city ?? '', npwp: s.npwp ?? '',
        bank_name: s.bank_name ?? '', bank_account_no: s.bank_account_no ?? '', bank_account_name: s.bank_account_name ?? '', is_active: !!s.is_active,
    });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.suppliers.update', editingId.value), opts) : form.post(route('bs.suppliers.store'), opts);
}
function destroy(s: any) { if (!confirm(`Hapus supplier ${s.name}?`)) return; router.delete(route('bs.suppliers.destroy', s.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Truck class="h-6 w-6 text-primary-600" /> Supplier / Vendor</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Supplier</Button>
        </div>

        <div class="card flex items-end gap-3 p-4">
            <div class="flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="Kode / Nama / Kota" :class="inputCls" @keyup.enter="display" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3">Kontak</th><th class="px-4 py-3">Kota</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="s in suppliers.data" :key="s.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ s.code }}</td>
                        <td class="px-4 py-2.5">{{ s.name }}</td>
                        <td class="px-4 py-2.5">{{ s.category ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ s.contact_person ?? '—' }}<span v-if="s.phone" class="block text-xs text-neutral-400">{{ s.phone }}</span></td>
                        <td class="px-4 py-2.5">{{ s.city ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs">{{ s.bank_name ? `${s.bank_name} ${s.bank_account_no ?? ''}` : '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="s.is_active ? 'success' : 'neutral'" size="sm">{{ s.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2">
                            <button @click="openEdit(s)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(s)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!suppliers.data?.length" title="Belum ada supplier" description="Klik Tambah Supplier." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900 max-h-[90vh] overflow-y-auto">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Supplier</h2>
                    <form @submit.prevent="submit" class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode <span class="text-neutral-400">(otomatis)</span></label><input v-model="form.code" :disabled="!editingId" placeholder="dibuat sistem (SUP-xxxx)" :class="[inputCls, !editingId ? 'bg-neutral-100 dark:bg-neutral-800' : '']" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori</label><input v-model="form.category" placeholder="material / jasa / kontraktor" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kontak / PIC</label><input v-model="form.contact_person" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Telepon</label><input v-model="form.phone" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Email</label><input v-model="form.email" type="email" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kota</label><input v-model="form.city" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">NPWP * <span class="text-neutral-400">(16 angka)</span></label><input v-model="form.npwp" inputmode="numeric" maxlength="16" required :class="inputCls" /><p v-if="form.errors.npwp" class="mt-1 text-xs text-danger-500">{{ form.errors.npwp }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Alamat</label><input v-model="form.address" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank *</label><input v-model="form.bank_name" required :class="inputCls" /><p v-if="form.errors.bank_name" class="mt-1 text-xs text-danger-500">{{ form.errors.bank_name }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening *</label><input v-model="form.bank_account_no" required :class="inputCls" /><p v-if="form.errors.bank_account_no" class="mt-1 text-xs text-danger-500">{{ form.errors.bank_account_no }}</p></div>
                        <div class="col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Atas Nama Rekening *</label><input v-model="form.bank_account_name" required :class="inputCls" /><p v-if="form.errors.bank_account_name" class="mt-1 text-xs text-danger-500">{{ form.errors.bank_account_name }}</p></div>
                        <label class="col-span-2 flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="col-span-2 flex justify-end gap-2 pt-2">
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
