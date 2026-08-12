<script setup lang="ts">
// Sub modul "Posting Invoice dari Purchase Order" — HANYA PO.
//
// Berdiri sendiri, sebentuk dengan layar Posting Invoice SPK. Tidak berkotak centang:
// tiap PO diinvoice sendiri-sendiri, tidak pernah digabung, dan suppliernya sudah pasti
// dari dokumen PO-nya sehingga tidak perlu dipilih ulang.
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ShoppingCart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ pos: Array<any> }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function postPo(id: number) {
    if (confirm('Terbitkan invoice hutang dari PO ini?')) {
        router.post(route('bs.ap.posting.po', id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShoppingCart class="h-6 w-6 text-primary-600" /> Posting Invoice dari Purchase Order</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari penerimaan barang Purchase Order. Hutang sudah terbukukan saat barang diterima; invoice menjadi dokumen tagihan yang dibayar via Kuitansi. Nilai yang ditagih hanya sebesar barang yang <b>sudah diterima</b>, bukan nilai PO penuh.</p>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">
                <ShoppingCart class="h-5 w-5 text-primary-600" /> Dari Purchase Order
            </div>
            <table v-if="pos.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. PO</th><th class="px-4 py-2.5">Supplier</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Nilai Diterima</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in pos" :key="'po-' + p.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">
                            {{ p.po_number }}
                            <span v-if="p.po_date" class="ml-1 font-sans text-[11px] font-normal text-neutral-400">{{ p.po_date }}</span>
                        </td>
                        <td class="px-4 py-2.5">{{ p.supplier ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ p.uraian }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.received_value) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postPo(p.id)">Generate Invoice</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada Purchase Order siap diposting" description="PO berstatus disetujui/tutup yang barangnya sudah diterima dan belum diinvoice akan tampil di sini." />
        </div>
    </div>
</template>
