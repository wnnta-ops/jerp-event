<script setup lang="ts">
// Informasi CTC — nilai tiap SPK berikut berapa yang sudah & belum tertagih (Lampiran 2).
//
// BACA-SAJA. Angkanya dari salinan kontrak MUK (nilai SPK & yang sudah tertagih) dan dari
// pemetaan Setup Project (Lokasi & Tahap). Semuanya disusun BarisSpkMuk — definisi yang SAMA
// dengan sub modul Kontrak SPK (MUK), supaya satu kontrak tidak pernah menunjukkan dua angka
// berbeda di dua layar.
//
// "Ditarik" ditampilkan di kanan atas karena sumbernya salinan, bukan panggilan langsung:
// orang berhak tahu angka yang dilihatnya setua apa sebelum memakainya.
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, Search, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: any;
    filters: { cari?: string };
    total: { jumlah: number; spk_dpp: number; spk_tagihan: number; sudah_tertagih: number; belum_tertagih: number };
    ditarik_pada: string | null;
}>();

const cari = ref(props.filters.cari ?? '');

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }

let jeda: ReturnType<typeof setTimeout>;
watch(cari, () => {
    clearTimeout(jeda);
    jeda = setTimeout(() => {
        router.get(route('bs.muk.ctc.index'), { cari: cari.value || undefined },
            { preserveState: true, replace: true });
    }, 300);
});
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Contractor Management
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <ClipboardList class="h-6 w-6 text-amber-600" /> Informasi CTC
            </h1>
            <span v-if="ditarik_pada" class="ml-auto inline-flex items-center gap-1.5 text-xs text-neutral-500">
                <RefreshCw class="h-3.5 w-3.5" /> Ditarik {{ ditarik_pada }}
            </span>
        </div>

        <p class="text-sm text-neutral-500">
            Nilai tiap SPK berikut berapa yang sudah dan belum tertagih. Angkanya <b>baca-saja</b> —
            nilai SPK &amp; yang sudah tertagih dari MUK ERP, Lokasi &amp; Tahap dari pemetaan di Setup Project.
        </p>

        <!-- Dikatakan, bukan disembunyikan: tiga kolom di tabel ini belum punya isi dari MUK,
             dan "Nilai SPK (Tagihan)" karenanya masih sama dengan DPP. Tanpa keterangan ini,
             kolom Belum Tertagih akan dibaca sebagai angka final. -->
        <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>
                <b>PPN dan PPh 4 ayat 2 belum dikirim MUK</b> — tidak ada di endpoint kontrak mereka.
                Selama itu, <b>Nilai SPK (Tagihan)</b> masih sama dengan DPP, dan <b>Belum Tertagih</b>
                ikut bergeser begitu kedua nilai itu dibuka.
            </span>
        </p>

        <div class="card flex flex-wrap items-center gap-3 p-3">
            <div class="relative min-w-[16rem] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    v-model="cari"
                    type="search"
                    placeholder="Cari No. SPK, no. kontrak, proyek, atau pelanggan…"
                    class="h-10 w-full rounded-lg border border-neutral-300 pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                />
            </div>
            <span class="text-sm text-neutral-500">{{ total.jumlah }} SPK</span>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3 w-12">No</th>
                    <th class="px-3 py-3">Nomor SPK</th>
                    <th class="px-3 py-3">Lokasi</th>
                    <th class="px-3 py-3">Tahap</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (DPP)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (PPN)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (PPh 4 ayat 2)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (Tagihan)</th>
                    <th class="px-3 py-3 text-right">Sudah Tertagih</th>
                    <th class="px-3 py-3 text-right">Belum Tertagih</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5 text-neutral-400">{{ (rows.from ?? 1) + i }}</td>
                        <td class="px-3 py-2.5">
                            <!-- Tertaut ke kontraknya di Kontrak SPK (MUK): rincian penagihan
                                 (COP Owner) yang membentuk kolom Sudah Tertagih ada di sana. -->
                            <Link :href="route('bs.muk.kontrak.show', r.id)" class="font-mono font-semibold text-amber-700 hover:underline dark:text-amber-400">
                                {{ r.no_spk || r.no_kontrak || '—' }}
                            </Link>
                            <div v-if="r.pelanggan" class="text-xs text-neutral-400">{{ r.pelanggan }}</div>
                        </td>
                        <td class="px-3 py-2.5">
                            <span v-if="r.lokasi">{{ r.lokasi }}</span>
                            <span v-else class="text-xs italic text-neutral-400">belum dipetakan</span>
                        </td>
                        <td class="px-3 py-2.5">{{ r.tahap || '—' }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.spk_dpp) }}</td>
                        <!-- "belum dari MUK", BUKAN Rp 0 — nol adalah angka, dan orang akan
                             memakainya untuk membayar. -->
                        <td class="px-3 py-2.5 text-right text-xs italic text-neutral-400">belum dari MUK</td>
                        <td class="px-3 py-2.5 text-right text-xs italic text-neutral-400">belum dari MUK</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.spk_tagihan) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.sudah_tertagih) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">
                            {{ fmtRp(r.belum_tertagih) }}
                            <!-- Angka MUK yang melampaui nilai kontraknya sendiri ditandai,
                                 bukan disembunyikan: sisanya dijepit ke nol, dan tanpa tanda
                                 ini nol itu terbaca seperti "sudah lunas". -->
                            <span v-if="r.janggal_tertagih" :title="'Tertagih melampaui nilai SPK — perlu ditanyakan ke MUK'"
                                  class="ml-1 inline-flex align-middle text-amber-600">
                                <AlertTriangle class="h-3.5 w-3.5" />
                            </span>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.data.length" class="border-t-2 border-neutral-200 bg-neutral-50 text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900/50">
                    <tr>
                        <!-- Total SELURUH baris tersaring, bukan halaman yang sedang terbuka —
                             total satu halaman tidak menjawab pertanyaan siapa pun, tetapi
                             tampak seperti total keseluruhan. -->
                        <td colspan="4" class="px-3 py-2.5">Total {{ total.jumlah }} SPK</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.spk_dpp) }}</td>
                        <td class="px-3 py-2.5"></td>
                        <td class="px-3 py-2.5"></td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.spk_tagihan) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.sudah_tertagih) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(total.belum_tertagih) }}</td>
                    </tr>
                </tfoot>
            </table>

            <EmptyState v-if="!rows.data.length" title="Belum ada SPK"
                        description="Belum ada kontrak MUK yang tersalin. Tarik dulu dengan perintah penarikan kontrak MUK." />

            <div v-if="rows.links && rows.last_page > 1" class="flex flex-wrap gap-1 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''"
                      class="rounded px-3 py-1 text-sm"
                      :class="[l.active ? 'bg-amber-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                               !l.url && 'pointer-events-none opacity-40']"
                      v-html="l.label" />
            </div>
        </div>
    </div>
</template>
