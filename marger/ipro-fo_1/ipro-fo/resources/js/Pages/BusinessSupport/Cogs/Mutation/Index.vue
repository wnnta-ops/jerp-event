<!-- COGS Mutation index — list budget mutations (transfer/koreksi) with year filter -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, GitMerge, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  year: number;
  rows: Array<any>;
  statuses: Record<string, string>;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const stBadge:Record<string,any>={baru:'warning',approved:'success',rejected:'danger',generated:'info',posted_persediaan:'warning',posted_hpp:'success',saldo_awal:'neutral',realisasi:'info'};

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const year = ref(props.year);
function applyFilter(){ router.get(route('bs.cogs.mutation.index'),{year:year.value},{preserveState:true,replace:true}); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><GitMerge class="h-6 w-6 text-primary-600" /> Mutasi Budget</h1>

    <div class="card flex flex-wrap items-center gap-2">
      <input v-model.number="year" type="number" :class="inputCls" class="!w-28" @keyup.enter="applyFilter" />
      <Button variant="secondary" @click="applyFilter">Tampilkan</Button>
      <div class="ms-auto flex items-center gap-2">
        <Link :href="route('bs.cogs.mutation.create')"><Button><Plus class="h-4 w-4" /> Registrasi Mutasi</Button></Link>
        <Link :href="route('bs.cogs.mutation.approvals')" class="text-sm text-primary-600 hover:underline">Approval</Link>
      </div>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. Voucher</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Jenis</th>
            <th class="px-3 py-2">Dari</th>
            <th class="px-3 py-2">Ke</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-mono text-xs">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2"><Badge :variant="r.kind==='transfer'?'info':'primary'">{{ r.kind==='transfer'?'Transfer':'Koreksi' }}</Badge></td>
            <td class="px-3 py-2">{{ r.from ?? '—' }}</td>
            <td class="px-3 py-2">{{ r.to ?? '—' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ statuses[r.status] }}</Badge></td>
            <td class="px-3 py-2">{{ r.note }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Belum ada mutasi" description="Belum ada mutasi budget untuk tahun ini." />
  </div>
</template>
