<!-- General Ledger: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ section: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
const titles: Record<string, string> = {
    'general': 'General Function',
    'setup': 'Setup',
    'master': 'Master',
    'query': 'Query',
    'neraca': 'Neraca',
    'laba-rugi': 'Laba Rugi',
    'cf-direct': 'Cash Flow Direct',
    'cf-indirect': 'Cash Flow Indirect',
    'saldo-awal': 'Saldo Awal',
    'jurnal-memorial': 'Jurnal Memorial',
    'generate-lk': 'Generate Laporan Keuangan',
    'generate-lk-perusahaan': 'Generate Laporan Keuangan Perusahaan',
};
const sections = computed<Record<string, Item[]>>(() => ({
    'general': [
        { label: 'General Function', route: r('bs.gl.printhub') },
    ],
    'setup': [
        { label: 'Multi Currency', route: r('bs.gl.currency.index') },
        { label: 'Kurs Sistem', route: r('bs.gl.kurs.index') },
        { label: 'Repair Data', route: r('bs.gl.repair.index') },
        { label: 'Kategori Cash Flow Direct', route: r('bs.gl.cf-category.index', { kind: 'direct' }) },
        { label: 'Kategori Cash Flow Indirect', route: r('bs.gl.cf-category.index', { kind: 'indirect' }) },
    ],
    'master': [
        { label: 'Chart of Account', route: r('bs.accounts.index') },
        { label: 'Jurnal', route: r('bs.gl.journal.index') },
        { label: 'Cost Center', route: r('bs.cost-centers.index') },
        { label: 'Mapping Cash Flow Direct', route: r('bs.gl.cf-mapping.index') },
        { label: 'Dimensi Jurnal (Dept/Lokasi)', route: r('bs.journal-dimensions.index') },
    ],
    'query': [
        { label: 'Detail Transaksi Jurnal', route: r('bs.gl.journal.index') },
        { label: 'General Ledger (Buku Besar)', route: r('bs.gl.ledger') },
    ],
    'neraca': [
        { label: 'Neraca Saldo (Trial Balance)', route: r('bs.gl.trialbalance') },
        { label: 'Neraca (Balance Sheet)', route: r('bs.gl.balancesheet') },
        { label: 'Laba Rugi (Income Statement)', route: r('bs.gl.incomestatement') },
        { label: 'Neraca Lajur (Worksheet)', route: r('bs.gl.worksheet') },
        { label: 'Histori Saldo', route: r('bs.gl.extra', { mode: 'histori' }) },
        { label: 'Neraca | Multi Periode', route: r('bs.gl.extra', { mode: 'multi' }) },
        { label: 'Neraca Komparasi (Bulanan)', route: r('bs.gl.comparative') },
        { label: 'Neraca Komparasi (Tahunan)', route: r('bs.gl.extra', { mode: 'tahunan' }) },
    ],
    'laba-rugi': [
        { label: 'Laba Rugi vs Budget', route: r('bs.gl.lr-budget') },
        { label: 'Laba Rugi | Multi Periode', route: r('bs.gl.extra', { mode: 'lr-multi' }) },
        { label: 'Laba Rugi Komparasi (Bulanan)', route: r('bs.gl.comparative') },
        { label: 'Laba Rugi Komparasi (Tahunan)', route: r('bs.gl.extra', { mode: 'lr-tahunan' }) },
        { label: 'Laba Rugi Perbandingan', route: r('bs.gl.extra', { mode: 'lr-banding' }) },
    ],
    'cf-direct': [
        { label: 'Cash Flow Direct | Multi Periode', route: r('bs.gl.cashflow', { method: 'direct' }) },
    ],
    'cf-indirect': [
        { label: 'Cash Flow Indirect | Multi Periode', route: r('bs.gl.cashflow', { method: 'indirect' }) },
    ],
    'saldo-awal': [
        { label: 'Input Saldo Awal', route: r('bs.closing.saldoawal') },
        { label: 'Confirm Saldo Awal', route: r('bs.gl.saldo-awal.confirm') },
    ],
    'jurnal-memorial': [
        { label: 'Registrasi Jurnal Memorial', route: r('bs.gl.journal.create') },
        { label: 'Import Jurnal', route: r('bs.gl.journal.import') },
    ],
    'generate-lk': [
        { label: 'Finalisasi Jurnal', route: r('bs.gl.journal.index', { stage: 'finalisasi' }) },
        { label: 'Approval Jurnal', route: r('bs.gl.journal.index', { stage: 'approval' }) },
        { label: 'Late Closing', route: r('bs.gl.late-closing') },
    ],
    'generate-lk-perusahaan': [
        { label: 'Closing Fiskal', route: r('bs.closing.index') },
    ],
}));
const items = computed<Item[]>(() => sections.value[props.section] ?? []);
const title = computed(() => titles[props.section] ?? 'Sub-Modul');
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="it in items" :key="it.label" class="card flex items-center justify-between gap-2 p-4">
                <a v-if="it.route" :href="it.route" class="flex-1 font-medium text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                <span v-else class="flex-1 font-medium text-neutral-400">{{ it.label }}</span>
                <span class="flex items-center gap-2">
                    <a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a>
                    <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                    <span v-else class="text-primary-400">→</span>
                </span>
            </div>
        </div>
    </div>
</template>
