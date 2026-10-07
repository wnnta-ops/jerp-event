<script setup lang="ts">
// Sub modul "Posting Invoice SPK" — HANYA progress SPK kontraktor.
//
// Sebelumnya sidebar ini membuka layar yang persis sama dengan Posting Invoice dari FO
// (rutenya sama, params `source` tidak pernah dibaca controller). Sekarang berdiri sendiri.
// Tidak berkotak centang: tiap progress diinvoice sendiri, kontraktornya sudah pasti dari SPK-nya.
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, HardHat, CalendarClock, FileStack } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ progress: Array<any>; mukTagihan: Array<any> }>();

// Jatuh tempo ditetapkan SEBELUM invoice terbit. Kosongkan untuk memakai cadangan lama
// (30 hari sejak invoice). Nilai ini yang tercetak di Bukti Permohonan Pembayaran dan
// menentukan umur tagihan pada Aging Hutang.
const jatuhTempo = ref('');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: string) {
    return v ? new Date(v + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '';
}
function postMuk(id: number, no: string) {
    // Jatuh tempo TIDAK dikirim dari sini: tagihan MUK sudah membawa tanggalnya sendiri dari
    // dokumen yang dipegang kontraktor, dan sudah ditegaskan saat Posting ke AP. Mengirim
    // isian layar ini akan menimpanya dengan tanggal yang tidak ada di dokumen mana pun.
    if (confirm(`Terbitkan invoice hutang dari tagihan MUK ${no}?`)) {
        router.post(route('bs.ap.posting.muk-tagihan', id), {}, { preserveScroll: true });
    }
}
function postSpk(id: number) {
    const kapan = jatuhTempo.value ? `Jatuh tempo ${fmtTgl(jatuhTempo.value)}.` : 'Jatuh tempo 30 hari sejak hari ini.';
    if (confirm(`Terbitkan invoice hutang dari progress SPK ini?

${kapan}`)) {
        router.post(route('bs.ap.posting.spk', id), { due_date: jatuhTempo.value || null }, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Posting Invoice SPK</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari progress pekerjaan SPK kontraktor. Hutang sudah terbukukan saat progress disetujui; invoice menjadi dokumen tagihan yang dibayar via Kuitansi. Nilai yang ditagih adalah <b>Net Bayar</b> — sesudah potongan retensi dan pajak.</p>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><HardHat class="h-5 w-5 text-amber-600" /> Dari Progress SPK Kontraktor</div>
                <label class="ml-auto flex items-center gap-2 text-sm">
                    <CalendarClock class="h-4 w-4 text-neutral-400" />
                    <span class="text-neutral-500">Tgl. Jatuh Tempo</span>
                    <input v-model="jatuhTempo" type="date"
                        class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                </label>
            </div>
            <p class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-[11.5px] text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/40">
                Dikosongkan berarti jatuh tempo 30 hari sejak invoice terbit.
            </p>
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

        <!-- ── DARI TAGIHAN MUK ───────────────────────────────────────────────────
             Antrean kedua, dipisah dari progress SPK internal: asal dokumennya berbeda, dan
             nilainya sudah membawa PPN & PPh dari COP-nya sehingga tidak dihitung ulang. -->
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><FileStack class="h-5 w-5 text-amber-600" /> Dari Tagihan MUK</div>
            </div>
            <p class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-[11.5px] text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/40">
                Tagihan yang sudah ditekan “Posting ke AP” di Tagihan Progress Project. Nilai &amp; pajaknya
                disalin dari COP Owner, dan jatuh temponya mengikuti dokumen MUK — isian tanggal di atas tidak berlaku di sini.
            </p>
            <table v-if="mukTagihan.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">No. Tagihan</th><th class="px-4 py-2.5">No. Kontrak</th><th class="px-4 py-2.5">Kontraktor</th>
                    <th class="px-4 py-2.5">Jatuh Tempo</th>
                    <th class="px-4 py-2.5 text-right">DPP</th><th class="px-4 py-2.5 text-right">PPN</th><th class="px-4 py-2.5 text-right">PPh 4(2)</th>
                    <th class="px-4 py-2.5 text-right">Nilai Tagihan</th><th class="px-4 py-2.5 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in mukTagihan" :key="t.id">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ t.no_tagihan }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ t.no_kontrak }}</td>
                        <td class="px-4 py-2.5">{{ t.supplier }}</td>
                        <td class="px-4 py-2.5">{{ t.due_date ? fmtTgl(t.due_date) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(t.dpp) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(t.ppn) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(t.pph) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(t.nilai_tagihan) }}</td>
                        <td class="px-4 py-2.5 text-right"><Button size="sm" @click="postMuk(t.id, t.no_tagihan)">Generate Invoice</Button></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada tagihan MUK di antrean"
                        description="Buka Contractor Management → Tagihan Progress Project, lalu tekan Posting ke AP pada tagihan yang akan dibayar." />
        </div>

    </div>
</template>
