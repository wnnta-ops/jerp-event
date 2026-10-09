<!-- Upload Budget — Business Support / Budget Control -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Upload, Download, AlertTriangle, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  year: number;
  costCenters: Array<any>;
  header: string[];
}>();

const form = useForm({
  year: props.year,
  project: '',
  cost_center_id: '',
  file: null as any,
});

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

/* Penolakan upload tampil sebagai DIALOG, bukan toast: galatnya bisa puluhan baris, dan
   justru baris itulah yang harus dibetulkan orang. Toast memampatkannya jadi satu kalimat
   lalu hilang sebelum sempat dibaca. */
const page = usePage();
const galat = computed<any>(() => (page.props as any)?.flash?.uploadError ?? null);
const tampilGalat = ref(false);
watch(galat, (v) => { if (v) tampilGalat.value = true; }, { immediate: true });

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

function fmtDate(d: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function submit() {
  form.post(route('bs.budget.upload.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Budget Control
    </Link>

    <div class="flex items-center justify-between gap-2">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Upload class="h-6 w-6 text-primary-600" /> Upload Budget
      </h1>
      <a :href="route('bs.budget.upload.template')">
        <Button variant="secondary">
          <Download class="h-4 w-4" /> Unduh Template
        </Button>
      </a>
    </div>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm font-medium">Tahun</label>
          <input v-model.number="form.year" type="number" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Project</label>
          <input v-model="form.project" type="text" placeholder="JR" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Cost Center</label>
          <select v-model="form.cost_center_id" :class="inputCls">
            <option value="">Semua</option>
            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
          <label class="mb-1 block text-sm font-medium">File XLSX</label>
          <input
            type="file"
            accept=".csv,.txt"
            :class="inputCls"
            class="py-1"
            @input="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
          />
          <p v-if="form.errors.file" class="mt-1 text-sm text-danger-600">{{ form.errors.file }}</p>
        </div>
      </div>

      <p class="text-sm text-neutral-500">
        Kode akun & cost center harus sesuai master. Akun yang memang tidak dianggarkan (kas, bank, piutang, utang, penyusutan, HPP) akan ditolak. Bila ada satu baris yang salah, <b>tidak ada</b> yang disimpan. Kolom Cost Center boleh dikosongkan — isinya mengikuti pilihan di atas.
      </p>

      <div>
        <Button type="submit" :disabled="form.processing">
          <Upload class="h-4 w-4" /> Upload
        </Button>
      </div>
    </form>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Kolom</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="(col, i) in header" :key="col">
            <td class="px-3 py-2 text-neutral-500">{{ i + 1 }}</td>
            <td class="px-3 py-2 font-medium">{{ col }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

    <!-- Penolakan upload: daftar baris yang salah, lengkap dengan nomor barisnya. -->
    <div v-if="tampilGalat && galat" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="tampilGalat = false">
      <div class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
        <div class="flex items-start gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
          <AlertTriangle class="mt-0.5 h-6 w-6 shrink-0 text-danger-500" />
          <div class="flex-1">
            <h3 class="text-lg font-bold">{{ galat.judul }}</h3>
            <p class="text-sm text-neutral-500">{{ galat.pesan }}</p>
          </div>
          <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="tampilGalat = false">
            <X class="h-5 w-5" />
          </button>
        </div>
        <div class="flex-1 overflow-y-auto px-5 py-4">
          <ul class="space-y-1.5 text-sm">
            <li v-for="(g, i) in (galat.baris ?? [])" :key="i" class="flex gap-2">
              <span class="text-danger-500">&bull;</span><span>{{ g }}</span>
            </li>
          </ul>
          <p v-if="galat.lainnya" class="mt-3 text-xs text-neutral-400">… dan {{ galat.lainnya }} baris lain dengan masalah serupa.</p>
        </div>
        <div class="flex justify-end border-t border-neutral-200 px-5 py-3 dark:border-neutral-800">
          <Button variant="primary" size="md" @click="tampilGalat = false">Mengerti</Button>
        </div>
      </div>
    </div>
</template>
