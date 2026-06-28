<!-- Budget Control · Print-Out — direktori laporan cetak / ekspor -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';

const reports: Array<{ label: string; desc: string; route: string }> = [
    { label: 'Master Budget', desc: 'Cetak daftar anggaran induk per cost center.', route: route('bs.budget.query.master') },
    { label: 'Realisasi Budget', desc: 'Cetak laporan realisasi terhadap anggaran.', route: route('bs.budget.query.realization') },
    { label: 'Alokasi Budget', desc: 'Cetak rincian alokasi anggaran antar pos.', route: route('bs.budget.query.allocation') },
    { label: 'Koreksi Budget', desc: 'Cetak riwayat koreksi anggaran.', route: route('bs.budget.query.correction') },
];
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

        <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-primary-600" /> Print-Out</h1>
        <p class="text-sm text-neutral-500">Pilih laporan untuk dicetak / diekspor.</p>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <a v-for="r in reports" :key="r.label" :href="r.route" class="card flex items-start justify-between gap-3 p-4 transition hover:border-primary-300 hover:bg-primary-50 dark:hover:border-primary-700 dark:hover:bg-neutral-800">
                <div>
                    <div class="text-sm font-semibold text-primary-700 dark:text-primary-300">{{ r.label }}</div>
                    <p class="mt-1 text-xs text-neutral-500">{{ r.desc }}</p>
                </div>
                <span class="mt-0.5 text-primary-400">→</span>
            </a>
        </div>
    </div>
</template>
