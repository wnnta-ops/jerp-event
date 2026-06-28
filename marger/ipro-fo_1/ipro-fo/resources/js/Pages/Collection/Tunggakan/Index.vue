<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    rows: Array<any>;
    summary: { ringan: number; sedang: number; berat: number; count: number; total: number };
    filters: Record<string, any>;
}>();

const category = ref(props.filters.category ?? '');
function applyFilter() {
    router.get(route('tunggakan.index'), { category: category.value || undefined }, { preserveState: true, replace: true });
}

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v ?? 0);
}
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
// Warna kategori aging: ringan=biru, sedang=kuning, berat=merah.
function colorFor(c: string) { return c === 'berat' ? 'danger' : c === 'sedang' ? 'warning' : 'info'; }
function agingCls(c: string) {
    return c === 'berat'
        ? 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300'
        : c === 'sedang'
            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
            : 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300';
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-bold">Tunggakan</h1>
            <p class="text-sm text-neutral-500">Daftar angsuran/DP yang menunggak per unit. Aging dihitung dari jatuh tempo ke hari ini — ringan ≤30 hari, sedang 31–90 hari, berat &gt;90 hari.</p>
        </div>

        <!-- Ringkasan kategori aging -->
        <div class="grid gap-3 sm:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Tunggakan</div><div class="mt-1 text-lg font-bold font-mono text-danger-600">{{ fmtRp(summary.total) }}</div><div class="text-[11px] text-neutral-400">{{ summary.count }} angsuran</div></div>
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><span class="h-2 w-2 rounded-full bg-sky-500"></span> Ringan</div><div class="mt-1 text-lg font-bold">{{ summary.ringan }}</div><div class="text-[11px] text-neutral-400">≤ 30 hari</div></div>
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><span class="h-2 w-2 rounded-full bg-amber-500"></span> Sedang</div><div class="mt-1 text-lg font-bold">{{ summary.sedang }}</div><div class="text-[11px] text-neutral-400">31–90 hari</div></div>
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><span class="h-2 w-2 rounded-full bg-danger-500"></span> Berat</div><div class="mt-1 text-lg font-bold">{{ summary.berat }}</div><div class="text-[11px] text-neutral-400">&gt; 90 hari</div></div>
        </div>

        <div class="flex items-center gap-2">
            <select v-model="category" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua kategori</option>
                <option value="ringan">Ringan (≤30 hari)</option>
                <option value="sedang">Sedang (31–90 hari)</option>
                <option value="berat">Berat (&gt;90 hari)</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Angsuran</th>
                        <th class="px-4 py-3">Jatuh Tempo</th>
                        <th class="px-4 py-3 text-center">Aging</th>
                        <th class="px-4 py-3 text-center">Kategori</th>
                        <th class="px-4 py-3 text-right">Tunggakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in rows" :key="t.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 font-mono font-semibold">{{ t.unit_code }}<div v-if="t.booking_no" class="text-[11px] font-normal text-neutral-400">{{ t.booking_no }}</div></td>
                        <td class="px-4 py-3"><span class="font-mono text-xs text-neutral-400">{{ t.customer_code }}</span> {{ t.customer }}</td>
                        <td class="px-4 py-3">{{ t.installment }}</td>
                        <td class="px-4 py-3">{{ fmtDate(t.due_date) }}</td>
                        <td class="px-4 py-3 text-center"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="agingCls(t.category)">{{ t.aging_days }} hari</span></td>
                        <td class="px-4 py-3 text-center"><Badge :variant="colorFor(t.category)" dot class="capitalize">{{ t.category }}</Badge></td>
                        <td class="px-4 py-3 text-right font-mono text-danger-600">{{ fmtRp(t.outstanding) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Tidak ada tunggakan" description="Semua angsuran masih dalam jadwal — tidak ada yang melewati jatuh tempo." />
        </div>
    </div>
</template>
