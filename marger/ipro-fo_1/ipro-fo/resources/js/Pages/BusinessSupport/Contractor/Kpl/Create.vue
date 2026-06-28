<script setup lang="ts">
// KPL — Registrasi Kontrol Progress Lapangan (create + auto-jurnal)
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ spks: Array<any> }>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const today = new Date().toISOString().slice(0,10);

const form = useForm({
  work_order_id: '',
  progress_date: today,
  description: '',
  progress_value: 0,
  releaser: '',
  pic: '',
  no_manual: '',
});

const sel = computed(()=>props.spks.find((s:any)=>s.id===form.work_order_id));
const ret = computed(()=> sel.value ? Number(form.progress_value||0)*Number(sel.value.retention_pct||0)/100 : 0);
const ppn = computed(()=> sel.value ? Number(form.progress_value||0)*Number(sel.value.ppn_pct||0)/100 : 0);
const net = computed(()=> Number(form.progress_value||0)+ppn.value-ret.value);

function submit(){ form.post(route('bs.spk.kpl.store')); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardCheck class="h-6 w-6 text-amber-600" /> Registrasi Kontrol Progress Lapangan</h1>

    <form @submit.prevent="submit" class="card space-y-4 p-4">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="form.work_order_id" :class="inputCls">
          <option value="">— pilih —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
        </select>
      </div>

      <div v-if="sel" class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-neutral-800 dark:bg-neutral-800">
        <div class="flex justify-between"><span class="text-neutral-500">Sisa Kontrak</span><span class="font-semibold">{{ fmtRp(sel.remaining) }}</span></div>
        <div class="mt-2 flex justify-between"><span class="text-neutral-500">Retensi ({{ sel.retention_pct }}%)</span><span>{{ fmtRp(ret) }}</span></div>
        <div class="mt-1 flex justify-between"><span class="text-neutral-500">PPN ({{ sel.ppn_pct }}%)</span><span>{{ fmtRp(ppn) }}</span></div>
        <div class="mt-1 flex justify-between border-t border-neutral-200 pt-2 dark:border-neutral-700"><span class="text-neutral-500">Nilai Bersih</span><span class="font-semibold">{{ fmtRp(net) }}</span></div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.progress_date" type="date" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Nilai Progress (DPP) *</label>
          <input v-model.number="form.progress_value" type="number" :max="sel?.remaining" :class="inputCls" required />
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Uraian *</label>
        <input v-model="form.description" type="text" :class="inputCls" required />
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm font-medium">Releaser</label>
          <input v-model="form.releaser" type="text" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">PIC</label>
          <input v-model="form.pic" type="text" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">No. Manual</label>
          <input v-model="form.no_manual" type="text" :class="inputCls" />
        </div>
      </div>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing">Simpan &amp; Jurnal</Button>
        <Link :href="route('bs.spk.kpl.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Batal</Link>
      </div>
    </form>
  </div>
</template>
