<!-- COGS Setup: CSV upload page with expected-column legend. -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Upload } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  kind: string;
  meta: { title: string; header: string[]; route: string };
}>();

const form = useForm({ file: null as any });

function submit() {
  form.post(route(props.meta.route));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cost of Good Sold</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Upload class="h-6 w-6 text-primary-600" /> {{ meta.title }}</h1>

    <form class="card space-y-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">File CSV</label>
        <input
          type="file"
          accept=".csv,text/csv"
          class="block w-full text-sm text-neutral-700 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-600 file:px-3 file:py-2 file:text-sm file:text-white hover:file:bg-primary-700 dark:text-neutral-200"
          @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
        <p v-if="form.errors.file" class="mt-1 text-sm text-danger-600">{{ form.errors.file }}</p>
        <p class="mt-1 text-xs text-neutral-500">Format CSV. Baris pertama boleh header (otomatis dilewati).</p>
      </div>

      <Button type="submit" :disabled="form.processing">
        <Upload class="h-4 w-4" /> Upload
      </Button>
    </form>

    <div class="card overflow-x-auto">
      <h2 class="mb-2 text-sm font-semibold">Kolom yang Diharapkan</h2>
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 dark:bg-neutral-800">
          <tr class="text-left text-xs uppercase text-neutral-500">
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Kolom</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(col, i) in meta.header" :key="i">
            <td class="px-3 py-2 text-neutral-500">{{ i + 1 }}</td>
            <td class="px-3 py-2 font-medium">{{ col }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
