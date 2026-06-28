<!-- COGS Setup · Ketentuan (provision: account mapping & allocation basis) -->
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Settings2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  projects: Array<any>;
  accounts: Array<any>;
}>();

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

const form = useForm({
  project_id: '' as string | number,
  acc_uang_muka_id: '' as string | number,
  acc_pendapatan_id: '' as string | number,
  acc_piutang_id: '' as string | number,
  acc_cip_id: '' as string | number,
  acc_persediaan_unit_id: '' as string | number,
  acc_hpp_id: '' as string | number,
  basis: 'price' as string,
  notes: '' as string,
});

function projName(id: number): string {
  return props.projects.find((p) => p.id === id)?.name ?? '—';
}

function submit() {
  form.post(route('bs.cogs.provision.save'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.cogs.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Cost of Good Sold
    </Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold">
      <Settings2 class="h-6 w-6 text-primary-600" /> Ketentuan
    </h1>

    <p class="text-sm text-neutral-500">Akun &amp; dasar alokasi dipakai saat Generate &amp; Posting HPP.</p>

    <form class="card space-y-4" @submit.prevent="submit">
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm font-medium">Project <span class="text-danger-600">*</span></label>
          <select v-model="form.project_id" :class="inputCls">
            <option value="">— Pilih Project —</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc Uang Muka Konsumen</label>
          <select v-model="form.acc_uang_muka_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc Pendapatan</label>
          <select v-model="form.acc_pendapatan_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc Piutang Konsumen</label>
          <select v-model="form.acc_piutang_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc CIP / Pool Biaya</label>
          <select v-model="form.acc_cip_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc Persediaan Unit</label>
          <select v-model="form.acc_persediaan_unit_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Acc Beban Pokok (HPP)</label>
          <select v-model="form.acc_hpp_id" :class="inputCls">
            <option value="">—</option>
            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Dasar Alokasi</label>
          <select v-model="form.basis" :class="inputCls">
            <option value="price">Harga (price)</option>
            <option value="equal">Rata (equal)</option>
            <option value="luas">Luas (luas)</option>
          </select>
        </div>

        <div class="md:col-span-2 lg:col-span-3">
          <label class="mb-1 block text-sm font-medium">Catatan</label>
          <input v-model="form.notes" type="text" :class="inputCls" placeholder="Catatan (opsional)" />
        </div>
      </div>

      <div class="flex justify-end">
        <Button type="submit" :disabled="form.processing || !form.project_id">Simpan</Button>
      </div>
    </form>

    <div v-if="!rows.length">
      <EmptyState title="Belum ada ketentuan" description="Simpan ketentuan akun & dasar alokasi per project." />
    </div>
    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2">Project</th>
            <th class="px-3 py-2">Dasar Alokasi</th>
            <th class="px-3 py-2">Catatan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-medium">{{ projName(r.project_id) }}</td>
            <td class="px-3 py-2">{{ r.basis }}</td>
            <td class="px-3 py-2 text-neutral-500">{{ r.notes || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
