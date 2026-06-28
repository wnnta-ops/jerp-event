<!-- Cost of Good Sold · Posting Jurnal HPP Persediaan (batch-select kapitalisasi HPP unit) -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck } from 'lucide-vue-next';
import { ref } from 'vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  summary: { count: number; total: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const sel = ref<number[]>([]);
const today = new Date();
const post_date = ref(`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`);

function toggle(id:number){ const i=sel.value.indexOf(id); i>=0?sel.value.splice(i,1):sel.value.push(id); }
function allChecked(){ return props.rows.length>0 && sel.value.length===props.rows.length; }
function toggleAll(){ sel.value = allChecked() ? [] : props.rows.map(r=>r.id); }

function run(){
  if(!sel.value.length) return;
  router.post(route('bs.cogs.hpp.post-persediaan.run'),{ids:sel.value,post_date:post_date.value},{preserveScroll:true,onSuccess:()=>{sel.value=[];}});
}

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <div>
      <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCheck class="h-6 w-6 text-primary-600" /> Posting Jurnal HPP Persediaan</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ summary.count }} HPP siap diposting · Rp {{ fmtRp(summary.total) }}</p>
    </div>

    <div class="card border-l-4 border-l-primary-500 text-sm text-neutral-600 dark:text-neutral-300">
      Kapitalisasi Total HPP unit: Dr Persediaan Unit / Cr CIP.
    </div>

    <div class="card flex flex-wrap items-end gap-3">
      <div>
        <label class="mb-1 block text-xs uppercase text-neutral-500">Tanggal Posting</label>
        <input v-model="post_date" type="date" :class="inputCls" />
      </div>
      <Button :disabled="sel.length===0" @click="run">Posting</Button>
    </div>

    <EmptyState v-if="!rows.length" title="Tidak ada HPP berstatus generated" description="Belum ada HPP yang siap untuk diposting." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase text-neutral-500 bg-neutral-50 dark:bg-neutral-800">
            <th class="px-3 py-2"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="px-3 py-2">CogsID</th>
            <th class="px-3 py-2">Unit</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2 text-right">Total HPP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2"><input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="px-3 py-2">{{ r.cogs_id }}</td>
            <td class="px-3 py-2">{{ r.unit_code }}</td>
            <td class="px-3 py-2">{{ r.period }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.total_hpp) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
