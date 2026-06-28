<!-- COGS Print-Out hub: links to the 6 printable / exportable reports. -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer, FileText } from 'lucide-vue-next';

const reports: Array<{ title: string; route: string }> = [
  { title: 'Report Budget', route: 'bs.cogs.query.budget' },
  { title: 'Report Realisasi Budget', route: 'bs.cogs.query.realisasi' },
  { title: 'Report Realisasi Budget Detil', route: 'bs.cogs.query.realisasi-detil' },
  { title: 'Report Pengakuan HPP RealCost', route: 'bs.cogs.query.pengakuan' },
  { title: 'Report Persediaan Unit RealCost', route: 'bs.cogs.query.persediaan-unit' },
  { title: 'Report HPP Unit RealCost', route: 'bs.cogs.query.hpp-unit' },
];
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-primary-600" /> Print-Out</h1>

    <p class="text-sm text-neutral-500">Pilih laporan untuk dicetak / diekspor.</p>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <a v-for="r in reports" :key="r.route" :href="route(r.route)" class="card flex items-center gap-3 transition hover:border-primary-500 hover:shadow-sm">
        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-neutral-800">
          <FileText class="h-5 w-5" />
        </span>
        <span class="text-sm font-medium">{{ r.title }}</span>
      </a>
    </div>
  </div>
</template>
