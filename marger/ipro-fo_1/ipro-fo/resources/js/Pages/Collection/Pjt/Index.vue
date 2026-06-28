<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ rows: Array<any>; filters: Record<string, any> }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
// #120: tanggal saja (tanpa waktu).
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }

function regenerate() {
    if (confirm('Generate PJT ulang sekarang?')) router.post(route('pjt.regenerate'));
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">PJT (Piutang Jatuh Tempo)</h1>
            <Button variant="secondary" @click="regenerate">Regenerate</Button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. PJT</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Keterangan (Angsuran/DP)</th>
                        <th class="px-4 py-3">Jatuh Tempo</th>
                        <th class="px-4 py-3 text-right">Jumlah</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.pjt_number }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ r.unit }}</td>
                        <td class="px-4 py-2.5">{{ r.customer }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.keterangan }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.due_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.status === 'open' ? 'warning' : 'neutral'" size="sm">{{ r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada PJT terbuka" description="Klik Regenerate untuk membentuk PJT dari jadwal yang jatuh tempo." />
        </div>
    </div>
</template>
