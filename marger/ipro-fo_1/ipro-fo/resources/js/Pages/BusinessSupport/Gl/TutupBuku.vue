<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText, Lock, Unlock, CheckCircle2 } from 'lucide-vue-next';
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
function changeYear() { router.get(route('bs.closing.index'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

function closeMonth(m: number) { if (confirm(`Tutup (kunci) periode ${monthNames[m-1]} ${props.year}? Jurnal tidak bisa ditambah ke periode terkunci.`)) router.post(route('bs.closing.close'), { year: props.year, month: m }, { preserveScroll: true }); }
function reopenMonth(m: number) { if (confirm(`Buka kembali periode ${monthNames[m-1]} ${props.year}?`)) router.post(route('bs.closing.reopen'), { year: props.year, month: m }, { preserveScroll: true }); }
function closeYearNow() { if (confirm(`Tutup Buku tahun ${props.year}? Jurnal penutup akan nol-kan Pendapatan & Beban ke Laba Ditahan, lalu mengunci seluruh 12 bulan.`)) router.post(route('bs.closing.year'), { year: props.year }, { preserveScroll: true }); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> Tutup Buku <span class="text-base font-normal text-neutral-400">(Periode &amp; Jurnal Penutup)</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.closing.neraca', { year: year + 1 })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><BookText class="h-4 w-4" /> Neraca Awal {{ year + 1 }}</Link>
                <select v-model.number="year" @change="changeYear" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
            </div>
        </div>

        <!-- YTD P/L -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pendapatan {{ year }}</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(ytd.income) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Beban {{ year }}</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(ytd.expense) }}</div></div>
            <div class="card p-4" :class="ytd.profit >= 0 ? 'ring-1 ring-success-200 dark:ring-success-900' : 'ring-1 ring-danger-200 dark:ring-danger-900'"><div class="text-xs uppercase text-neutral-400">{{ ytd.profit >= 0 ? 'Laba' : 'Rugi' }} Tahun Berjalan</div><div class="mt-1 text-xl font-bold" :class="ytd.profit >= 0 ? 'text-success-600' : 'text-danger-600'">{{ fmtRp(Math.abs(ytd.profit)) }}</div></div>
        </div>

        <!-- Year close banner / action -->
        <div v-if="yearClosed" class="card flex items-center gap-3 p-4 ring-1 ring-success-200 dark:ring-success-900">
            <CheckCircle2 class="h-6 w-6 text-success-600" />
            <div class="flex-1 text-sm"><div class="font-semibold">Tahun {{ year }} sudah ditutup</div><div class="text-neutral-500">Jurnal penutup <Link :href="route('bs.gl.journal.show', closingJournalId)" class="text-primary-600 hover:underline">#{{ closingJournalId }}</Link> — Pendapatan &amp; Beban telah dipindahkan ke Laba Ditahan.</div></div>
        </div>
        <div v-else class="card flex flex-wrap items-center justify-between gap-3 p-4">
            <div class="text-sm"><div class="font-semibold">Tutup Buku Tahunan</div><div class="text-neutral-500">Buat jurnal penutup (L/R → Laba Ditahan) &amp; kunci seluruh 12 bulan {{ year }}.</div></div>
            <Button variant="primary" @click="closeYearNow"><template #icon-left><Lock class="h-4 w-4" /></template>Tutup Buku {{ year }}</Button>
        </div>

        <!-- Periods table -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Bulan</th><th class="px-4 py-3 text-right">Pendapatan</th><th class="px-4 py-3 text-right">Beban</th><th class="px-4 py-3 text-right">Laba/Rugi</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in months" :key="m.month" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
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
        <p class="text-xs text-neutral-400">Periode terkunci menolak jurnal baru bertanggal di bulan tersebut (dijaga oleh General Ledger). Tutup Buku Tahunan memindahkan saldo Pendapatan &amp; Beban ke Laba Ditahan.</p>
    </div>
</template>
