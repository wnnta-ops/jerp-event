<!-- Pemakaian Barang — record material usage from inventory against an SPK (Dr CIP / Cr Persediaan). -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, PackageMinus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  spks: Array<any>;
  inventoryAccounts: Array<any>;
  defaultInventory: number | null;
}>();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  work_order_id: '',
  usage_date: today,
  description: '',
  amount: 0,
  inventory_account_id: props.defaultInventory ?? '',
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.spk.material.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><PackageMinus class="h-6 w-6 text-amber-600" /> Pemakaian Barang</h1>

    <p class="text-sm text-neutral-500">Jurnal: Dr Konstruksi/CIP / Cr Persediaan.</p>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="form.work_order_id" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
        </select>
        <p v-if="form.errors.work_order_id" class="mt-1 text-xs text-red-600">{{ form.errors.work_order_id }}</p>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Tanggal</label>
        <input v-model="form.usage_date" type="date" :class="inputCls" />
        <p v-if="form.errors.usage_date" class="mt-1 text-xs text-red-600">{{ form.errors.usage_date }}</p>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Uraian *</label>
        <input v-model="form.description" type="text" :class="inputCls" />
        <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Nilai *</label>
        <input v-model.number="form.amount" type="number" min="0" :class="inputCls" />
        <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Akun Persediaan</label>
        <select v-model="form.inventory_account_id" :class="inputCls">
          <option value="">— pilih akun —</option>
          <option v-for="a in props.inventoryAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
        </select>
        <p v-if="form.errors.inventory_account_id" class="mt-1 text-xs text-red-600">{{ form.errors.inventory_account_id }}</p>
      </div>

      <div class="flex items-center gap-2 pt-2">
        <Button type="submit" :disabled="form.processing">Simpan &amp; Jurnal</Button>
        <Link :href="route('bs.spk.material.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Batal</Link>
      </div>
    </form>
  </div>
</template>
