<!-- Budget Control: Alokasi Budget Baru — pindah anggaran budget A (COA lama) → budget B (COA baru) per bulan -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Layers } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
  year: number;
  budgets: Array<{ id: number; doc_no: string; account: string; cost_center: string; amount: number }>;
  monthLabels: string[];
}>();

function fmtRp(v: number) { return 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID'); }

const today = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

const form = useForm<{ budget_id: number | ''; to_budget_id: number | ''; alloc_date: string; months: Record<number, number>; notes: string }>({
  budget_id: '',
  to_budget_id: '',
  alloc_date: today,
  months: {},
  notes: '',
});

const opsi = (kecuali: number | '') => props.budgets.filter((b) => b.id !== kecuali)
  .map((b) => ({ id: b.id, label: `${b.cost_center} — ${b.account}`, sub: `${b.doc_no} · ${fmtRp(b.amount)}` }));

/* Angka per bulan kedua budget (anggaran berlaku, realisasi, sisa) dari bs.budget.bulan. */
type Bulan = { anggaran: number[]; realisasi: number[]; sisa: number[]; account: string; cost_center: string; doc_no: string };
const asal = ref<Bulan | null>(null);
const tujuan = ref<Bulan | null>(null);
async function muat(id: number | '', tujuanRef: typeof asal) {
  tujuanRef.value = null;
  if (!id) return;
  const { data } = await window.axios.get(route('bs.budget.bulan', id));
  tujuanRef.value = data;
}
watch(() => form.budget_id, (id) => { pindah.value = Array(12).fill(0); muat(id, asal); });
watch(() => form.to_budget_id, (id) => muat(id, tujuan));

/** Nilai yang dipindah per bulan (Jan..Des). */
const pindah = ref<number[]>(Array(12).fill(0));
const nilai = (i: number) => Number(pindah.value[i]) || 0;
const lewat = (i: number) => !!asal.value && nilai(i) > Math.max(0, asal.value.sisa[i]) + 0.001;
const total = computed(() => pindah.value.reduce((t, _, i) => t + nilai(i), 0));
const adaLewat = computed(() => pindah.value.some((_, i) => lewat(i)));
const sum = (xs: number[] | undefined) => (xs ?? []).reduce((t, x) => t + (Number(x) || 0), 0);
/** Isi semua bulan yang masih bersisa dengan seluruh sisanya. */
function ambilSemuaSisa() {
  if (asal.value) pindah.value = asal.value.sisa.map((v) => Math.max(0, v));
}

const bisaSimpan = computed(() => !!form.budget_id && !!form.to_budget_id && total.value > 0 && !adaLewat.value && !form.processing);
function submit() {
  form.transform((d) => ({ ...d, months: Object.fromEntries(pindah.value.map((v, i) => [i + 1, Number(v) || 0]).filter(([, v]) => (v as number) > 0)) }))
    .post(route('bs.budget.allocation.store'));
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

    <div class="flex items-center justify-between">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Alokasi Budget Baru</h1>
      <Link :href="route('bs.budget.allocation.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Daftar Alokasi</Link>
    </div>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <p class="text-sm text-neutral-500">Pindahkan anggaran dari budget asal (COA lama) ke budget tujuan (COA baru) per bulan. Langsung berlaku tanpa approval; yang bisa dipindah paling banyak sisa bulan itu di budget asal.</p>

      <div class="grid items-end gap-3 md:grid-cols-[1fr_auto_1fr]">
        <div>
          <label class="mb-1 block text-sm font-medium">Budget Asal (COA lama)</label>
          <SearchSelect v-model="form.budget_id" :options="opsi(form.to_budget_id)" placeholder="— pilih budget asal —" :no-truncate="true" />
        </div>
        <ArrowRight class="mx-auto hidden h-5 w-5 text-neutral-400 md:block" />
        <div>
          <label class="mb-1 block text-sm font-medium">Budget Tujuan (COA baru)</label>
          <SearchSelect v-model="form.to_budget_id" :options="opsi(form.budget_id)" placeholder="— pilih budget tujuan —" :no-truncate="true" />
        </div>
      </div>

      <div class="grid gap-3 sm:grid-cols-[14rem_1fr]">
        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.alloc_date" type="date" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Catatan</label>
          <input v-model="form.notes" type="text" maxlength="200" :class="inputCls" placeholder="Alasan pemindahan…" />
        </div>
      </div>

      <div v-if="asal" class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
            <tr>
              <th rowspan="2" class="px-3 py-2 text-left">Bulan</th>
              <th colspan="3" class="border-l border-neutral-200 px-3 pt-2 text-center dark:border-neutral-700">Asal · {{ asal.doc_no }}</th>
              <th rowspan="2" class="w-44 border-l border-neutral-200 px-3 py-2 text-right dark:border-neutral-700">
                Dipindah
                <button type="button" class="mt-0.5 block w-full text-right text-[10px] font-medium normal-case text-primary-600 hover:underline" @click="ambilSemuaSisa">ambil semua sisa</button>
              </th>
              <th colspan="3" class="border-l border-neutral-200 px-3 pt-2 text-center dark:border-neutral-700">Tujuan<span v-if="tujuan"> · {{ tujuan.doc_no }}</span></th>
            </tr>
            <tr>
              <th class="border-l border-neutral-200 px-3 pb-2 text-right dark:border-neutral-700">Anggaran</th><th class="px-3 pb-2 text-right">Sisa</th><th class="px-3 pb-2 text-right">Sisa setelah</th>
              <th class="border-l border-neutral-200 px-3 pb-2 text-right dark:border-neutral-700">Anggaran</th><th class="px-3 pb-2 text-right">Sisa</th><th class="px-3 pb-2 text-right">Sisa setelah</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(m, i) in props.monthLabels" :key="i" class="border-t border-neutral-100 dark:border-neutral-800" :class="nilai(i) > 0 ? 'bg-sky-50/60 dark:bg-sky-950/20' : ''">
              <td class="px-3 py-1.5 font-medium">{{ m }}</td>
              <td class="border-l border-neutral-100 px-3 py-1.5 text-right font-mono dark:border-neutral-800">{{ fmtRp(asal.anggaran[i]) }}</td>
              <td class="px-3 py-1.5 text-right font-mono" :class="asal.sisa[i] < 0 ? 'text-danger-600' : 'text-neutral-600 dark:text-neutral-300'">{{ fmtRp(asal.sisa[i]) }}</td>
              <td class="px-3 py-1.5 text-right font-mono" :class="lewat(i) ? 'font-semibold text-danger-600' : 'text-neutral-500'">{{ fmtRp(asal.sisa[i] - nilai(i)) }}</td>
              <td class="border-l border-neutral-100 px-3 py-1.5 dark:border-neutral-800">
                <MoneyInput :model-value="pindah[i]" @update:model-value="(v) => (pindah[i] = Number(v) || 0)" :disabled="asal.sisa[i] <= 0" :class="['!h-8 font-mono', lewat(i) ? '!border-danger-400 !ring-1 !ring-danger-300' : '']" />
              </td>
              <template v-if="tujuan">
                <td class="border-l border-neutral-100 px-3 py-1.5 text-right font-mono dark:border-neutral-800">{{ fmtRp(tujuan.anggaran[i]) }}</td>
                <td class="px-3 py-1.5 text-right font-mono" :class="tujuan.sisa[i] < 0 ? 'text-danger-600' : 'text-neutral-600 dark:text-neutral-300'">{{ fmtRp(tujuan.sisa[i]) }}</td>
                <td class="px-3 py-1.5 text-right font-mono" :class="tujuan.sisa[i] + nilai(i) < 0 ? 'text-danger-600' : 'text-success-700'">{{ fmtRp(tujuan.sisa[i] + nilai(i)) }}</td>
              </template>
              <td v-else colspan="3" class="border-l border-neutral-100 px-3 py-1.5 text-center text-xs text-neutral-400 dark:border-neutral-800">pilih budget tujuan</td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
            <tr>
              <td class="px-3 py-2">Total</td>
              <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(asal.anggaran)) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(asal.sisa)) }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(asal.sisa) - total) }}</td>
              <td class="px-3 py-2 text-right font-mono text-sky-700 dark:text-sky-300">{{ fmtRp(total) }}</td>
              <template v-if="tujuan">
                <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(tujuan.anggaran)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(tujuan.sisa)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtRp(sum(tujuan.sisa) + total) }}</td>
              </template>
              <td v-else colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p v-if="adaLewat" class="text-xs text-danger-600">Nilai yang dipindah melebihi sisa bulan itu di budget asal.</p>

      <div class="flex items-center justify-end gap-3">
        <Link :href="route('bs.budget.allocation.index')" class="text-sm text-neutral-500 hover:underline">Batal</Link>
        <Button type="submit" :disabled="!bisaSimpan">Simpan Alokasi</Button>
      </div>
    </form>
  </div>
</template>
