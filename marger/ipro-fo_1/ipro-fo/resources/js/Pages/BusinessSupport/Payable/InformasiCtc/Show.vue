<script setup lang="ts">
// Informasi CTC → rincian satu SPK (Lampiran 1).
//
// Susunannya mengikuti Lampiran: Lokasi / Tahap / Nilai SPK (Tagihan) disebut SEKALI di kiri,
// lalu Riwayat Pembayaran dan Sudah Tertagih berdampingan, dengan Sisa Pembayaran dan Belum
// Tertagih sebagai satu nilai di kolomnya masing-masing.
//
// Aritmetikanya ada di RincianCtc, bukan di sini — layar tidak menghitung apa pun sendiri.
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, AlertTriangle, RefreshCw } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    kontrak: { id: number; muk_id: number; no_spk: string | null; no_kontrak: string | null;
        pelanggan: string | null; status: string | null; ditarik_pada: string | null };
    kolom: Record<string, any>;
    rincian: {
        pembayaran: Array<{ tanggal: string | null; nominal: number; sumber: string; keterangan: string | null }>;
        total_pembayaran: number;
        sisa_pembayaran: number;
        tertagih: Array<{ tanggal: string | null; no_tagihan: string | null; nominal: number }>;
        sudah_tertagih: number;
        belum_tertagih: number;
        selisih_diterima: number | null;
    } | null;
    galat: string | null;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
