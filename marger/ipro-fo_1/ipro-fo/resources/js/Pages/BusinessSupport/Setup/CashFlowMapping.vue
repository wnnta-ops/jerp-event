<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Trash2, GitMerge, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    mappings: any[];
    accounts: Array<{ id: number; code: string; name: string }>;
    categories: Array<{ id: number; code: string; name: string; activity: string }>;
}>();

const activityLabel: Record<string, string> = { operating: 'Operasi', investing: 'Investasi', financing: 'Pendanaan' };

const showModal = ref(false);
const form = useForm({ account_id: '' as any, category_id: '' as any });
function openCreate() { form.reset(); showModal.value = true; }
function submit() { form.post(route('bs.gl.cf-mapping.store'), { onSuccess: () => { showModal.value = false; } }); }
function destroy(m: any) { if (!confirm(`Hapus mapping akun ${m.account?.code}?`)) return; router.delete(route('bs.gl.cf-mapping.destroy', m.id), { preserveScroll: true }); }
function changeCategory(m: any, categoryId: number) { router.put(route('bs.gl.cf-mapping.update', m.id), { account_id: m.account_id, category_id: categoryId }, { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><GitMerge class="h-6 w-6 text-primary-600" /> Mapping Cash Flow Direct</h1>
            <Button @click="openCreate" :disabled="!categories.length"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Mapping</Button>
        </div>
        <p v-if="!categories.length" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Buat dulu <Link :href="route('bs.gl.cf-category.index', { kind: 'direct' })" class="underline">Kategori Cash Flow Direct</Link> sebelum melakukan mapping.</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Akun</th><th class="px-4 py-3">Kategori Arus Kas</th><th class="px-4 py-3">Aktivitas</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in mappings" :key="m.id">
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ m.account?.code }}</span> {{ m.account?.name }}</td>
                        <td class="px-4 py-2.5">
                            <select :value="m.category_id" @change="changeCategory(m, ($event.target as HTMLSelectElement).value as any)" :class="inputCls">
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                            </select>
                        </td>
                        <td class="px-4 py-2.5"><Badge variant="neutral" size="sm">{{ activityLabel[m.category?.activity] ?? '—' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right"><button @click="destroy(m)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!mappings.length" title="Belum ada mapping" description="Petakan akun CoA ke kategori arus kas untuk laporan metode Direct." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Tambah Mapping</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun (ketik kode / nama) *</label><SearchSelect v-model="form.account_id" :options="accounts" placeholder="— cari akun —" /><p v-if="form.errors.account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.account_id }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Arus Kas *</label>
                            <select v-model="form.category_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select>
                        </div>
                        <p class="text-xs text-neutral-400">Satu akun hanya bisa dipetakan ke satu kategori. Menyimpan akun yang sudah ada akan menimpa mapping lama.</p>
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
