<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Gauge, ReceiptText, Landmark, CalendarClock, AlertTriangle, CheckCircle2, Clock } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    months: Array<any>;
    totals: { ppn_keluaran: number; ppn_masukan: number; ppn_kurang_bayar: number; pph_terutang: number };
    lastMasa: number;
    reminders: Array<any>;
}>();

const year = ref(props.year);
const monthNames = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function changeYear() { router.get(route('bs.tax.dashboard'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtRpShort(v: number) { const n = Number(v ?? 0); if (n === 0) return '—'; if (Math.abs(n) >= 1e9) return (n/1e9).toFixed(1)+' M'; if (Math.abs(n) >= 1e6) return (n/1e6).toFixed(0)+' jt'; return n.toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const statusMap: Record<string, { variant: any; label: string }> = { lewat: { variant: 'danger', label: 'Lewat tempo' }, segera: { variant: 'warning', label: 'Segera' }, aman: { variant: 'neutral', label: 'Terjadwal' } };

const maxBar = Math.max(1, ...props.months.flatMap((m: any) => [m.ppn_keluaran, m.ppn_masukan, m.pph_terutang]));
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.tax.rekap')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Faktur Pajak</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Gauge class="h-6 w-6 text-primary-600" /> Dashboard Pajak <span class="text-base font-normal text-neutral-400">{{ year }}</span></h1>
            <select v-model.number="year" @change="changeYear" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
        </div>

        <!-- KPI tahunan -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><ReceiptText class="h-3.5 w-3.5" /> PPN Keluaran</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(totals.ppn_keluaran) }}</div></div>
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><ReceiptText class="h-3.5 w-3.5" /> PPN Masukan</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(totals.ppn_masukan) }}</div></div>
            <div class="card p-4" :class="totals.ppn_kurang_bayar >= 0 ? 'ring-1 ring-amber-200 dark:ring-amber-900' : 'ring-1 ring-success-200 dark:ring-success-900'"><div class="text-xs uppercase text-neutral-400">PPN {{ totals.ppn_kurang_bayar >= 0 ? 'Kurang Bayar' : 'Lebih Bayar' }}</div><div class="mt-1 text-xl font-bold" :class="totals.ppn_kurang_bayar >= 0 ? 'text-amber-600' : 'text-success-600'">{{ fmtRp(Math.abs(totals.ppn_kurang_bayar)) }}</div></div>
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><Landmark class="h-3.5 w-3.5" /> PPh Terutang</div><div class="mt-1 text-xl font-bold text-accent-600">{{ fmtRp(totals.pph_terutang) }}</div></div>
        </div>

        <!-- Pengingat jatuh tempo -->
        <div class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><CalendarClock class="h-5 w-5 text-primary-600" /> Pengingat Jatuh Tempo <span class="text-sm font-normal text-neutral-400">(Masa {{ monthNames[lastMasa-1] }} {{ year }})</span></h2>
            <div class="grid gap-2 sm:grid-cols-3">
                <div v-for="(r, i) in reminders" :key="i" class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="flex items-center justify-between"><span class="text-sm font-medium">{{ r.label }}</span>
                        <Badge :variant="statusMap[r.status].variant" size="sm"><component :is="r.status==='lewat'?AlertTriangle:(r.status==='segera'?Clock:CheckCircle2)" class="mr-1 inline h-3 w-3" />{{ statusMap[r.status].label }}</Badge>
                    </div>
                    <div class="mt-1 font-mono text-sm">{{ fmtDate(r.date) }}</div>
                    <div v-if="r.amount !== null" class="text-xs text-neutral-500">Nilai: {{ fmtRp(r.amount) }}</div>
                    <div class="mt-0.5 text-[11px] text-neutral-400">{{ r.note }}</div>
                </div>
            </div>
        </div>

        <!-- Grafik 12 bulan -->
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Tren 12 Bulan {{ year }}</h2>
            <div class="flex items-end gap-1.5" style="height: 160px">
                <div v-for="m in months" :key="m.month" class="flex flex-1 flex-col items-center justify-end gap-0.5" :title="`${monthNames[m.month-1]}: Keluaran ${fmtRp(m.ppn_keluaran)}, Masukan ${fmtRp(m.ppn_masukan)}, PPh ${fmtRp(m.pph_terutang)}`">
                    <div class="flex w-full items-end justify-center gap-px" style="height: 130px">
                        <div class="w-1/3 rounded-t bg-success-500" :style="{ height: (m.ppn_keluaran/maxBar*100)+'%' }"></div>
                        <div class="w-1/3 rounded-t bg-danger-500" :style="{ height: (m.ppn_masukan/maxBar*100)+'%' }"></div>
                        <div class="w-1/3 rounded-t bg-accent-500" :style="{ height: (m.pph_terutang/maxBar*100)+'%' }"></div>
                    </div>
                    <div class="text-[10px] text-neutral-400">{{ monthNames[m.month-1] }}</div>
                </div>
            </div>
            <div class="mt-2 flex gap-4 text-xs text-neutral-500"><span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-success-500"></span> PPN Keluaran</span><span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-danger-500"></span> PPN Masukan</span><span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-accent-500"></span> PPh Terutang</span></div>
        </div>

        <!-- Tabel 12 bulan -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Masa</th><th class="px-4 py-3 text-right">PPN Keluaran</th><th class="px-4 py-3 text-right">PPN Masukan</th><th class="px-4 py-3 text-right">PPN K/(L) Bayar</th><th class="px-4 py-3 text-right">PPh Terutang</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in months" :key="m.month" :class="m.has_data ? '' : 'text-neutral-400'">
                        <td class="px-4 py-2 font-medium">{{ monthNames[m.month-1] }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRpShort(m.ppn_keluaran) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRpShort(m.ppn_masukan) }}</td>
                        <td class="px-4 py-2 text-right font-mono" :class="m.ppn_kurang_bayar > 0 ? 'text-amber-600' : ''">{{ fmtRpShort(m.ppn_kurang_bayar) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-accent-600">{{ fmtRpShort(m.pph_terutang) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800"><td class="px-4 py-2.5">Total</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.ppn_keluaran) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.ppn_masukan) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.ppn_kurang_bayar) }}</td><td class="px-4 py-2.5 text-right font-mono text-accent-600">{{ fmtRp(totals.pph_terutang) }}</td></tr></tfoot>
            </table>
        </div>
    </div>
</template>
