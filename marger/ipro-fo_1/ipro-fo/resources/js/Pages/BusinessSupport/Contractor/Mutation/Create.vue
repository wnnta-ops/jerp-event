<script setup lang="ts">
// Contractor Management — Registrasi Mutasi Progress Bangunan
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Shuffle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  spks: Array<any>;
  accounts: Array<any>;
  costCenters: Array<any>;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const today = new Date().toISOString().slice(0,10);

const form = useForm({
  work_order_id: '',
  mutation_date: today,
  amount: 0,
  to_account_id: '',
  to_cost_center_id: '',
  note: '',
});

const sel = computed(()=>props.spks.find((s:any)=>s.id===form.work_order_id));

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit(){ form.post(route('bs.spk.mutation.store')); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.mutation.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Shuffle class="h-6 w-6 text-amber-600" /> Registrasi Mutasi Progress Bangunan</h1>

    <p class="text-sm text-neutral-500">Diajukan dulu (status Baru); jurnal reklas CIP terbit saat di-approve.</p>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="form.work_order_id" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
        </select>
      </div>

      <div v-if="sel" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
        Dari Akun: {{ sel.from_account }} · CC: {{ sel.cost_center }} · Sisa dapat dimutasi: {{ fmtRp(sel.available) }}
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.mutation_date" type="date" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Nilai Mutasi <span class="text-red-500">*</span></label>
          <input v-model.number="form.amount" type="number" :max="sel?.available" :class="inputCls" />
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Akun CIP Tujuan</label>
          <select v-model="form.to_account_id" :class="inputCls">
            <option v-for="a in props.accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Cost Center Tujuan</label>
          <select v-model="form.to_cost_center_id" :class="inputCls">
            <option value="">— tanpa —</option>
            <option v-for="c in props.costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
          </select>
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <textarea v-model="form.note" rows="3" :class="inputCls" style="height:auto;padding-top:0.5rem;padding-bottom:0.5rem;"></textarea>
      </div>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing">Ajukan Mutasi</Button>
      </div>
    </form>
  </div>
</template>
