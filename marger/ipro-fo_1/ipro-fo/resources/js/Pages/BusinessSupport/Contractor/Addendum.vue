<!-- Addendum SPK (Tambah / Kurang) — pick an SPK and apply a contract-value delta (commitment, no journal). -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeft, FilePlus2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ spks: Array<any> }>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const today=(()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;})();

const woId=ref('');
const form=useForm({addendum_date:today,value_delta:0,note:''});
const sel=computed(()=>props.spks.find((s:any)=>s.id===woId.value));
const newContract=computed(()=> sel.value ? Number(sel.value.contract_value)+Number(form.value_delta||0) : 0);

function submit(){ if(!woId.value)return; form.post(route('bs.spk.addendum', woId.value)); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><FilePlus2 class="h-6 w-6 text-amber-600" /> Addendum SPK (Tambah / Kurang)</h1>

    <form @submit.prevent="submit" class="card space-y-4 p-4">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="woId" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }} ({{ fmtRp(s.contract_value) }})</option>
        </select>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.addendum_date" type="date" :class="inputCls" />
          <p v-if="form.errors.addendum_date" class="mt-1 text-xs text-red-600">{{ form.errors.addendum_date }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Perubahan Nilai (±) <span class="text-red-500">*</span></label>
          <input v-model.number="form.value_delta" type="number" step="any" :class="inputCls" placeholder="negatif = kurang" />
          <p v-if="form.errors.value_delta" class="mt-1 text-xs text-red-600">{{ form.errors.value_delta }}</p>
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <input v-model="form.note" type="text" :class="inputCls" />
        <p v-if="form.errors.note" class="mt-1 text-xs text-red-600">{{ form.errors.note }}</p>
      </div>

      <div v-if="sel" class="rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
        Nilai Kontrak Baru: <span class="font-semibold">{{ fmtRp(newContract) }}</span>
      </div>

      <div class="flex items-center justify-between gap-3">
        <p class="text-xs text-neutral-500">Untuk pengurangan isi nilai negatif (komitmen, tanpa jurnal).</p>
        <Button type="submit" :disabled="!woId || form.processing">Simpan Addendum</Button>
      </div>
    </form>
  </div>
</template>
