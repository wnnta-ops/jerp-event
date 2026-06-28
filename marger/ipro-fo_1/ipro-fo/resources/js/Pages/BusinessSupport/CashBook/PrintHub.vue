<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';

function r(name: string, params?: Record<string, any>): string | null {
    try { return route(name, params); } catch { return null; }
}

const groups: Array<{ group: string; items: Array<{ label: string; route: string | null }> }> = [
    { group: 'Kas & Bank', items: [
        { label: 'Buku Kas/Bank (transaksi)', route: r('bs.cashbook.index') },
        { label: 'Saldo Rekening', route: r('bs.cashbook.balances') },
        { label: 'Download Mutasi (.xls)', route: r('bs.cashbook.export') },
    ]},
    { group: 'Bon Sementara', items: [
        { label: 'Rekap Bon Sementara', route: r('bs.advance.report') },
        { label: 'Rekap Bon (PDF/cetak)', route: r('bs.advance.report.pdf') },
        { label: 'Rekap Bon (.xls)', route: r('bs.advance.report.excel') },
    ]},
    { group: 'Cek / Giro', items: [
        { label: 'Cek/Giro Masuk', route: r('bs.giro.index') },
        { label: 'Cek/Giro Keluar', route: r('bs.cheque-out.index') },
    ]},
];
</script>

<template>
    <div class="max-w-3xl space-y-5">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-primary-600" /> Print-Out</h1>
        <p class="text-sm text-neutral-500">Pilih laporan kas/bank untuk ditampilkan, lalu cetak/unduh dari halamannya.</p>

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
