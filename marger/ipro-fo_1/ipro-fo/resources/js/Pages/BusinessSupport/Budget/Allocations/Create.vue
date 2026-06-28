<!-- Budget Control: Alokasi Budget Baru -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Layers } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  year: number;
  budgets: Array<any>;
  monthLabels: string[];
  costCenters: Array<any>;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const today = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

const form = useForm({
  budget_id: '',
  alloc_date: today,
  period_from: 1,
  period_to: 12,
  amount: 0,
  notes: '',
});

const selected = computed(() => props.budgets.find((b: any) => b.id === form.budget_id));
const over = computed(() => !!selected.value && Number(form.amount) > selected.value.sisa);

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.budget.allocation.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Budget Control
    </Link>

    <div class="flex items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Layers class="h-6 w-6 text-primary-600" /> Alokasi Budget Baru
      </h1>
      <Link :href="route('bs.budget.allocation.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
        Daftar Alokasi
      </Link>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
      <div class="card p-4 space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium">Budget Master</label>
          <select v-model="form.budget_id" :class="inputCls">
            <option value="">— pilih —</option>
            <option v-for="b in budgets" :key="b.id" :value="b.id">
              {{ b.doc_no }} — {{ b.account }} (Sisa {{ fmtRp(b.sisa) }})
            </option>
          </select>
        </div>

        <div v-if="selected" class="card bg-neutral-50 p-4 dark:bg-neutral-800">
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <div class="text-xs uppercase text-neutral-500">Total Budget</div>
              <div class="font-semibold">{{ fmtRp(selected.total) }}</div>
            </div>
            <div>
              <div class="text-xs uppercase text-neutral-500">Sisa Budget</div>
              <div class="font-semibold">{{ fmtRp(selected.sisa) }}</div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label class="mb-1 block text-sm font-medium">Tanggal</label>
            <input v-model="form.alloc_date" type="date" :class="inputCls" />
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Periode Dari</label>
            <select v-model.number="form.period_from" :class="inputCls">
              <option v-for="i in 12" :key="i" :value="i">{{ monthLabels[i - 1] }}</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium">Periode Sampai</label>
            <select v-model.number="form.period_to" :class="inputCls">
              <option v-for="i in 12" :key="i" :value="i">{{ monthLabels[i - 1] }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Nilai Alokasi</label>
          <input v-model.number="form.amount" type="number" :class="[inputCls, 'text-right font-mono']" />
          <p v-if="over" class="mt-1 text-sm text-red-600">Nilai alokasi melebihi sisa budget.</p>
          <p v-if="form.errors.amount" class="mt-1 text-sm text-red-600">{{ form.errors.amount }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Catatan</label>
          <input v-model="form.notes" type="text" :class="inputCls" />
        </div>
      </div>

      <div class="flex items-center gap-3">
        <Button type="submit" :disabled="form.processing || over">Simpan Alokasi</Button>
        <Link :href="route('bs.budget.allocation.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
          Batal
        </Link>
      </div>
    </form>
  </div>
</template>
