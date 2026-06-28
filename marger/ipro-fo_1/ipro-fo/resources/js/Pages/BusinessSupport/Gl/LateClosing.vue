<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    periods: Array<{ month: number; status: string; closed_at: string | null; late_closed: boolean; late_note: string | null; overdue: boolean }>;
    today: string;
}>();

const monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const yearInput = ref(props.year);
function display() { router.get(route('bs.gl.late-closing'), { year: yearInput.value }, { preserveState: true, replace: true }); }
function lateClose(m: number) {
    const note = prompt(`Late Closing — alasan penutupan terlambat periode ${m}/${props.year}:`, '');
    if (note === null) return;
    router.post(route('bs.gl.late-closing.close'), { year: props.year, month: m, note }, { preserveScroll: true });
}
const statusColor: Record<string, any> = { open: 'info', final: 'warning', approved: 'primary', closed: 'neutral' };
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-primary-600" /> Late Closing</h1>
            <div class="flex items-end gap-2">
                <input v-model.number="yearInput" type="number" class="h-9 w-24 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Tutup periode mundur yang sudah lewat jatuh tempo namun belum dikunci, dengan mencatat alasan keterlambatan. Per {{ today }}.</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Bulan</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Ditutup</th><th class="px-4 py-3">Late</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in periods" :key="p.month" :class="{ 'bg-amber-50 dark:bg-amber-900/15': p.overdue }">
                        <td class="px-4 py-2.5 font-medium">{{ p.month }} <span class="text-xs text-neutral-400">{{ monthNames[p.month] }}</span></td>
                        <td class="px-4 py-2.5"><Badge :variant="statusColor[p.status]" dot>{{ p.status }}</Badge> <Badge v-if="p.overdue" variant="warning" size="sm" class="ml-1">jatuh tempo</Badge></td>
                        <td class="px-4 py-2.5">{{ fmtDate(p.closed_at) }}</td>
                        <td class="px-4 py-2.5"><span v-if="p.late_closed" :title="p.late_note ?? ''"><Badge variant="danger" size="sm">Late</Badge></span><span v-else class="text-neutral-300">—</span></td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="p.status !== 'closed'" @click="lateClose(p.month)" class="text-xs font-medium text-danger-500 hover:underline">Tutup (Late)</button>
                            <span v-else class="text-xs text-neutral-400">terkunci</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-neutral-400">Catatan: penutupan reguler tetap lewat menu Tutup Buku (Closing). Late Closing khusus untuk periode terlambat & menandai alasannya untuk audit.</p>
    </div>
</template>
