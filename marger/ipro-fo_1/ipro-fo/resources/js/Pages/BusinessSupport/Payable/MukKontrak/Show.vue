<script setup lang="ts">
// Rincian penagihan satu kontrak SPK — COP Owner dari MUK ERP.
//
// Kepala kontraknya dari salinan lokal; daftar tagihannya ditarik saat halaman dibuka.
// Bila MUK sedang tak bisa dihubungi, pesannya ditampilkan apa adanya dan bagian kepala
// tetap terbaca — lebih berguna daripada halaman galat yang menyembunyikan semuanya.
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FileSpreadsheet, AlertTriangle, WifiOff } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    kontrak: Record<string, any>;
    tagihan: Array<any>;
    galat: string | null;
    /** Lampiran 1 — nilai SPK dibagi ke unit menurut luas. Lihat AlokasiSpkUnit. */
    alokasi: Record<string, any>;
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
function fmtTgl(v: string | null) {
    return v ? new Date(v + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}

const warnaStatus: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-700',
    unpaid: 'bg-amber-100 text-amber-700',
    partial: 'bg-sky-100 text-sky-700',
};
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.muk.kontrak.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Kontrak SPK (MUK)
        </Link>

        <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold">
            <FileSpreadsheet class="h-6 w-6 text-amber-600" />
            <span class="font-mono">{{ kontrak.no_kontrak || '—' }}</span>
            <span class="rounded px-2 py-0.5 text-xs font-semibold"
                  :class="kontrak.status === 'closed' ? 'bg-neutral-200 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400' : 'bg-emerald-100 text-emerald-700'">
                {{ kontrak.status || '—' }}
            </span>
        </h1>

        <div v-if="kontrak.janggal" class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-300">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Angka dari MUK tidak konsisten pada kontrak ini — nilai yang ditagih melebihi yang diopname, atau opname melebihi nilai kontrak. Tanyakan ke tim MUK sebelum dipakai menyiapkan pembayaran.</span>
        </div>

        <!-- Kepala kontrak -->
        <div class="card p-4">
            <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Proyek</p><p class="mt-0.5">{{ kontrak.proyek || '—' }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Pelanggan</p><p class="mt-0.5">{{ kontrak.pelanggan || '—' }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">No. SPK</p><p class="mt-0.5 font-mono">{{ kontrak.no_spk || '—' }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Retensi</p><p class="mt-0.5">{{ kontrak.retensi_persen }}%</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Tanggal</p><p class="mt-0.5">{{ fmtTgl(kontrak.tanggal) }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Mulai</p><p class="mt-0.5">{{ fmtTgl(kontrak.mulai) }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Selesai</p><p class="mt-0.5">{{ fmtTgl(kontrak.selesai) }}</p></div>
                <div><p class="text-xs uppercase tracking-wide text-neutral-500">Ditarik</p><p class="mt-0.5">{{ kontrak.ditarik_pada || '—' }}</p></div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-neutral-500">Nilai Kontrak</p><p class="mt-1 text-lg font-bold">{{ fmtRp(kontrak.nilai_kontrak) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-neutral-500">Diopname</p><p class="mt-1 text-lg font-bold">{{ fmtRp(kontrak.diopname) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-neutral-500">Sudah Ditagih</p><p class="mt-1 text-lg font-bold text-amber-600">{{ fmtRp(kontrak.ditagih) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-neutral-500">Belum Ditagih</p><p class="mt-1 text-lg font-bold">{{ fmtRp(kontrak.belum_ditagih) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase tracking-wide text-neutral-500">Retensi Ditahan</p><p class="mt-1 text-lg font-bold">{{ fmtRp(kontrak.retensi) }}</p></div>
        </div>

        <div v-if="galat" class="flex items-start gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-700/60 dark:bg-red-900/20 dark:text-red-300">
            <WifiOff class="mt-0.5 h-4 w-4 shrink-0" />
            <div>
                <p class="font-semibold">Rincian penagihan tidak bisa diambil</p>
                <p class="mt-0.5">{{ galat }}</p>
                <p class="mt-1 text-xs opacity-80">Angka kontrak di atas dari tarikan terakhir, jadi tetap bisa dibaca.</p>
            </div>
        </div>

        <div v-else class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">
                Rincian Penagihan (COP Owner) — {{ tagihan.length }} tagihan
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/50">
                        <tr>
                            <th class="px-4 py-3">No. Tagihan</th>
                            <th class="px-4 py-3">Tanggal</th>
                            <th class="px-4 py-3">Jatuh Tempo</th>
                            <th class="px-4 py-3 text-right">DPP</th>
                            <th class="px-4 py-3 text-right">PPN</th>
                            <th class="px-4 py-3 text-right">PPh</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Diterima</th>
                            <th class="px-4 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="b in tagihan" :key="b.no_tagihan">
                            <tr class="border-t border-neutral-100 dark:border-neutral-800">
                                <td class="px-4 py-2.5 font-mono font-semibold">{{ b.no_tagihan || '—' }}</td>
                                <td class="px-4 py-2.5">{{ fmtTgl(b.tanggal) }}</td>
                                <td class="px-4 py-2.5">{{ fmtTgl(b.jatuh_tempo) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.dpp) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.ppn) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.pph) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(b.total) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.diterima) }}</td>
                                <td class="px-4 py-2.5 text-center">
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold"
                                          :class="warnaStatus[b.status] || 'bg-neutral-200 text-neutral-600'">{{ b.status || '—' }}</span>
                                </td>
                            </tr>
                            <!-- Opname yang mendasari tagihan ini. Ditampilkan supaya angka
                                 tagihan bisa ditelusuri ke dokumen asalnya, bukan diterima begitu saja. -->
                            <tr v-if="b.opname && b.opname.length" class="bg-neutral-50/60 text-xs dark:bg-neutral-900/30">
                                <td colspan="9" class="px-4 py-2">
                                    <span class="text-neutral-500">Opname:</span>
                                    <span v-for="o in b.opname" :key="o.no_opname" class="ml-2 inline-block">
                                        <span class="font-mono">{{ o.no_opname }}</span>
                                        <span class="text-neutral-500"> · {{ fmtTgl(o.tanggal) }} · {{ fmtRp(o.bruto) }}</span>
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <EmptyState v-if="!tagihan.length" title="Belum ada tagihan" desc="Kontrak ini belum punya COP Owner di MUK." />
        </div>

        <!-- LAMPIRAN 1 — alokasi nilai SPK ke tiap unit menurut luas. -->
        <div class="card">
            <div class="border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Alokasi SPK per Unit</h2>
                <p class="mt-0.5 text-xs text-neutral-500">
                    <template v-if="alokasi.baris.length">
                        Dibagi menurut <b>{{ alokasi.dasar === 'luas_tanah' ? 'luas tanah' : 'luas bangunan' }}</b>
                        <span v-if="alokasi.komponen"> (komponen COGS: {{ alokasi.komponen }})</span>.
                        Total {{ alokasi.dasar === 'luas_tanah' ? 'LT' : 'LB' }}
                        {{ Number(alokasi.total_dasar).toLocaleString('id-ID') }} m².
                    </template>
                    <template v-else>Belum ada unit yang bisa dialokasikan.</template>
                </p>
            </div>

            <!-- Yang belum dipasangkan DIKATAKAN, bukan disembunyikan: pembaginya ikut
                 berubah saat pasangan bertambah, jadi angka di bawah masih bisa bergeser. -->
            <p v-if="alokasi.lokasi_belum_dipasangkan"
               class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-300">
                {{ alokasi.lokasi_belum_dipasangkan }} dari {{ alokasi.lokasi_total }} lokasi MUK belum dipasangkan ke unit kita —
                angka di bawah masih akan berubah saat pasangannya dilengkapi.
            </p>

            <div v-if="alokasi.baris.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/50">
                        <tr>
                            <th class="px-4 py-3 w-12">No</th>
                            <th class="px-4 py-3">Unit Rumah</th>
                            <th class="px-4 py-3">Lokasi</th>
                            <th class="px-4 py-3">Tahap</th>
                            <th class="px-4 py-3 text-right">{{ alokasi.dasar === 'luas_tanah' ? 'LT' : 'LB' }} (m²)</th>
                            <th class="px-4 py-3 text-right">Alokasi SPK (DPP)</th>
                            <th class="px-4 py-3 text-right">Alokasi SPK (PPN)</th>
                            <th class="px-4 py-3 text-right">Alokasi SPK (PPh 4 ayat 2)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in alokasi.baris" :key="r.unit_id"
                            class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="px-4 py-2 text-neutral-400">{{ i + 1 }}</td>
                            <td class="px-4 py-2 font-mono">{{ r.unit_rumah }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ r.lokasi || '—' }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ r.tahap || '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono text-neutral-500">{{ Number(r.luas).toLocaleString('id-ID') }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ fmtRp(r.alokasi_dpp) }}</td>
                            <!-- PPN & PPh belum dikirim MUK; "belum dari MUK", bukan Rp 0 —
                                 nol adalah angka, dan orang akan memakainya untuk membayar. -->
                            <td class="px-4 py-2 text-right text-xs italic text-neutral-400">belum dari MUK</td>
                            <td class="px-4 py-2 text-right text-xs italic text-neutral-400">belum dari MUK</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 bg-neutral-50 text-sm font-semibold dark:border-neutral-700 dark:bg-neutral-900/50">
                        <tr>
                            <td colspan="4" class="px-4 py-2.5">Total {{ alokasi.baris.length }} unit</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ Number(alokasi.total_dasar).toLocaleString('id-ID') }}</td>
                            <!-- Dijumlahkan di layar, BUKAN disalin dari nilai SPK: kalau
                                 pembulatannya meleset, selisihnya harus terlihat di sini. -->
                            <td class="px-4 py-2.5 text-right font-mono">
                                {{ fmtRp(alokasi.baris.reduce((t, r) => t + Number(r.alokasi_dpp || 0), 0)) }}
                            </td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-else class="px-4 py-8 text-center text-sm text-neutral-500">
                <p v-if="alokasi.komponen === 'infrastruktur'">
                    Paket infrastruktur tidak dialokasikan per unit — pekerjaannya mencakup satu cluster,
                    bukan satu rumah.
                </p>
                <p v-else-if="!alokasi.lokasi_total">
                    MUK tidak memulangkan daftar lokasi untuk kontrak ini, jadi tidak ada unit yang bisa dibagi.
                </p>
                <p v-else-if="alokasi.total_dasar <= 0">
                    Unitnya sudah terpasang, tetapi luasnya belum terisi di master unit — tidak ada dasar pembagian.
                </p>
                <p v-else>
                    Lokasi MUK pada kontrak ini belum ada yang dipasangkan ke unit kita.
                </p>
            </div>
        </div>

    </div>
</template>
