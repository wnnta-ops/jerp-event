<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText, Lock, Unlock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    months: Array<any>;
    ytd: { income: number; expense: number; profit: number };
    yearClosed: boolean;
    closingJournalId: number | null;
    allClosed: boolean;
}>();

const year = ref(props.year);
const monthNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
const closeM = ref(Math.min(12, Math.max(1, new Date().getMonth() + 1)));
const selectedStatus = computed(() => props.months.find((m: any) => m.month === closeM.value)?.status ?? 'open');
const selectedClosed = computed(() => selectedStatus.value === 'closed');

function changeYear() { router.get(route('bs.closing.index'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function closeMonth(m: number) { if (confirm(`Tutup (kunci) periode ${monthNames[m-1]} ${props.year}? Jurnal tidak bisa ditambah / diedit di periode terkunci hingga dibuka kembali.`)) router.post(route('bs.closing.close'), { year: props.year, month: m }, { preserveScroll: true }); }
function reopenMonth(m: number) { if (confirm(`Buka kembali periode ${monthNames[m-1]} ${props.year}?`)) router.post(route('bs.closing.reopen'), { year: props.year, month: m }, { preserveScroll: true }); }
function closeSelected() { selectedClosed.value ? reopenMonth(closeM.value) : closeMonth(closeM.value); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> Closing Fiskal <span class="text-base font-normal text-neutral-400">(Closing Bulanan)</span></h1>
            <Link :href="route('bs.closing.neraca', { year: year + 1 })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Neraca Awal {{ year + 1 }}</Link>
        </div>

        <!-- YTD P/L -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pendapatan {{ year }}</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(ytd.income) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Beban {{ year }}</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(ytd.expense) }}</div></div>
            <div class="card p-4" :class="ytd.profit >= 0 ? 'ring-1 ring-success-200 dark:ring-success-900' : 'ring-1 ring-danger-200 dark:ring-danger-900'"><div class="text-xs uppercase text-neutral-400">{{ ytd.profit >= 0 ? 'Laba' : 'Rugi' }} Tahun Berjalan</div><div class="mt-1 text-xl font-bold" :class="ytd.profit >= 0 ? 'text-success-600' : 'text-danger-600'">{{ fmtRp(Math.abs(ytd.profit)) }}</div></div>
        </div>

        <!-- Closing Bulanan: pilih bulan & tahun untuk ditutup / dibuka -->
        <div class="card flex flex-wrap items-end justify-between gap-4 p-4">
            <div class="max-w-md">
                <div class="text-sm font-semibold">Closing Bulanan</div>
                <div class="text-xs text-neutral-500">Pilih <b>bulan</b> &amp; <b>tahun</b>, lalu kunci periodenya. Periode terkunci menolak jurnal baru dan tidak dapat diedit hingga dibuka kembali.</div>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Tahun</label>
                    <select v-model.number="year" @change="changeYear" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Bulan</label>
                    <select v-model.number="closeM" class="h-9 w-40 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(nm, i) in monthNames" :key="i" :value="i+1">{{ nm }}</option></select>
                </div>
                <div class="pb-1.5">
                    <Badge :variant="selectedClosed ? 'neutral' : 'success'" size="sm">{{ selectedClosed ? 'Terkunci' : 'Terbuka' }}</Badge>
                </div>
                <Button :variant="selectedClosed ? 'secondary' : 'primary'" @click="closeSelected">
                    <template #icon-left><Unlock v-if="selectedClosed" class="h-4 w-4" /><Lock v-else class="h-4 w-4" /></template>
                    {{ selectedClosed ? 'Buka Periode' : 'Tutup Periode' }}
                </Button>
            </div>
        </div>

        <!-- Status per bulan -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Bulan</th><th class="px-4 py-3 text-right">Pendapatan</th><th class="px-4 py-3 text-right">Beban</th><th class="px-4 py-3 text-right">Laba/Rugi</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in months" :key="m.month" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="m.month === closeM ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                        <td class="px-4 py-2.5 font-medium">{{ monthNames[m.month-1] }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(m.income) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(m.expense) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="m.profit >= 0 ? 'text-neutral-700 dark:text-neutral-200' : 'text-danger-600'">{{ fmtRp(m.profit) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="m.status === 'closed' ? 'neutral' : 'success'" size="sm">{{ m.status === 'closed' ? 'Terkunci' : 'Terbuka' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="m.status !== 'closed'" @click="closeMonth(m.month)" class="inline-flex items-center gap-1 text-xs text-neutral-600 hover:text-neutral-900 dark:text-neutral-300"><Lock class="h-3.5 w-3.5" /> Tutup</button>
                            <button v-else @click="reopenMonth(m.month)" class="inline-flex items-center gap-1 text-xs text-primary-600 hover:underline"><Unlock class="h-3.5 w-3.5" /> Buka</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-neutral-400">Periode terkunci (Closed) menolak jurnal baru bertanggal di bulan tersebut dan mengunci jurnal dari pengeditan — dijaga oleh General Ledger — hingga periode dibuka kembali.</p>
    </div>
</template>
