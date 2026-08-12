<script setup lang="ts">
// Sub modul "Posting Invoice dari Purchase Order" — HANYA PO.
//
// Berdiri sendiri, sebentuk dengan layar Posting Invoice SPK. Tidak berkotak centang:
// tiap PO diinvoice sendiri-sendiri, tidak pernah digabung, dan suppliernya sudah pasti
// dari dokumen PO-nya sehingga tidak perlu dipilih ulang.
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ShoppingCart, CalendarClock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ pos: Array<any> }>();

// Jatuh tempo ditetapkan SEBELUM invoice terbit — bukan sesudahnya. Kosongkan untuk
// memakai cadangan lama (jatuh tempo dokumen PO, atau 30 hari bila PO tidak punya).
// Nilai ini yang nanti tercetak di Bukti Permohonan Pembayaran dan menentukan umur
// tagihan pada Aging Hutang.
const jatuhTempo = ref('');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: string) {
    return v ? new Date(v + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '';
}
function postPo(id: number) {
    const kapan = jatuhTempo.value
        ? `Jatuh tempo ${fmtTgl(jatuhTempo.value)}.`
        : 'Jatuh tempo mengikuti dokumen PO (atau 30 hari bila PO tidak punya).';
    if (confirm(`Terbitkan invoice hutang dari PO ini?\n\n${kapan}`)) {
        router.post(route('bs.ap.posting.po', id), { due_date: jatuhTempo.value || null }, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ShoppingCart class="h-6 w-6 text-primary-600" /> Posting Invoice dari Purchase Order</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari penerimaan barang Purchase Order. Hutang sudah terbukukan saat barang diterima; invoice menjadi dokumen tagihan yang dibayar via Kuitansi. Nilai yang ditagih hanya sebesar barang yang <b>sudah diterima</b>, bukan nilai PO penuh.</p>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><ShoppingCart class="h-5 w-5 text-primary-600" /> Dari Purchase Order</div>
                <!-- Jatuh tempo ditetapkan sebelum invoice terbit; dipakai untuk SEMUA baris
                     yang diposting dari layar ini, dan tercetak di Bukti Permohonan Pembayaran. -->
                <label class="ml-auto flex items-center gap-2 text-sm">
                    <CalendarClock class="h-4 w-4 text-neutral-400" />
                    <span class="text-neutral-500">Tgl. Jatuh Tempo</span>
                    <input v-model="jatuhTempo" type="date"
                        class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                </label>
            </div>
            <p class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-[11.5px] text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/40">
                Dikosongkan berarti mengikuti jatuh tempo dokumen PO — atau 30 hari bila PO tidak mencantumkannya.
            </p>
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
