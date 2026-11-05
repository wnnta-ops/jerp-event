<!-- Approval Alokasi Budget — batch approval page -->
<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
  rows: Array<any>;
  canApprove: boolean;
  summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

const sel = ref<number[]>([]);
function toggle(id: number) { const i = sel.value.indexOf(id); i >= 0 ? sel.value.splice(i, 1) : sel.value.push(id); }
/** Hanya alokasi yang tahapnya giliran saya (atau alokasi lama tanpa Approval Inbox) yang bisa dicentang. */
const bisaDipilih = () => props.rows.filter((r: any) => r.bisa);
function allChecked() { return bisaDipilih().length > 0 && sel.value.length === bisaDipilih().length; }
function toggleAll() { sel.value = allChecked() ? [] : bisaDipilih().map((r: any) => r.id); }
function decide(decision: 'approve' | 'reject') {
  if (!sel.value.length) return;
  router.post(route('bs.budget.allocation.approve-batch'), { ids: sel.value, decision }, { preserveScroll: true, onSuccess: () => { sel.value = []; } });
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-primary-600" /> Approval Alokasi Budget</h1>
        <p class="mt-1 text-sm text-neutral-500">Alokasi dari Registrasi Invoice Hutang menunggu di sini (sama dengan Approval Inbox) — anggaran baru dipindah setelah disetujui, lalu invoicenya bisa diselesaikan.</p>
        <p class="text-sm text-neutral-500">{{ summary.count }} alokasi menunggu persetujuan · Rp {{ fmtRp(summary.total) }}</p>
      </div>
      <div v-if="canApprove" class="flex items-center gap-2">
        <Button class="bg-green-600 text-white hover:bg-green-700" :disabled="sel.length === 0" @click="decide('approve')">Setujui</Button>
        <Button class="bg-red-600 text-white hover:bg-red-700" :disabled="sel.length === 0" @click="decide('reject')">Tolak</Button>
      </div>
    </div>

    <EmptyState v-if="rows.length === 0" title="Tidak ada alokasi menunggu persetujuan" description="Semua alokasi budget sudah diproses." />

    <div v-else class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <th class="px-3 py-2"><input type="checkbox" :checked="allChecked()" @change="toggleAll" /></th>
            <th class="px-3 py-2">No. Alokasi</th>
            <th class="px-3 py-2">Tahun</th>
            <th class="px-3 py-2">Tgl</th>
            <th class="px-3 py-2">Dari (budget asal)</th>
            <th class="px-3 py-2">Ke (budget tujuan)</th>
            <th class="px-3 py-2">Periode</th>
            <th class="px-3 py-2">Sumber</th>
            <th class="px-3 py-2 text-right">Nilai</th>
            <th class="px-3 py-2">Tahap</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
          <tr v-for="r in rows" :key="r.id">
            <td class="px-3 py-2"><input type="checkbox" :disabled="!r.bisa" :checked="sel.includes(r.id)" @change="toggle(r.id)" /></td>
            <td class="px-3 py-2 font-medium"><a v-if="r.approval_url" :href="r.approval_url" class="text-primary-600 hover:underline">{{ r.doc_no }}</a><template v-else>{{ r.doc_no }}</template><a v-if="r.lampiran_url" :href="r.lampiran_url" target="_blank" rel="noopener" class="ml-1.5 whitespace-nowrap text-[11px] font-normal text-primary-600 hover:underline" title="Lampiran perubahan budget">Lampiran ↗</a></td>
            <td class="px-3 py-2">{{ r.year }}</td>
            <td class="px-3 py-2">{{ fmtDate(r.date) }}</td>
            <td class="px-3 py-2">{{ r.account }}<div class="text-[11px] text-neutral-500">{{ r.cost_center }}<template v-if="r.ref"> · {{ r.ref }}</template></div></td>
            <td class="px-3 py-2"><template v-if="r.to_account">{{ r.to_account }}<div class="text-[11px] text-neutral-500">{{ r.to_cost_center }} · {{ r.to_ref }}</div></template><span v-else class="text-neutral-400">—</span></td>
            <td class="px-3 py-2">{{ r.period }}</td>
            <td class="px-3 py-2"><span v-if="r.invoice" class="rounded bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/30 dark:text-primary-300">Invoice {{ r.invoice }}</span><span v-else class="text-neutral-400">—</span><div v-if="r.notes" class="text-[11px] text-neutral-500">{{ r.notes }}</div></td>
            <td class="px-3 py-2 text-right">{{ fmtRp(r.amount) }}</td>
            <td class="px-3 py-2 text-xs"><template v-if="r.tahap">{{ r.tahap }}</template><span v-else class="text-neutral-400">—</span><div v-if="r.ikut_invoice" class="text-[11px] text-neutral-400">lihat saja — disetujui bersama invoicenya</div><div v-else-if="!r.bisa" class="text-[11px] text-neutral-400">bukan giliran Anda</div></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
