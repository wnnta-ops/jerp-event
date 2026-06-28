<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, PackagePlus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ receipts: any }>();
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><PackagePlus class="h-6 w-6 text-success-600" /> Penerimaan Barang</h1>
            <Link :href="route('bs.receipt.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Terima Barang</Button></Link>
        </div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Gudang</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3 text-center">Item</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in receipts.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.trx_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.trx_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.warehouse?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!receipts.data?.length" title="Belum ada penerimaan" description="Klik Terima Barang." />
        </div>
    </div>
</template>
