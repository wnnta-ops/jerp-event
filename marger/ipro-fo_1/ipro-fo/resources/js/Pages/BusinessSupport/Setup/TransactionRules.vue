<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, FileCog, ArrowLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rules: any[];
    sources: Record<string, string>;
    filters: Record<string, any>;
}>();

const source = ref(props.filters.source ?? '');
function display() { router.get(route('bs.gl.transaction-rule.index'), { source: source.value || undefined }, { preserveState: true, replace: true }); }
function destroy(r: any) { if (!confirm(`Hapus rule ${r.code}?`)) return; router.delete(route('bs.gl.transaction-rule.destroy', r.id), { preserveScroll: true }); }
const srcColor: Record<string, any> = { ap: 'warning', ar: 'success', cash: 'info', inventory: 'primary', memorial: 'neutral' };
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCog class="h-6 w-6 text-primary-600" /> Transaction Rule</h1>
            <Link :href="route('bs.gl.transaction-rule.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Rule</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Pemetaan transaksi sumber (AP/AR/Kas/Persediaan/Memorial) ke template jurnal otomatis. Mencerminkan posting jurnal yang berjalan di sistem.</p>

        <div class="card flex items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber</label>
                <select v-model="source" :class="[inputCls, 'w-48']" @change="display"><option value="">— Semua —</option><option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option></select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Event</th><th class="px-4 py-3 text-center">Baris</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rules" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.gl.transaction-rule.edit', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2.5">{{ r.name }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="srcColor[r.source] ?? 'neutral'" size="sm">{{ sources[r.source] ?? r.source }}</Badge></td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.event ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5" @click.stop><div class="flex justify-end gap-2">
                            <Link :href="route('bs.gl.transaction-rule.edit', r.id)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></Link>
                            <button @click="destroy(r)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                        </div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rules.length" title="Belum ada Transaction Rule" description="Tambah rule untuk memetakan transaksi sumber ke template jurnal." />
        </div>
    </div>
</template>
