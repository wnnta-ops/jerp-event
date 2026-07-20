<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, Boxes } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    items: any;
    categories: Array<any>;
    uoms: Array<any>;
    types: Record<string, string>;
    filters: Record<string, any>;
}>();

const search = ref(props.filters.search ?? '');
const categoryId = ref(props.filters.category_id ?? '');
function display() { router.get(route('bs.items.index'), { search: search.value || undefined, category_id: categoryId.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', brand: '', stock_category_id: '', uom_id: '', type: 'stock', min_stock: 0, is_active: true });

function openCreate() { editingId.value = null; form.reset(); form.type = 'stock'; form.is_active = true; showModal.value = true; }
function openEdit(i: any) {
    editingId.value = i.id;
    Object.assign(form, { code: i.code, name: i.name, brand: i.brand ?? '', stock_category_id: i.stock_category_id ?? '', uom_id: i.uom_id ?? '', type: i.type, min_stock: i.min_stock ?? 0, is_active: !!i.is_active });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.items.update', editingId.value), opts) : form.post(route('bs.items.store'), opts);
}
function destroy(i: any) { if (!confirm(`Hapus item ${i.code}?`)) return; router.delete(route('bs.items.destroy', i.id), { preserveScroll: true }); }
const typeColor: Record<string, any> = { stock: 'info', non_stock: 'neutral', jasa: 'warning' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Boxes class="h-6 w-6 text-primary-600" /> Item / Stok</h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Item</Button>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[200px]"><label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label><input v-model="search" placeholder="Kode / Nama / Merk" :class="inputCls" @keyup.enter="display" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori</label><select v-model="categoryId" :class="inputCls"><option value="">Semua</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Kategori</th><th class="px-4 py-3">Satuan</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3 text-right">On-Hand</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="i in items.data" :key="i.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ i.code }}</td>
                        <td class="px-4 py-2.5">{{ i.name }}<span v-if="i.brand" class="block text-xs text-neutral-400">{{ i.brand }}</span></td>
                        <td class="px-4 py-2.5">{{ i.category?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ i.uom?.code ?? '—' }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="typeColor[i.type]" size="sm">{{ types[i.type] }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(i.on_hand ?? 0).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="i.is_active ? 'success' : 'neutral'" size="sm">{{ i.is_active ? 'Aktif' : 'Off' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2">
                            <button @click="openEdit(i)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!items.data?.length" title="Belum ada item" description="Klik Tambah Item." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Item</h2>
                    <form @submit.prevent="submit" class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori</label><select v-model="form.stock_category_id" :class="inputCls"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Satuan</label><select v-model="form.uom_id" :class="inputCls"><option value="">—</option><option v-for="u in uoms" :key="u.id" :value="u.id">{{ u.code }} - {{ u.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe *</label><select v-model="form.type" :class="inputCls"><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Merk</label><input v-model="form.brand" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Min. Stok</label><input v-model.number="form.min_stock" type="number" step="0.01" min="0" :class="inputCls" /></div>
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
