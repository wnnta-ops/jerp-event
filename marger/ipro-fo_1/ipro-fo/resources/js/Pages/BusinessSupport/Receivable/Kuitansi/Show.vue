<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ReceiptText, Ban, BookText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ kuitansi: any; alat: Record<string, string> }>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
function voidIt() { if (confirm('Void kuitansi ini? Penerimaan akan dibatalkan dan jurnal balik dibuat.')) router.post(route('bs.ar.kuitansi.void', props.kuitansi.id), {}, { preserveScroll: true }); }
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <Link :href="route('bs.ar.kuitansi.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kuitansi Piutang</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-xl font-bold"><ReceiptText class="h-6 w-6 text-success-600" /> {{ kuitansi.kuitansi_number }}</h1>
            <Badge :variant="kuitansi.status === 'void' ? 'danger' : 'success'">{{ kuitansi.status === 'void' ? 'Void' : 'Aktif' }}</Badge>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Customer</div><div class="font-medium">{{ kuitansi.customer?.full_name }}</div></div>
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(kuitansi.kuitansi_date) }}</div></div>
            <div><div class="text-neutral-500">Alat Transaksi</div><div class="font-medium">{{ alat[kuitansi.alat_transaksi] }}</div></div>
            <div><div class="text-neutral-500">Ke Kas/Bank</div><div class="font-medium">{{ kuitansi.cash_account?.name }}</div></div>
            <div><div class="text-neutral-500">Kasir</div><div class="font-medium">{{ kuitansi.kasir ?? '—' }}</div></div>
            <div><div class="text-neutral-500">No. Manual</div><div class="font-medium">{{ kuitansi.manual_no ?? '—' }}</div></div>
            <div class="md:col-span-3 flex items-center justify-between rounded-lg bg-neutral-50 px-4 py-3 dark:bg-neutral-800"><span class="font-semibold">Total Diterima</span><span class="font-mono text-xl font-bold">{{ fmtRp(kuitansi.total) }}</span></div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Invoice yang Diterima</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. Invoice</th><th class="px-4 py-2.5">Keterangan</th><th class="px-4 py-2.5 text-right">Nominal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in kuitansi.lines" :key="l.id">
                        <td class="px-4 py-2.5"><Link :href="route('bs.ar.show', l.ar_invoice_id)" class="font-mono text-xs text-primary-600 hover:underline">{{ l.invoice?.invoice_number }}</Link></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ l.invoice?.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-3 text-xs text-neutral-400">
            <span v-if="kuitansi.journal_id" class="inline-flex items-center gap-1"><BookText class="h-3.5 w-3.5" /> Jurnal #{{ kuitansi.journal_id }} (Dr Kas / Cr Piutang)</span>
            <span v-if="kuitansi.void_journal_id" class="inline-flex items-center gap-1 text-danger-500"><BookText class="h-3.5 w-3.5" /> Void #{{ kuitansi.void_journal_id }}</span>
        </div>

        <div v-if="kuitansi.status === 'active'">
            <Button variant="secondary" @click="voidIt"><template #icon-left><Ban class="h-4 w-4" /></template>Void Kuitansi</Button>
        </div>
    </div>
</template>
