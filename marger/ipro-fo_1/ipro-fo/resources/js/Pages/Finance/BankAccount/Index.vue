<script setup lang="ts">
import { ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { Landmark, Plus, Search, Pencil, Trash2, X } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rows: any; filters: { search?: string }; companies: Array<any> }>();

const search = ref(props.filters.search ?? '');
function apply() { router.get(route('rekening.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ company_id: null as number | null, account_name: '', account_number: '', bank_name: '', branch: '', is_virtual_pool: false, is_active: true });

function openCreate() { editingId.value = null; form.reset(); form.clearErrors(); showForm.value = true; }
function openEdit(r: any) { editingId.value = r.id; Object.assign(form, { company_id: r.company_id, account_name: r.account_name, account_number: r.account_number, bank_name: r.bank_name, branch: r.branch ?? '', is_virtual_pool: !!r.is_virtual_pool, is_active: !!r.is_active }); form.clearErrors(); showForm.value = true; }
function submit() {
    const opts = { onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.put(route('rekening.update', editingId.value), opts);
    else form.post(route('rekening.store'), opts);
}
function del(r: any) { if (confirm(`Hapus rekening ${r.bank_name} ${r.account_number}?`)) router.delete(route('rekening.destroy', r.id)); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> Setup Rekening Bank</h1>
            <button @click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Plus class="h-4 w-4" /> Rekening Baru</button>
        </div>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari bank / no. rekening / atas nama…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Bank</th><th class="px-4 py-3">No. Rekening</th><th class="px-4 py-3">Atas Nama</th><th class="px-4 py-3">Cabang</th><th class="px-4 py-3">Perusahaan</th><th class="px-4 py-3 text-center">VA Pool</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-semibold">{{ r.bank_name }}</td>
                        <td class="px-4 py-2 font-mono">{{ r.account_number }}</td>
                        <td class="px-4 py-2">{{ r.account_name }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.branch || '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.company?.name ?? '—' }}</td>
                        <td class="px-4 py-2 text-center"><Badge v-if="r.is_virtual_pool" variant="primary" size="sm">VA</Badge><span v-else class="text-neutral-300">—</span></td>
                        <td class="px-4 py-2 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button @click="openEdit(r)" class="mr-1 text-neutral-400 hover:text-primary-600"><Pencil class="h-4 w-4" /></button>
                            <button @click="del(r)" class="text-neutral-400 hover:text-danger-600"><Trash2 class="h-4 w-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="8" class="px-4 py-8 text-center text-neutral-400">Belum ada rekening.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>

        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showForm = false">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Rekening</h3><button @click="showForm = false"><X class="h-5 w-5 text-neutral-400" /></button></div>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                    <div><label :class="labelCls">Bank *</label><input v-model="form.bank_name" :class="inputCls" required /><p v-if="form.errors.bank_name" class="mt-1 text-xs text-danger-600">{{ form.errors.bank_name }}</p></div>
                    <div><label :class="labelCls">No. Rekening *</label><input v-model="form.account_number" :class="inputCls" required /></div>
                    <div class="sm:col-span-2"><label :class="labelCls">Atas Nama *</label><input v-model="form.account_name" :class="inputCls" required /></div>
                    <div><label :class="labelCls">Cabang</label><input v-model="form.branch" :class="inputCls" /></div>
                    <div><label :class="labelCls">Perusahaan</label><select v-model="form.company_id" :class="inputCls"><option :value="null">—</option><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_virtual_pool" class="rounded border-neutral-300" /> Kolam Virtual Account</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded border-neutral-300" /> Aktif</label>
                    <div class="sm:col-span-2 mt-2 flex justify-end gap-2">
                        <button type="button" @click="showForm = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
