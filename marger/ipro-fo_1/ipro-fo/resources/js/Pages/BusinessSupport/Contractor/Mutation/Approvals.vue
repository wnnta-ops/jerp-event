<!-- Approval Mutasi Progress Bangunan — batch approval of CIP reclass mutations -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  canApprove: boolean;
  summary: { count: number; total: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const sel = ref<number[]>([]);
function toggle(id:number){ const i=sel.value.indexOf(id); if(i>=0) sel.value.splice(i,1); else sel.value.push(id); }
const allChecked = () => props.rows.length>0 && sel.value.length===props.rows.length;
function toggleAll(){ sel.value = allChecked() ? [] : props.rows.map((r:any)=>r.id); }
function decide(d:'approve'|'reject'){
  if(!sel.value.length) return;
  router.post(route('bs.spk.mutation.approve-batch'),{ids:sel.value,decision:d},{preserveScroll:true,onSuccess:()=>{sel.value=[];}});
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <div class="space-y-1">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-amber-600" /> Approval Mutasi Progress Bangunan</h1>
      <p class="text-sm text-neutral-500">Menyetujui akan memposting jurnal reklasifikasi CIP.</p>
      <p class="text-sm font-medium text-neutral-600 dark:text-neutral-300">{{ summary.count }} mutasi menunggu persetujuan · Rp {{ fmtRp(summary.total) }}</p>
    </div>

    <div v-if="canApprove" class="flex flex-wrap gap-2">
      <Button color="success" :disabled="sel.length===0" @click="decide('approve')">Setujui</Button>
      <Button color="danger" :disabled="sel.length===0" @click="decide('reject')">Tolak</Button>
    </div>

    <EmptyState v-if="!rows.length" title="Tidak ada mutasi menunggu persetujuan" description="Semua mutasi progress bangunan telah diproses." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="p-3"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="p-3">SPK</th>
            <th class="p-3">Tgl</th>
            <th class="p-3">Dari</th>
            <th class="p-3">Ke</th>
            <th class="p-3">Cost Center</th>
            <th class="p-3 text-right">Nilai</th>
            <th class="p-3">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="p-3"><input type="checkbox" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="p-3 font-medium">{{ r.spk }}</td>
            <td class="p-3">{{ fmtDate(r.date) }}</td>
            <td class="p-3">{{ r.from }}</td>
            <td class="p-3">{{ r.to }}</td>
            <td class="p-3">{{ r.cost_center }}</td>
            <td class="p-3 text-right">{{ fmtRp(r.amount) }}</td>
            <td class="p-3 text-neutral-500">{{ r.note }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
