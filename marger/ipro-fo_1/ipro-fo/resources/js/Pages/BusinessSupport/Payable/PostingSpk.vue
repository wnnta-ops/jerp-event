<script setup lang="ts">
// Sub modul "Posting Invoice SPK" — HANYA progress SPK kontraktor.
//
// Sebelumnya sidebar ini membuka layar yang persis sama dengan Posting Invoice dari FO
// (rutenya sama, params `source` tidak pernah dibaca controller). Sekarang berdiri sendiri.
// Tidak berkotak centang: tiap progress diinvoice sendiri, kontraktornya sudah pasti dari SPK-nya.
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, HardHat } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ progress: Array<any> }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function postSpk(id: number) {
    if (confirm('Terbitkan invoice hutang dari progress SPK ini?')) {
        router.post(route('bs.ap.posting.spk', id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Posting Invoice SPK</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari progress pekerjaan SPK kontraktor. Hutang sudah terbukukan saat progress disetujui; invoice menjadi dokumen tagihan yang dibayar via Kuitansi. Nilai yang ditagih adalah <b>Net Bayar</b> — sesudah potongan retensi dan pajak.</p>

        <div class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">
                <HardHat class="h-5 w-5 text-amber-600" /> Dari Progress SPK Kontraktor
            </div>
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
