<script setup lang="ts">
// Detail Kontrol Progress Lapangan + preview & Cetak BAP (Berita Acara Pembayaran)
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCheck, Printer } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ kpl: any; bap: any }>();

const stBadge: Record<string, any> = { unpaid: 'warning', paid: 'success', open: 'warning', closed: 'success' };
function rp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d: string): string { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
function cetak() { window.open(route('bs.spk.kpl.bap', props.kpl.id), '_blank'); }

const b = props.bap;
// Baris tabel perincian BAP (label, nilai, operator, penekanan).
const lines = [
  { l: 'Nilai Kontrak Awal', v: b.contract_gross, op: '= Rp.' },
  { l: 'Nilai Pekerjaan Tambah Kurang', v: b.addendum_gross, op: '+' },
  { l: 'Nilai Kontrak Akhir', v: b.final_gross, op: '= Rp.', strong: true },
  { sp: true },
  { l: `Nilai Pekerjaan telah dilaksanakan s/d kini (${b.progress_pct}%)`, v: b.progress_val, op: 'Rp.' },
  { l: `Uang muka (${b.advance_pct}%)`, v: b.uang_muka, op: '+' },
  { l: 'Pengurangan pengembalian uang muka', v: b.dp_recovery, op: '+' },
  { l: 'Sub total', v: b.sub_total, op: '= Rp.', strong: true },
  { l: `Pengurangan Retensi (${b.retensi_pct}%) x Nilai Progress`, v: b.retensi, op: '+' },
  { l: 'Jumlah setelah Retensi', v: b.after_retensi, op: '= Rp.', strong: true },
  { l: 'Pembayaran yang telah dibayarkan s/d kini', v: b.prev_paid, op: '+' },
  { l: 'Total Pembayaran yang dapat dibayarkan sekarang', v: b.payable_now, op: '= Rp.', tot: true },
  { sp: true },
  { l: 'Sisa Pembayaran sebelum pembayaran ini', v: b.sisa_sebelum, op: 'Rp.' },
  { l: 'Sisa Pembayaran setelah pembayaran ini', v: b.sisa_setelah, op: 'Rp.' },
];
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.kpl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kontrol Progress Lapangan</Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardCheck class="h-6 w-6 text-amber-600" /> {{ kpl.kpl_number }}</h1>
      <div class="flex items-center gap-2">
        <Badge :variant="stBadge[kpl.status]">{{ kpl.status }}</Badge>
        <Button @click="cetak"><Printer class="h-4 w-4" /> Cetak BAP</Button>
      </div>
    </div>

    <!-- Info KPL / SPK -->
    <div class="card grid gap-x-8 gap-y-2 p-4 text-sm sm:grid-cols-2">
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">SPK</span><span class="font-mono">{{ kpl.work_order?.spk_number }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Kontraktor</span><span class="font-medium">{{ kpl.work_order?.supplier?.name }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Pekerjaan</span><span>{{ kpl.work_order?.title }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Tanggal</span><span>{{ fmtDate(kpl.progress_date) }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Uraian</span><span>{{ kpl.description }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">No. BAP</span><span class="font-mono">{{ bap.bap_number }} (ke-{{ bap.payment_seq }})</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Releaser / PIC</span><span>{{ kpl.releaser || '—' }} / {{ kpl.pic || '—' }}</span></div>
      <div class="flex justify-between border-b border-neutral-100 py-1 dark:border-neutral-800"><span class="text-neutral-500">Penandatangan Kontraktor</span><span>{{ kpl.contractor_signer || '—' }}</span></div>
    </div>

    <!-- Preview perincian BAP -->
    <div class="card p-4">
      <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-semibold">Perincian Pembayaran (BAP)</h2>
        <span class="text-xs text-neutral-500">nilai termasuk PPN {{ bap.ppn_pct }}%</span>
      </div>
      <table class="w-full text-sm">
        <tbody>
          <template v-for="(row, i) in lines" :key="i">
            <tr v-if="row.sp"><td colspan="3" class="h-2"></td></tr>
            <tr v-else :class="row.tot ? 'bg-neutral-100 font-semibold dark:bg-neutral-800' : row.strong ? 'font-semibold' : ''">
              <td class="px-2 py-1.5">{{ row.l }}</td>
              <td class="px-2 py-1.5 text-center text-neutral-500">{{ row.op }}</td>
              <td class="border-b border-neutral-200 px-2 py-1.5 text-right font-mono dark:border-neutral-700">{{ rp(row.v) }}</td>
            </tr>
          </template>
        </tbody>
      </table>
      <p class="mt-3 text-xs text-neutral-500">Angka dihitung otomatis dari SPK (Nilai Kontrak, Uang Muka {{ bap.advance_pct }}%, Retensi {{ bap.retensi_pct }}%) + akumulasi termin sebelumnya — sama seperti template Excel BAP. Klik <b>Cetak BAP</b> untuk hasil cetak.</p>
    </div>
  </div>
</template>
