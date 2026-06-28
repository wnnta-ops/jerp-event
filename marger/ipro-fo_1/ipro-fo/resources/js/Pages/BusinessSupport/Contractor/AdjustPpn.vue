<!-- Adjustment PPN — set new VAT percentage for an SPK's future progress. -->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Percent } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ spks: Array<any> }>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const today = new Date().toISOString().slice(0, 10);
const woId = ref('');
const form = useForm({ addendum_date: today, new_ppn_pct: 0, note: '' });
const sel = computed(() => props.spks.find((s: any) => s.id === woId.value));

function submit() {
  if (!woId.value) return;
  form.post(route('bs.spk.adjust-ppn', woId.value));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Percent class="h-6 w-6 text-amber-600" /> Adjustment PPN</h1>

    <form class="card space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="woId" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
        </select>
        <p v-if="sel" class="mt-1 text-sm text-neutral-500">PPN saat ini: {{ sel.ppn_pct }}%</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Tanggal</label>
          <input v-model="form.addendum_date" type="date" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">PPN Baru (%) *</label>
          <input v-model.number="form.new_ppn_pct" type="number" step="0.01" min="0" required :class="inputCls" />
        </div>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Catatan</label>
        <input v-model="form.note" type="text" :class="inputCls" />
      </div>

      <p class="text-xs text-neutral-500">Berlaku untuk progress berikutnya; tidak mengubah jurnal lama.</p>

      <div class="flex justify-end">
        <Button type="submit" :disabled="!woId || form.processing">Simpan PPN</Button>
      </div>
    </form>
  </div>
</template>
