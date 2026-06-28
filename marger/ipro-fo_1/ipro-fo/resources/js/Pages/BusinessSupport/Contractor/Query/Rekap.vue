<script setup lang="ts">
// Rekap SPK — recap of contractor work orders with totals
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  filters: { project_id: number | null; jenis: string | null };
  projects: Array<any>;
  jenisOpts: Record<string, string>;
  rows: Array<any>;
  total: { contract: number; progress: number; retention: number; remaining: number };
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const stBadge:Record<string,any>={baru:'warning',approved:'success',rejected:'danger',active:'info',closed:'success',cancelled:'danger',open:'warning',unpaid:'warning',paid:'success'};

const proj=ref<string|number>(props.filters.project_id ?? '');
const jen=ref<string>(props.filters.jenis ?? '');

function apply(){
  router.get(route('bs.spk.query.rekap'),{project_id:proj.value||undefined,jenis:jen.value||undefined},{preserveState:true,replace:true});
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-amber-600" /> Rekap SPK</h1>

    <div class="card p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
          <select v-model="proj" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
          </select>
        </div>
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-xs font-medium text-neutral-500">Jenis</label>
          <select v-model="jen" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="(lbl,key) in jenisOpts" :key="key" :value="key">{{ lbl }}</option>
          </select>
        </div>
        <Button @click="apply">Tampilkan</Button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Kontrak</div>
        <div class="mt-1 text-lg font-bold">{{ fmtRp(total.contract) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Progress</div>
        <div class="mt-1 text-lg font-bold">{{ fmtRp(total.progress) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Retensi</div>
        <div class="mt-1 text-lg font-bold">{{ fmtRp(total.retention) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs uppercase text-neutral-500">Sisa</div>
        <div class="mt-1 text-lg font-bold">{{ fmtRp(total.remaining) }}</div>
      </div>
    </div>

    <EmptyState v-if="!rows.length" title="Belum ada data" description="Tidak ada SPK yang sesuai dengan filter." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">No. SPK</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Kontraktor</th>
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Jenis</th>
            <th class="px-3 py-2">Pekerjaan</th>
            <th class="px-3 py-2 text-right">Kontrak</th>
            <th class="px-3 py-2 text-right">Progress</th>
            <th class="px-3 py-2 text-right">Retensi</th>
            <th class="px-3 py-2 text-right">Sisa</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.spk_number">
            <td class="px-3 py-2 font-medium">{{ r.spk_number }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.supplier }}</td>
            <td class="px-3 py-2">{{ r.project }}</td>
            <td class="px-3 py-2">{{ r.jenis }}</td>
            <td class="px-3 py-2">{{ r.title }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.contract) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.progress) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.retention) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.remaining) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status] || 'info'">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
