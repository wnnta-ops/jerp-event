<!-- Business Support · Data Center · Supplier · Upload (CSV import) -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Upload } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ header: string[] }>();

const form = useForm({ file: null as any });

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function onPick(e: Event) {
  const files = (e.target as HTMLInputElement).files;
  form.file = files?.[0] ?? null;
}

function submit() {
  form.post(route('bs.dc.supplier.upload.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Data Center</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Upload class="h-6 w-6 text-primary-600" /> Upload Supplier</h1>

    <div class="card space-y-4">
      <div class="space-y-1">
        <label class="text-sm font-medium">Berkas CSV</label>
        <input type="file" accept=".csv,text/csv" :class="inputCls" @input="onPick" />
        <p v-if="form.errors.file" class="text-sm text-red-600">{{ form.errors.file }}</p>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <Button :disabled="form.processing" @click="submit">Upload</Button>
        <a :href="route('bs.dc.supplier.upload.template')" class="text-sm text-primary-600 hover:underline">Unduh Template</a>
      </div>

      <p class="text-xs text-neutral-500">CSV; baris pertama header otomatis dilewati.</p>
    </div>

    <div class="card space-y-2">
      <h2 class="text-sm font-semibold">Kolom yang diharapkan</h2>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-neutral-600 dark:text-neutral-300">
        <li v-for="(col, i) in props.header" :key="i">{{ col }}</li>
      </ol>
    </div>
  </div>
</template>