function fmtTgl(v: string | null) {
    if (!v) return '—';
    const d = new Date(v);
    return isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Tinggi tabel disamakan agar baris pembayaran dan baris tagihan sejajar seperti di Lampiran.
const jumlahBaris = computed(() => Math.max(
    props.rincian?.pembayaran.length ?? 0,
    props.rincian?.tertagih.length ?? 0,
    1,
));
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.muk.ctc.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Informasi CTC
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <ClipboardList class="h-6 w-6 text-amber-600" />
                {{ kontrak.no_spk || kontrak.no_kontrak || '—' }}
            </h1>
            <span v-if="kontrak.ditarik_pada" class="ml-auto inline-flex items-center gap-1.5 text-xs text-neutral-500">
                <RefreshCw class="h-3.5 w-3.5" /> Ditarik {{ kontrak.ditarik_pada }}
            </span>
        </div>
        <p v-if="kontrak.pelanggan" class="text-sm text-neutral-500">{{ kontrak.pelanggan }}</p>

        <!-- Tanpa daftar tagihan, SELURUH angka turunannya tak bisa dihitung. Dikatakan, bukan
             ditampilkan sebagai nol — nol terbaca "belum ada tagihan sama sekali". -->
        <p v-if="galat" class="flex items-start gap-2 rounded-lg border border-danger-200 bg-danger-50 px-4 py-2.5 text-sm text-danger-700 dark:border-danger-900/40 dark:bg-danger-900/20 dark:text-danger-300">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Rincian tagihan tidak bisa diambil dari MUK: {{ galat }}. Sisa pembayaran dan angka tertagih tidak ditampilkan karena keduanya dihitung dari daftar itu.</span>
        </p>

        <template v-if="rincian">
            <!-- Jumlah 'diterima' per tagihan tidak sama dengan kum_diterima di kepala kontrak.
                 Disebut, bukan dipilih diam-diam salah satunya. -->
            <p v-if="rincian.selisih_diterima !== null"
               class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Angka MUK tidak konsisten: jumlah pembayaran per tagihan berbeda
                    <b>{{ fmtRp(Math.abs(rincian.selisih_diterima)) }}</b> dari total diterima di kepala kontrak.
                    Perlu ditanyakan ke MUK sebelum angka di bawah dipakai menagih.
                </span>
            </p>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800">
                        <tr>
                            <th rowspan="2" class="border-r border-neutral-200 px-3 py-2 text-left dark:border-neutral-700">Lokasi</th>
                            <th rowspan="2" class="border-r border-neutral-200 px-3 py-2 text-left dark:border-neutral-700">Tahap</th>
                            <th rowspan="2" class="border-r border-neutral-200 px-3 py-2 text-right dark:border-neutral-700">Nilai SPK (Tagihan)</th>
                            <th colspan="3" class="border-r border-b border-neutral-200 px-3 py-2 text-center dark:border-neutral-700">Riwayat Pembayaran</th>
                            <th colspan="3" class="border-r border-b border-neutral-200 px-3 py-2 text-center dark:border-neutral-700">Sudah Tertagih</th>
                            <th rowspan="2" class="px-3 py-2 text-right">Belum Tertagih</th>
                        </tr>
                        <tr>
                            <!-- "Tanggal Tagihan", BUKAN "Tanggal Pembayaran": MUK tidak mengirim
                                 tanggal pembayaran di mana pun. Menyebutnya tanggal pembayaran
                                 membuat orang memakainya merekonsiliasi bank, dan ia tak akan cocok. -->
                            <th class="px-3 py-2 text-left">Tanggal Tagihan</th>
                            <th class="px-3 py-2 text-right">Nominal Pembayaran</th>
                            <th class="border-r border-neutral-200 px-3 py-2 text-right dark:border-neutral-700">Sisa Pembayaran</th>
                            <th class="px-3 py-2 text-left">Tanggal</th>
                            <th class="px-3 py-2 text-left">No. Tagihan</th>
                            <th class="border-r border-neutral-200 px-3 py-2 text-right dark:border-neutral-700">Nominal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="i in jumlahBaris" :key="i">
                            <template v-if="i === 1">
                                <td :rowspan="jumlahBaris" class="border-r border-neutral-200 px-3 py-2.5 align-top dark:border-neutral-700">
                                    <span v-if="kolom.lokasi">{{ kolom.lokasi }}</span>
                                    <span v-else class="text-xs italic text-neutral-400">belum dipetakan</span>
                                </td>
                                <td :rowspan="jumlahBaris" class="border-r border-neutral-200 px-3 py-2.5 align-top dark:border-neutral-700">{{ kolom.tahap || '—' }}</td>
                                <td :rowspan="jumlahBaris" class="border-r border-neutral-200 px-3 py-2.5 text-right align-top font-mono font-semibold dark:border-neutral-700">{{ fmtRp(kolom.spk_tagihan) }}</td>
                            </template>

                            <td class="px-3 py-2.5">{{ fmtTgl(rincian.pembayaran[i - 1]?.tanggal ?? null) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono">
                                <template v-if="rincian.pembayaran[i - 1]">
                                    {{ fmtRp(rincian.pembayaran[i - 1].nominal) }}
                                    <div class="text-xs font-sans text-neutral-400">{{ rincian.pembayaran[i - 1].keterangan }}</div>
                                </template>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td v-if="i === 1" :rowspan="jumlahBaris"
                                class="border-r border-neutral-200 px-3 py-2.5 text-right align-top font-mono font-semibold dark:border-neutral-700">
                                {{ fmtRp(rincian.sisa_pembayaran) }}
                            </td>

                            <td class="px-3 py-2.5">{{ fmtTgl(rincian.tertagih[i - 1]?.tanggal ?? null) }}</td>
                            <td class="px-3 py-2.5 font-mono text-xs">{{ rincian.tertagih[i - 1]?.no_tagihan ?? '—' }}</td>
                            <td class="border-r border-neutral-200 px-3 py-2.5 text-right font-mono dark:border-neutral-700">
                                <span v-if="rincian.tertagih[i - 1]">{{ fmtRp(rincian.tertagih[i - 1].nominal) }}</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <td v-if="i === 1" :rowspan="jumlahBaris"
                                class="px-3 py-2.5 text-right align-top font-mono font-semibold"
                                :class="rincian.belum_tertagih < 0 ? 'text-danger-600' : ''">
                                {{ fmtRp(rincian.belum_tertagih) }}
                                <!-- Negatif berarti COP melebihi sisa kontrak. TIDAK dijepit ke
                                     nol: dijepit, ia terbaca "sudah beres". -->
                                <div v-if="rincian.belum_tertagih < 0" class="text-xs font-sans font-normal text-danger-600">
                                    COP melebihi sisa kontrak — tanyakan ke MUK
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 bg-neutral-50 text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900/50">
                        <tr>
                            <td colspan="4" class="px-3 py-2.5">Total</td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(rincian.total_pembayaran) }}</td>
                            <td class="border-r border-neutral-200 px-3 py-2.5 text-right font-mono dark:border-neutral-700">{{ fmtRp(rincian.sisa_pembayaran) }}</td>
                            <td colspan="2" class="px-3 py-2.5"></td>
                            <td class="border-r border-neutral-200 px-3 py-2.5 text-right font-mono dark:border-neutral-700">{{ fmtRp(rincian.sudah_tertagih) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(rincian.belum_tertagih) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <EmptyState v-if="!rincian.pembayaran.length && !rincian.tertagih.length"
                            title="Belum ada tagihan dari MUK"
                            description="SPK ini belum punya satu pun COP Owner di MUK, jadi belum ada pembayaran maupun tagihan yang bisa ditampilkan." />
            </div>

            <!-- Dikatakan terus terang: pembayaran lewat BO belum bisa ikut dihitung. Tanpa
                 kalimat ini, Sisa Pembayaran akan dibaca sebagai angka final padahal ia hanya
                 memperhitungkan pembayaran yang tercatat di MUK. -->
            <p class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-2.5 text-xs text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900/40">
                Riwayat pembayaran di atas berasal dari <b>MUK</b> — pembayaran yang tercatat pada tiap COP Owner.
                Pembayaran yang diposting AP lalu disetujui di Approval Pengeluaran Kas &amp; Bank <b>belum ikut</b>:
                invoice AP masih menunjuk register SPK internal dan belum ada jalur yang menautkannya ke kontrak MUK.
            </p>
        </template>
    </div>
</template>
