<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ rows: any }>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Faktur Pajak</h1>
            <Link :href="route('faktur-pajak.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Faktur Baru</Button></Link>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3 text-right">DPP</th>
                        <th class="px-4 py-3 text-right">PPN</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="f in rows.data" :key="f.id">
                        <td class="px-4 py-3 font-mono">{{ f.fp_number }}</td>
                        <td class="px-4 py-3">{{ f.issued_at }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(f.dpp) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(f.ppn) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-bold">{{ fmtRp(f.total) }}</td>
                        <td class="px-4 py-3 capitalize">{{ f.status }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada faktur pajak" />
        </div>
    </div>
</template>
