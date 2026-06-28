<script setup lang="ts">
// Close SBO — close all open material usage (bon) for a selected SPK
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, PackageMinus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  spks: Array<any>;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const today = (()=>{ const d=new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; })();

const form = useForm({ work_order_id:'', close_date:today });

const sel = computed(()=>props.spks.find((s:any)=>s.id===form.work_order_id));

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit(){ form.post(route('bs.spk.material.close')); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.material.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><PackageMinus class="h-6 w-6 text-amber-600" /> Close SBO</h1>

    <p class="text-sm text-neutral-500">Menutup seluruh pemakaian barang (bon) yang masih open untuk SPK terpilih.</p>

    <div v-if="!spks.length" class="card p-4 text-sm text-neutral-500">
      Tidak ada SPK dengan pemakaian barang open.
    </div>

    <form v-else class="card max-w-2xl space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="form.work_order_id" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }} ({{ s.open_materials }} bon open)</option>
        </select>
      </div>

      <div v-if="sel" class="rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
        {{ sel.open_materials }} bon open · pemakaian {{ fmtRp(sel.material_used) }}
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Tanggal</label>
        <input type="date" v-model="form.close_date" :class="inputCls" />
      </div>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing || !form.work_order_id">Proses Close SBO</Button>
      </div>
    </form>
  </div>
</template>
