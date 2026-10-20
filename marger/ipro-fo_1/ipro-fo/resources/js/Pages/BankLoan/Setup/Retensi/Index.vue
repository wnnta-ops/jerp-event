<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref, reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Percent } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    categories: any;
    projects: Array<{ id: number; name: string }>;
    filters: Record<string, any>;
    // Daftar pilihan datang dari server (RetentionCategory::KPR_SYNC) — disalin di sini,
    // menambah satu pilihan berarti mengingat dua tempat dan yang terlewat hanya menolak
    // simpan tanpa menjelaskan kenapa.
    syncOptions: Record<string, string>;
}>();

const search = ref(props.filters.search ?? '');
const projectId = ref(props.filters.project_id ?? '');

function display() {
    router.get(route('kpr.setup.retensi.index'), {
        search: search.value || undefined,
        project_id: projectId.value || undefined,
    }, { preserveState: true, replace: true });
}

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm({ project_id: '' as any, code: '', name: '', percentage: 0, is_dajam: false, kpr_sync: '', keterangan: '', sort_order: 0, is_active: true });

function openCreate() {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    form.is_dajam = false;
    showModal.value = true;
}
function openEdit(c: any) {
    editingId.value = c.id;
    form.project_id = c.project_id ?? '';
    form.code = c.code;
    form.name = c.name;
    form.percentage = Number(c.percentage ?? 0);
    form.is_dajam = !!c.is_dajam;
    form.kpr_sync = c.kpr_sync ?? '';
    form.keterangan = c.keterangan ?? '';
    form.sort_order = c.sort_order ?? 0;
    form.is_active = !!c.is_active;
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    if (editingId.value) form.put(route('kpr.setup.retensi.update', editingId.value), opts);
    else form.post(route('kpr.setup.retensi.store'), opts);
}
function destroy(c: any) {
    if (!confirm(`Hapus kategori retensi "${c.name}"?`)) return;
    router.delete(route('kpr.setup.retensi.destroy', c.id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Percent class="h-6 w-6 text-primary-600" /> Kategori Retensi
            </h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Add New</Button>
        </div>
        <p class="text-sm text-neutral-500">Setting KPR — daftar kategori retensi (tahapan pembangunan) untuk pencairan KPR.</p>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[200px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <input v-model="search" type="text" placeholder="Kode / Nama" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
                <select v-model="projectId" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">Semua</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Project</th>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Sinkron Master Data KPR</th>
                        <th class="px-4 py-3 text-right">Persentase</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in categories.data" :key="c.id">
                        <td class="px-4 py-3 font-semibold">{{ c.code }}<Badge v-if="c.is_dajam" variant="warning" size="sm" class="ml-2">DAJAM</Badge></td>
                        <td class="px-4 py-3">{{ c.project?.name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ c.name }}<div v-if="c.keterangan" class="text-xs text-neutral-400">{{ c.keterangan }}</div></td>
                        <!-- Yang belum sinkron ditandai di sini, bukan hanya ditolak saat disimpan:
                             tanpa penanda, operator tidak punya cara melihat kategori mana yang
                             masih berdiri sebagai kolom sendirian di Master Data KPR. -->
                        <td class="px-4 py-3">
                            <Badge v-if="c.kpr_sync" variant="info" size="sm">{{ syncOptions[c.kpr_sync] ?? c.kpr_sync }}</Badge>
                            <Badge v-else variant="warning" size="sm">Belum sinkron</Badge>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ Number(c.percentage ?? 0).toFixed(2) }}%</td>
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
            <EmptyState v-if="!categories.data?.length" title="Belum ada kategori retensi" description="Klik Add New untuk menambah." />
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Retensi</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
                            <select v-model="form.project_id" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">— (semua project) —</option>
                                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kategori (Kode) *</label>
                            <input v-model="form.code" type="text" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label>
                            <input v-model="form.name" type="text" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                        </div>
                        <!-- Sinkron dengan Master Data KPR — menentukan KOLOM mana di layar itu
                             yang menampung pencairan kategori ini. Kategori yang bersinkron sama
                             berdiri sebagai SATU kolom (mis. TANAH MATANG 50%/20%/30%). Wajib:
                             yang belum disinkronkan akan berdiri sebagai kolomnya sendiri dan
                             terbaca seperti kategori terpisah, padahal hanya belum diisi. -->
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Sinkron dengan Master Data KPR *</label>
                            <select v-model="form.kpr_sync" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">— pilih kolom —</option>
                                <option v-for="(label, key) in syncOptions" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.kpr_sync" class="mt-1 text-xs text-danger-500">{{ form.errors.kpr_sync }}</p>
                            <p v-else class="mt-1 text-xs text-neutral-400">Kolom Collection Retensi tempat pencairan kategori ini dijumlahkan.</p>
                        </div>
                        <!-- #69 persentase default + DAJAM -->
                        <div class="flex items-end gap-4">
                            <div class="w-32">
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Persentase (%)</label>
                                <input v-model.number="form.percentage" type="number" step="0.01" min="0" max="100" :disabled="form.is_dajam" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm disabled:bg-neutral-100 disabled:text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900 dark:disabled:bg-neutral-800" />
                                <p v-if="form.errors.percentage" class="mt-1 text-xs text-danger-500">{{ form.errors.percentage }}</p>
                            </div>
                            <label class="mb-2 flex items-center gap-2 text-sm">
                                <input v-model="form.is_dajam" type="checkbox" class="rounded" /> DAJAM (pengurangan retensi)
                            </label>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan <span class="text-neutral-400">(mis. DAJAM apa)</span></label>
                            <textarea v-model="form.keterangan" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                            <p v-if="form.errors.keterangan" class="mt-1 text-xs text-danger-500">{{ form.errors.keterangan }}</p>
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
        <Pagination :data="categories" />
    </div>
</template>
