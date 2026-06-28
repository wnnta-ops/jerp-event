<!-- BS / COGS / HPP — Generate HPP RealCost (POC: recognize Revenue & HPP at Progress%) -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Calculator } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  projects: Array<any>;
  locations: Array<any>;
  months: string[];
}>();

const now = new Date();
const form = useForm({
  project_id: '',
  location_id: '',
  year: now.getFullYear(),
  month: now.getMonth() + 1,
  default_progress: 100,
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.cogs.hpp.generate.run'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.hpp.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Calculator class="h-6 w-6 text-primary-600" /> Generate HPP RealCost</h1>

    <form class="card space-y-4" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Project <span class="text-red-500">*</span></label>
          <select v-model="form.project_id" :class="inputCls" required>
            <option value="" disabled>Pilih proyek…</option>
            <option v-for="p in props.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p v-if="form.errors.project_id" class="mt-1 text-xs text-red-500">{{ form.errors.project_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Cluster / Lokasi</label>
          <select v-model="form.location_id" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="l in props.locations" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option>
          </select>
          <p v-if="form.errors.location_id" class="mt-1 text-xs text-red-500">{{ form.errors.location_id }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Periode Bulan</label>
          <select v-model="form.month" :class="inputCls">
            <option v-for="(m, i) in props.months" :key="i" :value="i + 1">{{ m }}</option>
          </select>
          <p v-if="form.errors.month" class="mt-1 text-xs text-red-500">{{ form.errors.month }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Tahun</label>
          <input v-model.number="form.year" type="number" :class="inputCls" />
          <p v-if="form.errors.year" class="mt-1 text-xs text-red-500">{{ form.errors.year }}</p>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Progress Default (%)</label>
          <input v-model.number="form.default_progress" type="number" min="0" max="100" :class="inputCls" />
          <p v-if="form.errors.default_progress" class="mt-1 text-xs text-red-500">{{ form.errors.default_progress }}</p>
        </div>
      </div>

      <p class="rounded-lg bg-neutral-50 p-3 text-xs text-neutral-500 dark:bg-neutral-800">
        Menghitung HPP per unit terjual (7 komponen) dari Budget proyek &amp; mengakui Pendapatan/HPP sebesar Progress%. Metode persentase penyelesaian (POC).
      </p>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing">Generate</Button>
      </div>
    </form>
  </div>
</template>
