<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, Landmark, Link2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    banks: any;
    projects: Array<{ id: number; name: string }>;
    filters: Record<string, any>;
}>();

const search = ref(props.filters.search ?? '');
const projectId = ref(props.filters.project_id ?? '');

function display() {
    router.get(route('kpr.setup.bank.index'), {
        search: search.value || undefined,
        project_id: projectId.value || undefined,
    }, { preserveState: true, replace: true });
}
function destroy(b: any) {
    if (!confirm(`Hapus bank "${b.name}"?`)) return;
    router.delete(route('kpr.setup.bank.destroy', b.id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Landmark class="h-6 w-6 text-primary-600" /> Kategori Bank
            </h1>
            <Link :href="route('kpr.setup.bank.create')">
                <Button><template #icon-left><Plus class="h-4 w-4" /></template>Add New</Button>
            </Link>
        </div>
        <p class="text-sm text-neutral-500">Setting KPR — identitas bank rekanan KPR + ketentuan retensi tiap bank.</p>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[200px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
                <input v-model="search" type="text" placeholder="Kode / Nama / No. Rekening" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @keyup.enter="display" />
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

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kode Bank</th>
                        <th class="px-4 py-3">Project</th>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Cabang</th>
                        <th class="px-4 py-3">No. Rekening</th>
                        <th class="px-4 py-3 text-center">Retensi</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="b in banks.data" :key="b.id">
                        <td class="px-4 py-3 font-semibold">{{ b.code }}</td>
                        <td class="px-4 py-3">{{ b.project?.name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ b.name }}</td>
                        <td class="px-4 py-3">{{ b.branch ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ b.account_no ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex flex-wrap items-center justify-center gap-1">
                                <Badge :variant="b.retention_terms_count ? 'info' : 'neutral'">{{ b.retention_terms_count }} kategori</Badge>
                                <!-- Kesiapan COA: akun Debit di Approval Retensi terisi otomatis bila sudah di-link-kan. -->
                                <span v-if="b.retention_terms_count" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold ring-1"
                                    :class="b.retention_coa_count === b.retention_terms_count
                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800/60'
                                        : 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60'"
                                    :title="`${b.retention_coa_count} dari ${b.retention_terms_count} kategori sudah di-link-kan ke COA Bank`">
                                    <Link2 class="h-3 w-3 shrink-0" /> {{ b.retention_coa_count }}/{{ b.retention_terms_count }} COA
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Link :href="route('kpr.setup.bank.edit', b.id)" class="text-primary-600 hover:text-primary-800" title="Edit"><Pencil class="h-4 w-4" /></Link>
                                <button @click="destroy(b)" class="text-danger-500 hover:text-danger-700" title="Hapus"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!banks.data?.length" title="Belum ada bank" description="Klik Add New untuk menambah bank rekanan KPR." />
        </div>
        <Pagination :data="banks" />
    </div>
</template>
