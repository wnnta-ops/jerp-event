<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, FileSpreadsheet, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ month: string; items: Array<any>; total: number }>();

const month = ref(props.month);
function display() { router.get(route('bs.cashbook.giro-calendar'), { month: month.value || undefined }, { preserveState: true, replace: true }); }
// #9: parameter unduhan mengikuti bulan yang sedang ditampilkan.
const exportParams = computed<Record<string, any>>(() => (props.month ? { month: props.month } : {}));
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short' }) : '—'; }

// kelompokkan per tanggal jatuh tempo
const grouped = computed(() => {
    const map: Record<string, any[]> = {};
    for (const it of props.items) (map[it.date] ??= []).push(it);
    return Object.entries(map).map(([date, list]) => ({ date, list, sum: list.reduce((s, x) => s + Number(x.amount || 0), 0) }));
});
const statusColor: Record<string, any> = { in_hand: 'info', cleared: 'success', bounced: 'danger', handed_over: 'warning', void: 'neutral' };
</script>

<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-primary-600" /> Kalender Cek Giro</h1>
            <div class="flex flex-wrap items-end gap-2">
                <input v-model="month" type="month" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
                <!-- #9: Excel + PDF mengikuti bulan terpilih -->
                <a :href="route('bs.cashbook.giro-calendar.excel', exportParams)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.cashbook.giro-calendar.pdf', exportParams)" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> PDF</a>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Jatuh tempo cek/giro <b>masuk</b> & <b>keluar</b> pada bulan terpilih. Total: <span class="font-mono font-semibold">Rp {{ fmtRp(total) }}</span>.</p>

        <div v-for="g in grouped" :key="g.date" class="card overflow-hidden">
            <div class="flex items-center justify-between bg-neutral-50 px-4 py-2 dark:bg-neutral-800">
                <span class="font-semibold">{{ fmtDate(g.date) }}</span>
                <span class="font-mono text-sm">Rp {{ fmtRp(g.sum) }}</span>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(it, i) in g.list" :key="i">
                        <td class="px-4 py-2"><Badge :variant="it.arah === 'Masuk' ? 'success' : 'warning'" size="sm">{{ it.arah }}</Badge></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ it.no }}</td>
                        <td class="px-4 py-2">{{ it.pihak }}</td>
                        <td class="px-4 py-2"><Badge :variant="statusColor[it.status] ?? 'neutral'" size="sm">{{ it.status }}</Badge></td>
                        <td class="px-4 py-2 text-right font-mono font-semibold">Rp {{ fmtRp(it.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!items?.length" title="Tidak ada cek/giro jatuh tempo" description="Tidak ada cek/giro yang jatuh tempo pada bulan ini." />
    </div>
</template>
