<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, PackageMinus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ issues: any }>();
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><PackageMinus class="h-6 w-6 text-warning-600" /> Pengeluaran Barang</h1>
            <Link :href="route('bs.issue.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Keluarkan Barang</Button></Link>
        </div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No.</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Gudang</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3 text-center">Item</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in issues.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.trx_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.trx_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.warehouse?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ r.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ r.lines_count }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!issues.data?.length" title="Belum ada pengeluaran" description="Klik Keluarkan Barang." />
        </div>
        <Pagination :data="issues" />
    </div>
</template>
