<!-- Upload Massal Aset Tetap — Business Support / Aset Tetap -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Upload, Download } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  header: string[];
  categories: Record<string, string>;
}>();

const form = useForm({ file: null as any });

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
  form.post(route('bs.fa.upload.store'), { forceFormData: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Aset Tetap
    </Link>

    <div class="flex items-center justify-between gap-2">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Upload class="h-6 w-6 text-primary-600" /> Upload Massal Aset Tetap
      </h1>
      <a :href="route('bs.fa.upload.template')">
        <Button variant="secondary"><Download class="h-4 w-4" /> Unduh Template</Button>
      </a>
    </div>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">File Excel (.xlsx)</label>
        <input
          type="file"
          accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          :class="inputCls"
          class="py-1"
          @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
        <p v-if="form.errors.file" class="mt-1 text-sm text-danger-600">{{ form.errors.file }}</p>
      </div>

      <div class="rounded-lg bg-neutral-50 p-3 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
        <p class="mb-1 font-medium">Catatan:</p>
        <ul class="list-inside list-disc space-y-0.5 text-[13px]">
          <li>Unggah template Java Core (.xlsx) langsung — unduh template di kanan atas.</li>
          <li><b>No. Aset</b> dipakai apa adanya bila diisi; bila kosong → nomor dibuat otomatis.</li>
          <li><b>category</b> menerima nama (Bangunan/Kendaraan/Inventaris/Tanah) maupun kode.</li>
          <li>Tidak memposting jurnal perolehan (aset dianggap sudah tercatat). Baris yang gagal dilaporkan.</li>
        </ul>
      </div>

      <div>
        <Button type="submit" :disabled="form.processing"><Upload class="h-4 w-4" /> Upload</Button>
      </div>
    </form>

    <div class="grid gap-4 lg:grid-cols-2">
      <div class="card overflow-x-auto">
        <div class="border-b border-neutral-200 px-3 py-2 text-xs font-semibold uppercase text-neutral-500 dark:border-neutral-800">Kolom Template</div>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
            <tr v-for="(col, i) in header" :key="col">
              <td class="px-3 py-2 text-neutral-500">{{ i + 1 }}</td>
              <td class="px-3 py-2 font-mono">{{ col }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card overflow-x-auto">
        <div class="border-b border-neutral-200 px-3 py-2 text-xs font-semibold uppercase text-neutral-500 dark:border-neutral-800">Kategori yang valid</div>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
            <tr v-for="(label, key) in categories" :key="key">
              <td class="px-3 py-2 font-mono text-neutral-500">{{ key }}</td>
              <td class="px-3 py-2">{{ label }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
