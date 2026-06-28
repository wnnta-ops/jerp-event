<!-- Pembatalan Surat Perintah Kerja — picker form for cancelling an SPK -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Ban } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{ spks: Array<any> }>();

const today = new Date().toISOString().slice(0, 10);
const woId = ref('');
const form = useForm({ cancel_date: today, reason: '' });

function submit() {
  if (!woId.value) return;
  if (!confirm('Batalkan SPK ini?')) return;
  form.post(route('bs.spk.cancel', woId.value));
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Ban class="h-6 w-6 text-amber-600" /> Pembatalan Surat Perintah Kerja</h1>

    <form class="card max-w-2xl space-y-4 p-4" @submit.prevent="submit">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="woId" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
        </select>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Tanggal</label>
        <input v-model="form.cancel_date" type="date" :class="inputCls" />
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium">Alasan</label>
        <input v-model="form.reason" type="text" :class="inputCls" placeholder="Alasan pembatalan" />
      </div>

      <p class="text-xs text-neutral-500">Hanya bila tak ada termin belum-bayar &amp; retensi tertahan.</p>

      <div class="flex justify-end">
        <Button type="submit" variant="danger" :disabled="!woId || form.processing">Batalkan SPK</Button>
      </div>
    </form>
  </div>
</template>
