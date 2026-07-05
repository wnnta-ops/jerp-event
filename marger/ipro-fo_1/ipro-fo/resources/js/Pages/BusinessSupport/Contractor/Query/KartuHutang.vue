<!-- Kartu Hutang Supplier - SPK: per-SPK payable card with filter + summary -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart, FileSpreadsheet } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  filters: { project_id?: any; jenis?: any; date_from?: any; date_to?: any };
  projects: Array<any>;
  jenisOpts: Record<string, string>;
  rows: Array<any>;
  total: { billed: number; paid: number; outstanding: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const proj = ref(props.filters?.project_id ?? '');
const jen = ref(props.filters?.jenis ?? '');
const df = ref(props.filters?.date_from ?? '');
const dt = ref(props.filters?.date_to ?? '');

function params(){ return {project_id:proj.value||undefined,jenis:jen.value||undefined,date_from:df.value||undefined,date_to:dt.value||undefined}; }
function apply(){ router.get(route('bs.spk.query.kartu-hutang'), params(), { preserveState:true, replace:true }); }
function exportXls(){ window.location.href=route('bs.spk.query.export',{type:'kartu-hutang',...params()}); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-amber-600" /> Kartu Hutang Supplier - SPK</h1>

    <div class="card p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs text-neutral-500">Project</label>
          <select v-model="proj" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
          </select>
        </div>
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs text-neutral-500">Jenis</label>
          <select v-model="jen" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="(lbl,key) in jenisOpts" :key="key" :value="key">{{ lbl }}</option>
          </select>
        </div>
        <div><label class="mb-1 block text-xs text-neutral-500">Tgl Awal</label><input v-model="df" type="date" :class="inputCls" /></div>
        <div><label class="mb-1 block text-xs text-neutral-500">Tgl Akhir</label><input v-model="dt" type="date" :class="inputCls" /></div>
        <Button @click="apply">Tampilkan</Button>
        <button @click="exportXls" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Tagihan</div>
        <div class="mt-1 text-xl font-bold">{{ fmtRp(total.billed) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Terbayar</div>
        <div class="mt-1 text-xl font-bold text-emerald-600">{{ fmtRp(total.paid) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Outstanding</div>
        <div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(total.outstanding) }}</div>
      </div>
    </div>

    <div v-if="rows.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. SPK</th>
            <th class="px-3 py-2">Kontraktor</th>
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2 text-right">Tagihan/Net</th>
            <th class="px-3 py-2 text-right">Terbayar</th>
            <th class="px-3 py-2 text-right">Outstanding</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(r,i) in rows" :key="i">
            <td class="px-3 py-2 font-medium">{{ r.spk_number }}</td>
            <td class="px-3 py-2">{{ r.supplier }}</td>
            <td class="px-3 py-2">{{ r.project }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.billed) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.paid) }}</td>
            <td class="px-3 py-2 text-right text-amber-600">{{ fmtRp(r.outstanding) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <EmptyState v-else title="Tidak ada data" description="Belum ada kartu hutang supplier untuk filter yang dipilih." />
  </div>
</template>
