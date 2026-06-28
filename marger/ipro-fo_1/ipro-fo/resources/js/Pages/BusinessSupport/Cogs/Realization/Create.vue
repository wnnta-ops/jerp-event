<!-- COGS Realization: Input Saldo Awal / Realisasi -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Coins } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{
  projects: Array<any>;
  locations: Array<any>;
  categories: Array<any>;
  accounts: Array<any>;
}>();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  project_id: '',
  location_id: '',
  category_id: '',
  account_id: '',
  realization_date: today,
  amount: 0,
  type: 'saldo_awal',
  note: '',
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.cogs.realization.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Cost of Good Sold
    </Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Coins class="h-6 w-6 text-primary-600" /> Input Saldo Awal Realisasi
      </h1>
      <Link :href="route('bs.cogs.realization.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
        Kembali ke daftar
      </Link>
    </div>

    <form class="card space-y-4" @submit.prevent="submit">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Project <span class="text-danger-600">*</span></label>
          <select v-model="form.project_id" :class="inputCls" required>
            <option value="">— Pilih Project —</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p v-if="form.errors.project_id" class="mt-1 text-xs text-danger-600">{{ form.errors.project_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Lokasi</label>
          <select v-model="form.location_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
          </select>
          <p v-if="form.errors.location_id" class="mt-1 text-xs text-danger-600">{{ form.errors.location_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Kategori</label>
          <select v-model="form.category_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
          </select>
          <p v-if="form.errors.category_id" class="mt-1 text-xs text-danger-600">{{ form.errors.category_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">COA</label>
          <select v-model="form.account_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
          <p v-if="form.errors.account_id" class="mt-1 text-xs text-danger-600">{{ form.errors.account_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal <span class="text-danger-600">*</span></label>
          <input v-model="form.realization_date" type="date" :class="inputCls" required />
          <p v-if="form.errors.realization_date" class="mt-1 text-xs text-danger-600">{{ form.errors.realization_date }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Tipe <span class="text-danger-600">*</span></label>
          <select v-model="form.type" :class="inputCls" required>
            <option value="saldo_awal">Saldo Awal</option>
            <option value="realisasi">Realisasi</option>
          </select>
          <p v-if="form.errors.type" class="mt-1 text-xs text-danger-600">{{ form.errors.type }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Nilai <span class="text-danger-600">*</span></label>
          <input v-model.number="form.amount" type="number" min="0" step="1" :class="inputCls" required />
          <p v-if="form.errors.amount" class="mt-1 text-xs text-danger-600">{{ form.errors.amount }}</p>
        </div>

        <div class="md:col-span-2">
          <label class="mb-1 block text-sm font-medium">Catatan</label>
          <input v-model="form.note" type="text" :class="inputCls" />
          <p v-if="form.errors.note" class="mt-1 text-xs text-danger-600">{{ form.errors.note }}</p>
        </div>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="submit" :disabled="form.processing">Simpan</Button>
      </div>
    </form>
  </div>
</template>
