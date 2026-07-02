<script setup lang="ts">
// Kontrol Progress Lapangan — KPL list (paginated)
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCheck, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: any;
  filters: { search?: string };
}>();

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger', active: 'info', closed: 'success', cancelled: 'danger', open: 'warning', unpaid: 'warning', paid: 'success' };

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const search = ref(props.filters.search ?? '');
function submit() {
  router.get(route('bs.spk.kpl.index'), { search: search.value || undefined }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardCheck class="h-6 w-6 text-amber-600" /> Kontrol Progress Lapangan</h1>
      <Link :href="route('bs.spk.kpl.create')"><Button><Plus class="h-4 w-4" /> Registrasi KPL</Button></Link>
    </div>

    <form class="card flex flex-wrap items-end gap-3 p-4" @submit.prevent="submit">
      <div class="grow">
        <label class="mb-1 block text-xs font-medium text-neutral-500">Cari</label>
        <input v-model="search" :class="inputCls" placeholder="No. KPL, SPK, kontraktor…" />
      </div>
      <Button type="submit">Tampilkan</Button>
    </form>

    <div v-if="rows.data.length" class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="w-6 px-3 py-2"></th>
            <th class="px-3 py-2">No. KPL</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">SPK</th>
            <th class="px-3 py-2">Kontraktor</th>
            <th class="px-3 py-2">Uraian</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2 text-right">Net</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows.data" :key="r.id" @click="router.get(route('bs.spk.kpl.show', r.id))" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800">
            <td class="px-3 py-2 text-primary-600 dark:text-primary-400" title="Lihat detail">›</td>
            <td class="px-3 py-2 font-mono text-xs">{{ r.kpl_number }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.progress_date) }}</td>
            <td class="px-3 py-2">{{ r.work_order?.spk_number }}</td>
            <td class="px-3 py-2">{{ r.work_order?.supplier?.name }}</td>
            <td class="px-3 py-2">{{ r.description }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.progress_value) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.net_payable) }}</td>
            <td class="px-3 py-2"><Badge :variant="stBadge[r.status]">{{ r.status }}</Badge></td>
          </tr>
        </tbody>
      </table>

      <div v-if="rows.links?.length > 3" class="flex flex-wrap gap-1 p-3 text-sm">
        <template v-for="(l, i) in rows.links" :key="i">
          <Link v-if="l.url" :href="l.url" v-html="l.label" class="rounded px-3 py-1" :class="l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700'" preserve-scroll />
          <span v-else v-html="l.label" class="rounded px-3 py-1 text-neutral-400" />
        </template>
      </div>
    </div>

    <EmptyState v-else title="Belum ada KPL" description="Belum ada data Kontrol Progress Lapangan yang terdaftar." />
  </div>
</template>
