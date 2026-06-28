<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, LayoutList } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    movements: any;
    summary: Array<{ code: string; name: string; on_hand: number }>;
    items: Array<any>;
    warehouses: Array<any>;
    filters: Record<string, any>;
}>();

const itemId = ref(props.filters.item_id ?? '');
const warehouseId = ref(props.filters.warehouse_id ?? '');
function display() { router.get(route('bs.stockcard.index'), { item_id: itemId.value || undefined, warehouse_id: warehouseId.value || undefined }, { preserveState: true, replace: true }); }
function num(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><LayoutList class="h-6 w-6 text-primary-600" /> Kartu Stok</h1>

        <!-- On-hand summary -->
        <div class="card p-4">
            <h2 class="mb-2 text-sm font-semibold">Saldo Stok (On-Hand)</h2>
            <div v-if="summary.length" class="flex flex-wrap gap-2">
                <div v-for="s in summary" :key="s.code" class="rounded-lg border border-neutral-200 px-3 py-1.5 text-sm dark:border-neutral-700">
                    <span class="font-mono text-neutral-500">{{ s.code }}</span> {{ s.name }}: <span class="font-bold">{{ num(s.on_hand) }}</span>
                </div>
            </div>
            <p v-else class="text-sm text-neutral-400">Belum ada stok.</p>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Item</label><select v-model="itemId" :class="inputCls"><option value="">Semua</option><option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} - {{ i.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Gudang</label><select v-model="warehouseId" :class="inputCls"><option value="">Semua</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Gudang</th><th class="px-4 py-3">Arah</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3">Referensi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in movements.data" :key="m.id">
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(m.moved_at) }}</td>
                        <td class="px-4 py-2.5">{{ m.item?.code }} - {{ m.item?.name }}</td>
                        <td class="px-4 py-2.5">{{ m.warehouse?.name }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="m.direction==='in' ? 'success' : 'warning'" size="sm">{{ m.direction === 'in' ? 'Masuk' : 'Keluar' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ num(m.qty) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ num(m.balance_after) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ m.ref_number ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!movements.data?.length" title="Belum ada pergerakan stok" />
        </div>
    </div>
</template>
