<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, BookOpen, ArrowLeft, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    accounts: Array<any>;
    types: Record<string, string>;
    filters: Record<string, any>;
    stats: { total: number; postable: number };
}>();

const search = ref(props.filters.search ?? '');
const typeFilter = ref(props.filters.type ?? '');
function display() {
    router.get(route('bs.accounts.index'), { search: search.value || undefined, type: typeFilter.value || undefined }, { preserveState: true, replace: true });
}

const typeColor: Record<string, any> = { asset: 'info', liability: 'warning', equity: 'primary', income: 'success', expense: 'danger' };
const levelName: Record<number, string> = { 1: 'Kategori', 2: 'Sub Kategori', 3: 'Ledger', 4: 'Detil', 5: 'Detil Transaksi', 6: 'Detil Sub Transaksi' };

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ code: '', name: '', type: 'asset', normal_balance: 'D', is_postable: true, is_active: true });

function openCreate() { editingId.value = null; form.reset(); showModal.value = true; }
function openEdit(a: any) {
    editingId.value = a.id;
    form.code = a.code; form.name = a.name; form.type = a.type; form.normal_balance = a.normal_balance;
    form.is_postable = !!a.is_postable; form.is_active = !!a.is_active;
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.accounts.update', editingId.value), opts) : form.post(route('bs.accounts.store'), opts);
}
function destroy(a: any) {
    if (!confirm(`Hapus akun ${a.code} - ${a.name}?`)) return;
    router.delete(route('bs.accounts.destroy', a.id), { preserveScroll: true });
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Chart of Account</h1>
                <p class="text-sm text-neutral-500">Struktur 6 level eProperty: Kategori › Sub Kategori › Ledger › Detil › Detil Transaksi › Detil Sub Transaksi (postable). <span class="font-medium">{{ stats.total }} akun · {{ stats.postable }} postable.</span></p>
            </div>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Akun</Button>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="relative flex-1 min-w-[220px]">
                <Search class="pointer-events-none absolute left-3 top-[34px] h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <input v-model="search" type="text" placeholder="Kode / Nama akun" :class="[inputCls,'pl-9']" @keyup.enter="display" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Tipe</label>
                <select v-model="typeFilter" :class="inputCls"><option value="">Semua</option><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select>
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kode / Akun</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Tipe</th>
                        <th class="px-4 py-3 text-center">Normal</th><th class="px-4 py-3 text-center">Postable</th><th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800/60">
                    <tr v-for="a in accounts" :key="a.id" :class="!a.is_postable ? 'bg-neutral-50/60 dark:bg-neutral-800/30' : ''">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2" :style="{ paddingLeft: ((a.level || 6) - 1) * 18 + 'px' }">
                                <span class="font-mono text-xs" :class="a.is_postable ? 'text-neutral-500' : 'font-semibold text-neutral-400'">{{ a.code }}</span>
                                <span :class="a.is_postable ? 'font-medium' : 'font-bold uppercase tracking-wide text-neutral-700 dark:text-neutral-200'">{{ a.name }}</span>
                                <span v-if="!a.is_active" class="rounded bg-neutral-200 px-1.5 text-[10px] text-neutral-500 dark:bg-neutral-700">nonaktif</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs text-neutral-400">{{ levelName[a.level] ?? a.level }}</td>
                        <td class="px-4 py-2"><Badge v-if="a.is_postable" :variant="typeColor[a.type] ?? 'neutral'" size="sm">{{ types[a.type] }}</Badge></td>
                        <td class="px-4 py-2 text-center font-mono text-xs">{{ a.is_postable ? a.normal_balance : '' }}</td>
                        <td class="px-4 py-2 text-center">{{ a.is_postable ? '✓' : '—' }}</td>
                        <td class="px-4 py-2"><div class="flex justify-end gap-2">
                            <button @click="openEdit(a)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                            <button @click="destroy(a)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!accounts?.length" title="Belum ada akun" description="Klik Tambah Akun atau jalankan seeder CoA." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Akun</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode Akun *</label><input v-model="form.code" required :class="inputCls" /><p class="mt-1 text-[11px] text-neutral-400">Panjang kode menentukan level (1/3/5/7/9/11 digit → Kategori…Detil Sub Transaksi).</p><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Akun *</label><input v-model="form.name" required :class="inputCls" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe *</label><select v-model="form.type" :class="inputCls"><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Saldo Normal *</label><select v-model="form.normal_balance" :class="inputCls"><option value="D">Debit (D)</option><option value="K">Kredit (K)</option></select></div>
                        </div>
                        <div class="flex gap-5">
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_postable" type="checkbox" class="rounded" /> Bisa dijurnal</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
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
