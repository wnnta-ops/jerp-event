<!-- COGS · Posting Jurnal HPP (batch-select: Dr Beban Pokok / Cr Persediaan Unit sebesar HPP diakui POC) -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  summary: { count: number; total: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const stBadge:Record<string,any>={baru:'warning',approved:'success',rejected:'danger',generated:'info',posted_persediaan:'warning',posted_hpp:'success',saldo_awal:'neutral',realisasi:'info'};

const sel = ref<number[]>([]);
const today = new Date();
const post_date = ref(`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`);

function toggle(id:number){ const i=sel.value.indexOf(id); i>=0?sel.value.splice(i,1):sel.value.push(id); }
const allChecked = () => props.rows.length>0 && sel.value.length===props.rows.length;
function toggleAll(){ sel.value = allChecked() ? [] : props.rows.map(r=>r.id); }

function run(){
  if(!sel.value.length) return;
  router.post(route('bs.cogs.hpp.post-hpp.run'),{ids:sel.value,post_date:post_date.value},{preserveScroll:true,onSuccess:()=>{sel.value=[];}});
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div>
      <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCheck class="h-6 w-6 text-primary-600" /> Posting Jurnal HPP</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} HPP siap diakui · Rp {{ fmtRp(summary.total) }}</p>
    </div>

    <div class="card text-sm text-neutral-600 dark:text-neutral-300">
      Akui beban: Dr Beban Pokok / Cr Persediaan Unit sebesar HPP diakui (POC).
    </div>

    <div class="card flex flex-wrap items-end gap-3">
      <div>
        <label class="mb-1 block text-xs uppercase text-neutral-500">Tanggal Posting</label>
        <input type="date" v-model="post_date" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
      </div>
      <Button :disabled="sel.length===0" @click="run">Posting</Button>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="px-3 py-2">CogsID</th>
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2">Progress</th>
            <th class="px-3 py-2 text-right">HPP Diakui</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2"><input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="px-3 py-2">{{ r.cogs_id }}</td>
            <td class="px-3 py-2">{{ r.unit_code }}</td>
            <td class="px-3 py-2">{{ r.period }}</td>
            <td class="px-3 py-2">{{ r.progress + '%' }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.hpp_recognized) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else title="Tidak ada HPP" description="Belum ada HPP yang siap diakui." />
  </div>
</template>
