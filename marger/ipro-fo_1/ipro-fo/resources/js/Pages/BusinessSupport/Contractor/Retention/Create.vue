<!-- Registrasi Retensi — cairkan retensi SPK & tutup work order -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Unlock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
  spks: Array<any>;
  cashAccounts: Array<any>;
  defaultCash: number | null;
}>();

function fmtRp(v:number){return 'Rp '+Number(v??0).toLocaleString('id-ID');}

const today = new Date().toISOString().slice(0,10);

const form = useForm({
  work_order_id: '',
  settled_date: today,
  cash_account_id: props.defaultCash ?? '',
});

const sel = computed(() => props.spks.find((s:any) => s.id === form.work_order_id));

const inputCls='h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit(){ form.post(route('bs.spk.retention.store')); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.retention.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><Unlock class="h-6 w-6 text-amber-600" /> Registrasi Retensi</h1>

    <form @submit.prevent="submit" class="card space-y-4 p-4">
      <div>
        <label class="mb-1 block text-sm font-medium">SPK</label>
        <select v-model="form.work_order_id" :class="inputCls">
          <option value="">— pilih SPK —</option>
          <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }} (Retensi {{ fmtRp(s.retention_held) }})</option>
        </select>
      </div>

      <div v-if="sel" class="rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
        <p class="font-semibold text-amber-700 dark:text-amber-400">Retensi akan dicairkan: {{ fmtRp(sel.retention_held) }}</p>
        <p class="mt-1 text-xs text-neutral-500">Jurnal: Dr Hutang Retensi / Cr Kas. SPK akan ditutup.</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">Tgl Cair</label>
          <input v-model="form.settled_date" type="date" :class="inputCls" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Dari Kas/Bank</label>
          <select v-model="form.cash_account_id" :class="inputCls">
            <option value="">— pilih —</option>
            <option v-for="a in props.cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>
      </div>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing">Cairkan &amp; Tutup SPK</Button>
      </div>
    </form>
  </div>
</template>
