<!-- COGS · Registrasi Mutasi Budget (transfer/koreksi alokasi antar budget) -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, GitMerge } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  year: number;
  budgets: Array<any>;
  projects: Array<any>;
}>();

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const form = useForm({
  project_id: '',
  kind: 'transfer',
  mutation_date: today(),
  from_budget_id: '',
  to_budget_id: '',
  amount: 0,
  note: '',
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.cogs.mutation.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.mutation.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><GitMerge class="h-6 w-6 text-primary-600" /> Registrasi Mutasi Budget</h1>

    <form class="card space-y-4" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Project <span class="text-red-500">*</span></label>
          <select v-model="form.project_id" :class="inputCls" required>
            <option value="">Pilih Project</option>
            <option v-for="p in props.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p v-if="form.errors.project_id" class="mt-1 text-xs text-red-500">{{ form.errors.project_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.mutation_date" type="date" :class="inputCls" />
          <p v-if="form.errors.mutation_date" class="mt-1 text-xs text-red-500">{{ form.errors.mutation_date }}</p>
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Jenis Mutasi</label>
        <div class="flex gap-4">
          <label class="inline-flex items-center gap-2 text-sm">
            <input v-model="form.kind" type="radio" value="transfer" /> Transfer
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input v-model="form.kind" type="radio" value="koreksi" /> Koreksi
          </label>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div v-if="form.kind === 'transfer'">
          <label class="mb-1 block text-sm font-medium">From Budget</label>
          <select v-model="form.from_budget_id" :class="inputCls">
            <option value="">Pilih Budget</option>
            <option v-for="b in props.budgets" :key="b.id" :value="b.id">{{ b.label }}</option>
          </select>
          <p v-if="form.errors.from_budget_id" class="mt-1 text-xs text-red-500">{{ form.errors.from_budget_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">To Budget / Target <span class="text-red-500">*</span></label>
          <select v-model="form.to_budget_id" :class="inputCls" required>
            <option value="">Pilih Budget</option>
            <option v-for="b in props.budgets" :key="b.id" :value="b.id">{{ b.label }}</option>
          </select>
          <p v-if="form.errors.to_budget_id" class="mt-1 text-xs text-red-500">{{ form.errors.to_budget_id }}</p>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Nilai <span class="text-red-500">*</span></label>
          <input v-model.number="form.amount" type="number" min="0" :class="inputCls" required />
          <p v-if="form.errors.amount" class="mt-1 text-xs text-red-500">{{ form.errors.amount }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Catatan</label>
          <input v-model="form.note" type="text" :class="inputCls" placeholder="Keterangan mutasi" />
          <p v-if="form.errors.note" class="mt-1 text-xs text-red-500">{{ form.errors.note }}</p>
        </div>
      </div>

      <p class="text-xs text-neutral-500">Transfer menggeser alokasi antar budget; berlaku setelah Approval.</p>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing">Ajukan Mutasi</Button>
      </div>
    </form>
  </div>
</template>
