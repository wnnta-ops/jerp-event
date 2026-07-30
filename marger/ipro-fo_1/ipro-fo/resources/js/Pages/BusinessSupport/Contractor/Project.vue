<!-- Setup Project — contractor project master CRUD with modal -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, FolderGit2, Plus, Pencil, Trash2, ChevronRight } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    projects: Array<any>;
    coaAccounts: Array<any>;
    salesProjects: Array<any>;
    clusters: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// Label COA (kode - nama) untuk kolom tabel.
const coaLabel = computed(() => {
    const m: Record<number, string> = {};
    (props.coaAccounts || []).forEach((a: any) => { m[a.id] = `${a.code} - ${a.name}`; });
    return m;
});

const open = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ code: '', name: '', dept: '', cluster: '', tahap: '', coa_account_id: '', is_active: true, notes: '' });

// Cluster dropdown di-scope ke sales project yang kodenya cocok dgn kode project;
// bila tak ada yg cocok → tampilkan semua (fallback). Nilai disimpan sbg LABEL (string).
const matchedProjectIds = computed<number[]>(() => {
    const code = String(form.code || '').trim().toLowerCase();
    if (!code) return [];
    return (props.salesProjects || []).filter((p: any) => String(p.code || '').trim().toLowerCase() === code).map((p: any) => p.id);
});
const clusterOptions = computed(() => {
    const ids = matchedProjectIds.value;
    const list = props.clusters || [];
    return ids.length ? list.filter((c: any) => ids.includes(c.project_id)) : list;
});
// Nilai tersimpan mungkin di luar daftar ter-scope (mis. master berubah) → tetap tampilkan.
const clusterInOptions = computed(() => (clusterOptions.value || []).some((c: any) => c.name === form.cluster));

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
    form.cluster = r.cluster ?? '';
    form.tahap = r.tahap ?? '';
    form.coa_account_id = r.coa_account_id ?? '';
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
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Dept</th><th class="px-4 py-3">Cluster / Tahap</th><th class="px-4 py-3">COA</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in projects" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.spk.project.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold"><span class="inline-flex items-center gap-1.5 text-primary-600"><ChevronRight class="h-3.5 w-3.5" />{{ r.code }}</span></td>
                        <td class="px-4 py-2.5">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.dept || '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ [r.cluster, r.tahap].filter(Boolean).join(' / ') || '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500 font-mono text-xs">{{ r.coa_account_id ? (coaLabel[r.coa_account_id] || '—') : '—' }}</td>
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
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Cluster</label>
                                <select v-model="form.cluster" :class="inputCls">
                                    <option value="">— pilih —</option>
                                    <option v-if="form.cluster && !clusterInOptions" :value="form.cluster">{{ form.cluster }} (tersimpan)</option>
                                    <option v-for="c in clusterOptions" :key="c.id" :value="c.name">{{ c.name }}</option>
                                </select>
                                <p v-if="form.errors.cluster" class="mt-1 text-xs text-red-500">{{ form.errors.cluster }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Tahap</label>
                                <input v-model="form.tahap" :class="inputCls" placeholder="mis. 5" />
                                <p v-if="form.errors.tahap" class="mt-1 text-xs text-red-500">{{ form.errors.tahap }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">COA (Akun Konstruksi/CIP default)</label>
                            <select v-model="form.coa_account_id" :class="inputCls">
                                <option value="">— pilih COA —</option>
                                <option v-for="a in coaAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
                            </select>
                            <p v-if="form.errors.coa_account_id" class="mt-1 text-xs text-red-500">{{ form.errors.coa_account_id }}</p>
                            <p v-else class="mt-1 text-[11px] text-neutral-400">Di-prefill sebagai Akun Konstruksi/CIP saat Register SPK.</p>
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
