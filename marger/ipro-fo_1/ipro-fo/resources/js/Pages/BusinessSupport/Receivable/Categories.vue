<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, Tags, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    categories: Array<any>;
    filters: { search?: string };
    accounts: Array<any>;
    defaultReceivable: number | null;
}>();

const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('bs.ar.categories.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', income_account_id: '', receivable_account_id: props.defaultReceivable ?? '', is_active: true });
function openCreate() { editingId.value = null; form.reset(); form.receivable_account_id = props.defaultReceivable ?? ''; showModal.value = true; }
function openEdit(c: any) {
    editingId.value = c.id;
    Object.assign(form, { code: c.code, name: c.name, income_account_id: c.income_account_id, receivable_account_id: c.receivable_account_id, is_active: !!c.is_active });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.ar.categories.update', editingId.value), opts) : form.post(route('bs.ar.categories.store'), opts);
}
function destroy(c: any) { if (confirm(`Hapus kategori ${c.code}?`)) router.delete(route('bs.ar.categories.destroy', c.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Tags class="h-6 w-6 text-success-600" /> Setup Kategori Invoice <span class="text-base font-normal text-neutral-400">({{ categories.length }})</span></h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Kategori</Button>
        </div>
        <p class="text-sm text-neutral-500">Kategori menentukan akun pendapatan otomatis saat input invoice piutang. Akun piutang biasanya Piutang Usaha.</p>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari kategori…" :class="[inputCls,'pl-9']" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Akun Pendapatan</th><th class="px-4 py-3">Akun Piutang</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in categories" :key="c.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ c.code }}</td>
                        <td class="px-4 py-2.5">{{ c.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ c.income }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ c.receivable }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="c.is_active ? 'success' : 'neutral'" size="sm">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2"><button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button><button @click="destroy(c)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!categories.length" title="Belum ada kategori" description="Klik Kategori untuk menambah." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Invoice</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required placeholder="mis. DENDA-KETERLAMBATAN" :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Pendapatan *</label><select v-model="form.income_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Piutang *</label><select v-model="form.receivable_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Simpan</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
