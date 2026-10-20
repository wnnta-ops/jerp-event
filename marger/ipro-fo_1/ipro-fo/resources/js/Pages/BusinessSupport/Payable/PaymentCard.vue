<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Receipt, FileSpreadsheet, Printer } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import PilihPosisiHutang from '@/Components/UI/PilihPosisiHutang.vue';

const props = defineProps<{ payments: any; suppliers: Array<any>; supplierId: number | null; total: number; posisi?: string; posisiOpsi?: Array<{ nilai: string; label: string }> }>();
const selected = ref(props.supplierId ?? '');
const posisi = ref(props.posisi ?? 'semua');
function display() { router.get(route('bs.ap.payment-card'), { supplier_id: selected.value || undefined, posisi: posisi.value }, { preserveState: true, replace: true }); }

// Tautan ekspor membaca props.*, BUKAN ref lokal. Ref-nya berubah begitu operator menyentuh
// kontrol, sedangkan layarnya baru berubah setelah permintaan kembali — berkas yang terunduh
// di sela itu akan berbeda dari yang sedang tampil, tanpa ada yang menandainya.
const paramEkspor = computed(() => ({
    supplier_id: props.supplierId || undefined,
    posisi: props.posisi ?? 'semua',
}));
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Receipt class="h-6 w-6 text-primary-600" /> Kartu Pembayaran Hutang</h1>
        <p class="text-sm text-neutral-500">Riwayat pembayaran hutang (dari Kuitansi Hutang & pelunasan invoice).</p>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex-1 min-w-[240px]">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier</label>
                <select v-model="selected" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" @change="display"><option value="">— semua supplier —</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
            </div>
            <PilihPosisiHutang v-model="posisi" :opsi="props.posisiOpsi" @ubah="display" />
            <a :href="route('bs.ap.payment-card.export-excel', paramEkspor)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
            <a :href="route('bs.ap.payment-card.export-pdf', paramEkspor)" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-danger-300 bg-danger-50 px-3 text-sm font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-4 w-4" /> PDF</a>
            <div class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Total Pembayaran</div><div class="font-mono text-lg font-bold text-emerald-600">Rp {{ fmtRp(total) }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Tanggal Bayar</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">No Invoice</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3 text-right">Nominal</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in payments.data" :key="p.id">
                        <td class="px-4 py-2.5">{{ fmtDate(p.payment_date) }}</td>
                        <td class="px-4 py-2.5">{{ p.invoice?.supplier?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ p.invoice?.invoice_number ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ p.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(p.amount) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!payments.data?.length" title="Belum ada pembayaran" description="Belum ada riwayat pembayaran hutang." />
        </div>
        <Pagination :data="payments" />
    </div>
</template>
