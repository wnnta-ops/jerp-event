<!-- Cost of Good Sold — Saldo Awal Realisasi (list + year filter) -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Coins, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  year: number;
  rows: Array<any>;
  summary: { count: number; total: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const stBadge:Record<string,any>={baru:'warning',approved:'success',rejected:'danger',generated:'info',posted_persediaan:'warning',posted_hpp:'success',saldo_awal:'neutral',realisasi:'info'};

const year = ref<number>(props.year);
function applyFilter(){ router.get(route('bs.cogs.realization.index'),{year:year.value},{preserveState:true,replace:true}); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Coins class="h-6 w-6 text-primary-600" /> Saldo Awal Realisasi</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} entri · Rp {{ fmtRp(summary.total) }}</p>
      </div>
      <div class="flex items-end gap-2">
        <div>
          <label class="mb-1 block text-xs text-neutral-500">Tahun</label>
          <input v-model.number="year" type="number" :class="inputCls" class="!w-28" @keyup.enter="applyFilter" />
        </div>
        <Button variant="secondary" @click="applyFilter">Tampilkan</Button>
        <Link :href="route('bs.cogs.realization.create')">
          <Button><Plus class="h-4 w-4" /> Input Saldo Awal</Button>
        </Link>
      </div>
    </div>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No.</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Tipe</th>
            <th class="px-3 py-2">Kategori</th>
            <th class="px-3 py-2">Lokasi</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="row in rows" :key="row.id">
            <td class="px-3 py-2">{{ row.doc_no }}</td>
            <td class="px-3 py-2">{{ fmtDate(row.date) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[row.type]">{{ row.type==='saldo_awal' ? 'Saldo Awal' : 'Realisasi' }}</Badge></td>
            <td class="px-3 py-2">{{ row.category ?? '—' }}</td>
            <td class="px-3 py-2">{{ row.location ?? '—' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(row.amount) }}</td>
            <td class="px-3 py-2">{{ row.note ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="!rows.length" title="Belum ada saldo awal" description="Input saldo awal atau realisasi COGS untuk tahun ini." />
    </div>
  </div>
</template>
