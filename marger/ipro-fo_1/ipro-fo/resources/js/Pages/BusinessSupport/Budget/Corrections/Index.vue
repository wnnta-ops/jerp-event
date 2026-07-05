<!-- Budget Control · Koreksi Budget index -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  year: number;
  rows: Array<any>;
  statuses: Record<string, string>;
  summary: { count: number };
  filterStatus?: string;
  pending?: number;
}>();

const year = ref(props.year);
const statusFilter = ref<string>(props.filterStatus ?? 'approved');

const stBadge: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger' };

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

function fmtDate(d: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function reload() {
  router.get(route('bs.budget.correction.index'), { year: year.value, status: statusFilter.value }, { preserveState: true, replace: true });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Budget Control
    </Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold">
        <Pencil class="h-6 w-6 text-primary-600" /> Koreksi Budget
      </h1>
      <div class="flex items-center gap-2">
        <input v-model.number="year" type="number" :class="inputCls" class="!w-28" @change="reload" />
        <select v-model="statusFilter" @change="reload" :class="inputCls" class="!w-auto" title="Filter status"><option value="approved">Disetujui</option><option value="baru">Menunggu Approval</option><option value="rejected">Ditolak</option><option value="all">Semua Status</option></select>
        <Link :href="route('bs.budget.correction.create')">
          <Button>
            <Plus class="h-4 w-4" /> Koreksi Baru
          </Button>
        </Link>
      </div>
    </div>

    <Link v-if="pending && statusFilter === 'approved'" :href="route('bs.budget.correction.approvals')" class="flex items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
      <span><b>{{ pending }}</b> koreksi menunggu approval — belum tampil di daftar ini.</span>
      <span class="font-semibold underline">Buka Approval Koreksi Budget →</span>
    </Link>

    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs uppercase text-neutral-500 bg-neutral-50 dark:bg-neutral-800">
            <th class="px-3 py-2">No. Koreksi</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Ref. Budget</th>
            <th class="px-3 py-2">Akun</th>
            <th class="px-3 py-2">Cost Center</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2 text-right">Nilai Lama</th>
            <th class="px-3 py-2 text-right">Nilai Baru</th>
            <th class="px-3 py-2">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2 font-mono text-xs">{{ r.doc_no }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.ref }}</td>
            <td class="px-3 py-2">{{ r.account }}</td>
            <td class="px-3 py-2">{{ r.cost_center }}</td>
            <td class="px-3 py-2">{{ r.period }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.old_amount) }}</td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.new_amount) }}</td>
            <td class="px-3 py-2">
              <Badge :variant="stBadge[r.status]">{{ statuses[r.status] ?? r.status }}</Badge>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="rows.length === 0" title="Belum ada koreksi" description="Belum ada data koreksi budget untuk ditampilkan." />
    </div>

    <p class="text-sm text-neutral-500">{{ summary.count }} koreksi</p>
  </div>
</template>
