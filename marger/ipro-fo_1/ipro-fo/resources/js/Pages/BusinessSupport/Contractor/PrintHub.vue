<!-- Contractor Print-Out hub: links to printable / exportable SPK reports -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer, ArrowRight } from 'lucide-vue-next';

const reports = [
  { name: 'bs.spk.query.rekap', title: 'Rekap SPK', desc: 'Rekapitulasi seluruh Surat Perintah Kerja per proyek dan periode.' },
  { name: 'bs.spk.query.kartu-hutang', title: 'Kartu Hutang Supplier - SPK', desc: 'Kartu hutang per supplier berikut mutasi dan saldo SPK.' },
  { name: 'bs.spk.query.pemakaian', title: 'Pemakaian Barang', desc: 'Daftar pemakaian barang / material pada pelaksanaan SPK.' },
  { name: 'bs.spk.query.summary', title: 'Summary SPK', desc: 'Ringkasan nilai, progres, dan status pembayaran SPK.' },
];
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-amber-600" /> Print-Out</h1>

    <p class="text-sm text-neutral-500">Pilih laporan untuk dicetak / diekspor.</p>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      <a v-for="r in reports" :key="r.name" :href="route(r.name)" class="card group flex flex-col gap-2 p-4 transition hover:border-amber-500 hover:shadow-sm">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-neutral-800 dark:text-neutral-100">{{ r.title }}</h2>
          <ArrowRight class="h-4 w-4 text-neutral-400 transition group-hover:translate-x-1 group-hover:text-amber-600" />
        </div>
        <p class="text-sm text-neutral-500">{{ r.desc }}</p>
      </a>
    </div>
  </div>
</template>
