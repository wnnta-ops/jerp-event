<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';

function r(name: string, params?: Record<string, any>): string | null {
    try { return route(name, params); } catch { return null; }
}

// Laporan GL yang bisa dicetak (membuka laporan; tombol cetak ada di tiap halaman).
const groups: Array<{ group: string; items: Array<{ label: string; route: string | null }> }> = [
    { group: 'Buku Besar', items: [
        { label: 'Detail Transaksi Jurnal', route: r('bs.gl.journal.index') },
        { label: 'Buku Besar (General Ledger)', route: r('bs.gl.ledger') },
    ]},
    { group: 'Neraca', items: [
        { label: 'Neraca Saldo (Trial Balance)', route: r('bs.gl.trialbalance') },
        { label: 'Neraca & Laba Rugi', route: r('bs.gl.financials') },
        { label: 'Neraca Lajur (Worksheet)', route: r('bs.gl.worksheet') },
        { label: 'Neraca Multi Periode', route: r('bs.gl.extra', { mode: 'multi' }) },
        { label: 'Neraca Komparasi Tahunan', route: r('bs.gl.extra', { mode: 'tahunan' }) },
    ]},
    { group: 'Laba Rugi', items: [
        { label: 'Laba Rugi Multi Periode', route: r('bs.gl.extra', { mode: 'lr-multi' }) },
        { label: 'Laba Rugi Komparasi Tahunan', route: r('bs.gl.extra', { mode: 'lr-tahunan' }) },
        { label: 'Laba Rugi Perbandingan', route: r('bs.gl.extra', { mode: 'lr-banding' }) },
        { label: 'Komparasi Bulanan', route: r('bs.gl.comparative') },
    ]},
    { group: 'Arus Kas', items: [
        { label: 'Laporan Arus Kas (Direct & Indirect)', route: r('bs.gl.cashflow') },
    ]},
];
</script>

<template>
    <div class="max-w-4xl space-y-5">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-primary-600" /> Print-Out</h1>
        <p class="text-sm text-neutral-500">Pilih laporan untuk ditampilkan, lalu cetak dari halaman laporannya.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="g in groups" :key="g.group" class="card p-4">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-accent-600">{{ g.group }}</h2>
                <ul class="space-y-1">
                    <li v-for="it in g.items" :key="it.label">
                        <a v-if="it.route" :href="it.route" class="flex items-center justify-between rounded px-2 py-1.5 text-sm text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-neutral-800">{{ it.label }} <Printer class="h-3.5 w-3.5 text-primary-400" /></a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
