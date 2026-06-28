<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileInput, ShoppingCart, HardHat } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ pos: Array<any>; progress: Array<any> }>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function postPo(id: number) { if (confirm('Terbitkan invoice hutang dari PO ini?')) router.post(route('bs.ap.posting.po', id), {}, { preserveScroll: true }); }
function postSpk(id: number) { if (confirm('Terbitkan invoice hutang dari progress SPK ini?')) router.post(route('bs.ap.posting.spk', id), {}, { preserveScroll: true }); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileInput class="h-6 w-6 text-danger-600" /> Posting Invoice</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari Purchase Order (penerimaan barang) atau progress SPK kontraktor. Hutang sudah terbukukan saat penerimaan/progress; invoice menjadi dokumen tagihan yang dibayar via Kuitansi.</p>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800"><ShoppingCart class="h-5 w-5 text-primary-600" /> Dari Purchase Order (penerimaan)</div>
            <table v-if="pos.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. PO</th><th class="px-4 py-2.5">Supplier</th><th class="px-4 py-2.5 text-right">Nilai Diterima</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in pos" :key="p.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ p.po_number }}</td>
                        <td class="px-4 py-2.5">{{ p.supplier }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.received_value) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postPo(p.id)">Generate Invoice</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada PO siap diposting" description="PO dengan barang diterima & belum diinvoice akan tampil di sini." />
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800"><HardHat class="h-5 w-5 text-amber-600" /> Dari Progress SPK Kontraktor</div>
            <table v-if="progress.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. SPK</th><th class="px-4 py-2.5">Kontraktor</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Net Bayar</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in progress" :key="p.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ p.spk_number }}</td>
                        <td class="px-4 py-2.5">{{ p.supplier }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ p.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.net_payable) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postSpk(p.id)">Generate Invoice</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada progress SPK siap diposting" description="Progress SPK belum dibayar & belum diinvoice akan tampil di sini." />
        </div>
    </div>
</template>
