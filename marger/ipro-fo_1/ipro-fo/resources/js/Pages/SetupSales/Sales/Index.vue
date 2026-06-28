<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Users, Plus, Search, Pencil, Trash2, X } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rows: any; filters: { search?: string; active?: string }; managers: Array<any>; users: Array<any> }>();

const search = ref(props.filters.search ?? '');
function apply() { router.get(route('setup-sales.sales.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ code: '', name: '', type: 'non_arebi', level: 'sales', phone: '', email: '', manager_id: null as number | null, user_id: null as number | null, joined_at: '', is_active: true });

const levelLabel: Record<string, string> = { kantor: 'Kantor', koordinator: 'Koordinator', sales: 'Sales' };
// Atasan sesuai level: koordinator → list Kantor; sales → list Koordinator; kantor → tanpa atasan.
const eligibleManagers = computed(() => {
    if (form.level === 'koordinator') return props.managers.filter((m: any) => m.level === 'kantor');
    if (form.level === 'sales') return props.managers.filter((m: any) => m.level === 'koordinator');
    return [];
});
watch(() => form.level, () => { if (form.level === 'kantor') form.manager_id = null; });

function openCreate() { editingId.value = null; form.reset(); form.clearErrors(); showForm.value = true; }
function openEdit(r: any) { editingId.value = r.id; form.code = r.code; form.name = r.name; form.type = r.type ?? 'non_arebi'; form.level = r.level ?? 'sales'; form.phone = r.phone ?? ''; form.email = r.email ?? ''; form.manager_id = r.manager_id; form.user_id = r.user_id; form.joined_at = r.joined_at ? r.joined_at.substring(0, 10) : ''; form.is_active = !!r.is_active; form.clearErrors(); showForm.value = true; }
function submit() {
    const opts = { onSuccess: () => { showForm.value = false; } };
    if (editingId.value) form.put(route('setup-sales.sales.update', editingId.value), opts);
    else form.post(route('setup-sales.sales.store'), opts);
}
function del(r: any) { if (confirm(`Hapus sales ${r.name}?`)) router.delete(route('setup-sales.sales.destroy', r.id)); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Master Sales / Marketing</h1>
            <button @click="openCreate" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Plus class="h-4 w-4" /> Sales Baru</button>
        </div>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari kode / nama…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Kontak</th><th class="px-4 py-3">Atasan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2">{{ r.name }}</td>
                        <td class="px-4 py-2"><span class="text-xs">{{ r.type === 'arebi' ? 'AREBI' : 'Non-AREBI' }}</span></td>
                        <td class="px-4 py-2"><Badge size="sm" :variant="r.level === 'kantor' ? 'primary' : r.level === 'koordinator' ? 'info' : 'neutral'">{{ levelLabel[r.level] ?? r.level ?? '—' }}</Badge></td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.phone || '—' }}<span v-if="r.email"> · {{ r.email }}</span></td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.manager?.name ?? '—' }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <button @click="openEdit(r)" class="mr-1 text-neutral-400 hover:text-primary-600"><Pencil class="h-4 w-4" /></button>
                            <button @click="del(r)" class="text-neutral-400 hover:text-danger-600"><Trash2 class="h-4 w-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="6" class="px-4 py-8 text-center text-neutral-400">Belum ada sales.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>

        <!-- Modal form -->
        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showForm = false">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Sales</h3><button @click="showForm = false"><X class="h-5 w-5 text-neutral-400" /></button></div>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                    <div><label :class="labelCls">Kode *</label><input v-model="form.code" :class="inputCls" required /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-600">{{ form.errors.code }}</p></div>
                    <div><label :class="labelCls">Nama *</label><input v-model="form.name" :class="inputCls" required /></div>
                    <div><label :class="labelCls">Tipe *</label><select v-model="form.type" :class="inputCls"><option value="arebi">AREBI</option><option value="non_arebi">Non-AREBI</option></select></div>
                    <div><label :class="labelCls">Level *</label><select v-model="form.level" :class="inputCls"><option value="kantor">Kantor</option><option value="koordinator">Koordinator</option><option value="sales">Sales</option></select></div>
                    <div v-if="form.level !== 'kantor'"><label :class="labelCls">Atasan ({{ form.level === 'koordinator' ? 'Kantor' : 'Koordinator' }}) *</label><select v-model="form.manager_id" :class="inputCls" required><option :value="null">— pilih —</option><option v-for="m in eligibleManagers" :key="m.id" :value="m.id">{{ m.name }}</option></select><p v-if="form.errors.manager_id" class="mt-1 text-xs text-danger-600">{{ form.errors.manager_id }}</p><p v-if="!eligibleManagers.length" class="mt-1 text-xs text-amber-600">Belum ada data {{ form.level === 'koordinator' ? 'Kantor' : 'Koordinator' }}. Buat dulu.</p></div>
                    <div><label :class="labelCls">Telepon</label><input v-model="form.phone" :class="inputCls" /></div>
                    <div><label :class="labelCls">Email</label><input type="email" v-model="form.email" :class="inputCls" /></div>
                    <div><label :class="labelCls">Akun User</label><select v-model="form.user_id" :class="inputCls"><option :value="null">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                    <div><label :class="labelCls">Tgl Bergabung</label><input type="date" v-model="form.joined_at" :class="inputCls" /></div>
                    <div class="flex items-end"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded border-neutral-300" /> Aktif</label></div>
                    <div class="sm:col-span-2 mt-2 flex justify-end gap-2">
                        <button type="button" @click="showForm = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
