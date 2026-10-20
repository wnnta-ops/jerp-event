<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, HardHat, Search } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Stepper from '@/Components/UI/Stepper.vue';

// Tahapan SPK (progres, gaya Inisiasi KPR)
const SPK_STAGES = [
    { label: 'SPK Terbit', short: 'Terbit' },
    { label: 'Progress', short: 'Progress' },
    { label: 'Retensi', short: 'Retensi' },
    { label: 'Selesai', short: 'Selesai' },
];
function spkCurrent(r: any): number {
    if (r.status === 'closed') return 5;                          // semua tahap selesai
    const cv = Number(r.contract_value) || 0;
    const pv = Number(r.progress_value) || 0;
    if (cv > 0 && pv >= cv) return 3;                             // fisik 100% → fase retensi
    if (pv > 0) return 2;                                         // progress berjalan
    return 1;                                                     // baru terbit
}

const props = defineProps<{
    workOrders: any;
    filters: { status?: string; search?: string };
    summary: { contract: number; progress: number; retention: number; active: number };
}>();
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('bs.spk.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
function pct(p: number, c: number) { return c > 0 ? Math.min(100, Math.round(p / c * 100)) : 0; }
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Kontraktor (SPK)</h1>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Kontrak</div><div class="mt-1 text-base font-bold">{{ fmtRp(summary.contract) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Progress</div><div class="mt-1 text-base font-bold text-primary-600">{{ fmtRp(summary.progress) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Retensi Ditahan</div><div class="mt-1 text-base font-bold text-amber-600">{{ fmtRp(summary.retention) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">SPK Aktif</div><div class="mt-1 text-base font-bold">{{ summary.active }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari no. SPK / judul…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua status</option><option value="active">Aktif</option><option value="closed">Selesai</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. SPK</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Kontraktor</th><th class="px-4 py-3">Pekerjaan</th><th class="px-4 py-3 text-right">Nilai Kontrak</th><th class="px-4 py-3 w-32">Progress</th><th class="px-4 py-3">Tahapan</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in workOrders.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.spk.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.spk_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.spk_date) }}</td>
                        <td class="px-4 py-2.5">{{ r.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.title }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.contract_value) }}</td>
                        <td class="px-4 py-2.5"><div class="flex items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700"><div class="h-full bg-primary-500" :style="{ width: pct(r.progress_value, r.contract_value) + '%' }"></div></div><span class="text-xs text-neutral-400">{{ pct(r.progress_value, r.contract_value) }}%</span></div></td>
                        <td class="px-4 py-2.5"><Stepper compact :stages="SPK_STAGES" :current="spkCurrent(r)" :cancelled="r.status === 'cancelled'" /></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!workOrders.data?.length" title="Belum ada SPK" description="SPK terbit otomatis dari proses kontrak kontraktor." />
        </div>
        <Pagination :data="workOrders" />
    </div>
</template>
