<!-- Cash Book: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Banknote } from 'lucide-vue-next';
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
    'query': 'Report/Query',
    'saldo-awal': 'Saldo Awal',
    'pengeluaran': 'Transaksi Pengeluaran Bank',
    'bank': 'Transaksi Penerimaan Bank',
    'bon': 'Transaksi Bon Sementara',
    'kas-kecil': 'Transaksi Kas Kecil',
    'giro': 'Transaksi Cek Giro Masuk',
};
const sections = computed<Record<string, Item[]>>(() => ({
    'general': [
        { label: 'General Function', route: r('bs.cashbook.printhub') },
    ],
    'setup': [
        { label: 'Rekening', route: r('rekening.index') },
        { label: 'Repair Data', route: r('bs.cashbook.repair.index') },
    ],
    'master': [
        { label: 'Transfer Rekening', route: r('bs.cashbook.index', { type: 'transfer' }) },
        { label: 'Kas Masuk', route: r('bs.cashbook.index', { type: 'in' }) },
        { label: 'Kas Keluar', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Kas Kecil Masuk', route: r('bs.pettycash.index', { type: 'in' }) },
        { label: 'Kas Kecil Keluar', route: r('bs.pettycash.index', { type: 'out' }) },
        { label: 'Cek Giro Masuk', route: r('bs.giro.index') },
        { label: 'Pengeluaran Kas & Bank', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Pengeluaran Kas & Bank (AP)', route: r('bs.ap.kuitansi.index') },
        { label: 'Realisasi Bon Sementara', route: r('bs.advance.index') },
    ],
    'query': [
        { label: 'Rekening Ledger', route: r('bs.cashbook.ledger') },
        { label: 'Rekening Saldo', route: r('bs.cashbook.balances') },
        { label: 'Kalender Cek Giro', route: r('bs.cashbook.giro-calendar') },
        { label: 'Pengeluaran Kas & Bank', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Kartu Bon Sementara', route: r('bs.advance.card') },
        { label: 'Rekap Bon Sementara', route: r('bs.advance.report') },
        { label: 'Bon Sementara Masih Outstanding', route: r('bs.advance.report') },
    ],
    'saldo-awal': [
        { label: 'Saldo Awal Rekening', route: r('bs.cashbook.opening.index') },
    ],
    'pengeluaran': [
        { label: 'Registrasi Pengeluaran Kas & Bank', route: r('bs.cashbook.create', { type: 'out' }) },
        { label: 'Registrasi Pengeluaran Kas & Bank (AP)', route: r('bs.ap.kuitansi.create') },
        { label: 'Approval Pengeluaran Kas & Bank', route: r('bs.cashbook.approvals') },
    ],
    'bank': [
        { label: 'Transfer Antar Rekening', route: r('bs.cashbook.create', { type: 'transfer' }) },
        { label: 'Penerimaan Bank', route: r('bs.cashbook.create', { type: 'in' }) },
    ],
    'bon': [
        { label: 'Pengeluaran Bon Sementara', route: r('bs.advance.payments') },
        { label: 'Realisasi Bon Sementara', route: r('bs.advance.index') },
        { label: 'Refund Bon Sementara', route: r('bs.advance.index') },
        { label: 'Reimburse Bon Sementara', route: r('bs.advance.index') },
    ],
    'kas-kecil': [
        { label: 'Penerimaan Kas Kecil', route: r('bs.pettycash.create', { type: 'in' }) },
        { label: 'Pengeluaran Kas Kecil', route: r('bs.pettycash.create', { type: 'out' }) },
    ],
    'giro': [
        { label: 'Penerimaan Cek Giro', route: r('bs.giro.create') },
        { label: 'Kliring Cek Giro Masuk', route: r('bs.giro.index') },
        { label: 'Tolakan Cek Giro Masuk', route: r('bs.giro.index') },
    ],
}));
const items = computed<Item[]>(() => sections.value[props.section] ?? []);
const title = computed(() => titles[props.section] ?? 'Sub-Modul');
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> {{ title }}</h1>
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
