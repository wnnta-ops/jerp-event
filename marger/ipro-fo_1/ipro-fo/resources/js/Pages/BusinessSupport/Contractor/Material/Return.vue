<!-- Material Return: returns unused material from a SPK back to inventory (Dr Persediaan / Cr CIP). -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, PackagePlus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  spks: Array<any>;
  inventoryAccounts: Array<any>;
  defaultInventory: number | null;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}
function fmtDate(d:string):string{ return d ? new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'; }

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const today = new Date().toISOString().slice(0,10);

const form = useForm({
  work_order_id: '',
  return_date: today,
  description: '',
  amount: 0,
  inventory_account_id: props.defaultInventory ?? '',
});

const sel = computed(() => props.spks.find((s:any) => s.id === form.work_order_id));

function submit(){
  form.post(route('bs.spk.material.return'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><PackagePlus class="h-6 w-6 text-amber-600" /> Pengembalian Sisa Pemakaian</h1>

    <div class="card p-4">
      <p class="mb-3 text-sm text-neutral-500">Jurnal: Dr Persediaan / Cr CIP.</p>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium">SPK</label>
          <select v-model="form.work_order_id" :class="inputCls">
            <option value="">— pilih SPK —</option>
            <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }} (pakai {{ fmtRp(s.material_used) }})</option>
          </select>
          <p v-if="sel" class="mt-1 text-xs text-neutral-500">Pemakaian saat ini: {{ fmtRp(sel.material_used) }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input type="date" v-model="form.return_date" :class="inputCls" />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Uraian</label>
          <input type="text" v-model="form.description" :class="inputCls" />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Nilai <span class="text-red-500">*</span></label>
          <input type="number" v-model.number="form.amount" :max="sel?.material_used" min="0" :class="inputCls" required />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Akun Persediaan</label>
          <select v-model="form.inventory_account_id" :class="inputCls">
            <option v-for="a in props.inventoryAccounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
          </select>
        </div>

        <div class="flex justify-end gap-2">
          <Link :href="route('bs.spk.material.index')"><Button type="button" variant="secondary">Batal</Button></Link>
          <Button type="submit" :disabled="form.processing">Simpan &amp; Jurnal</Button>
        </div>
      </form>
    </div>
  </div>
</template>
