<script setup lang="ts">
// Realisasi KPR per Cluster — dua tabel dari Exsum Collection, diisi lewat import Excel.
//
// Kenapa diimpor dan bukan dihitung: sebagian kolomnya kalimat yang ditulis orang
// ("Sisa Retensi 4,2 M · MUI 5", "Retensi TO + BAST"), dan kolom seperti HOLD / OPEN STOCK
// tidak punya padanan transaksi di aplikasi ini. Alasannya ada di
// KprRealisasiClusterController.
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { LayoutGrid, Upload, Download, X } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Status {
    label: string; total_unit: number; realisasi: number; kpr: number; cash: number;
    proses: number; open_stock: number; hold: number; escrow: string | null;
}
interface Os { label: string; retensi: string | null; proses: string | null; open_stok: string | null; lain_lain: string | null }

defineProps<{
    status: Status[];
    statusTotal: { total_unit: number; realisasi: number; kpr: number; cash: number; proses: number; open_stock: number; hold: number };
    statusCatatan: string | null;
    os: Os[];
    osCatatan: string | null;
}>();

/* Nol ditampilkan sebagai tanda pisah, persis seperti Exsum. Deretan "0" pada tabel yang
   sebagian besar selnya kosong menenggelamkan sel yang benar-benar berisi. */
function n(v: number) { return Number(v ?? 0) === 0 ? '—' : Number(v).toLocaleString('id-ID'); }
function teks(v: string | null) { return v && v.trim() !== '' ? v : '—'; }

// ── Import ──
const buka = ref<'status' | 'os' | null>(null);
const form = useForm<{ jenis: string; file: File | null }>({ jenis: 'status', file: null });

function pilihBerkas(e: Event) { form.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function kirim() {
    form.jenis = buka.value ?? 'status';
    form.post(route('kpr.database.realisasi-cluster.import'), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { buka.value = null; form.reset('file'); },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><LayoutGrid class="h-6 w-6 text-primary-600" /> Realisasi KPR per Cluster</h1>
            <p class="mt-1 text-sm text-neutral-500">Status unit &amp; outstanding per cluster dan tahap, mengikuti Exsum Collection.</p>
        </div>

        <!-- ══ Tabel 1: Proses KPR per Cluster ══ -->
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 class="text-sm font-bold">Proses KPR per Cluster <span class="font-normal text-neutral-400">(Status Unit)</span></h2>
                <button type="button" @click="buka = 'status'" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-primary-300 px-2.5 text-xs font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-800 dark:text-primary-300"><Upload class="h-3.5 w-3.5" /> Import</button>
            </div>

            <div v-if="status.length" class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-4 py-3">Cluster / Tahap</th>
                            <th class="px-4 py-3 text-right">Total Unit</th>
                            <th class="px-4 py-3 text-right">Realisasi</th>
                            <th class="px-4 py-3 text-right">KPR</th>
                            <th class="px-4 py-3 text-right">Cash</th>
                            <th class="px-4 py-3 text-right">Proses</th>
                            <th class="px-4 py-3 text-right">Open Stock</th>
                            <th class="px-4 py-3 text-right">Hold</th>
                            <th class="px-4 py-3">Escrow / Sisa Retensi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in status" :key="r.label" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-2.5 font-medium text-primary-700 dark:text-primary-300">{{ r.label }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.total_unit) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.realisasi) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.kpr) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.cash) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.proses) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.open_stock) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ n(r.hold) }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ teks(r.escrow) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 font-bold dark:bg-neutral-800">
                        <tr>
                            <td class="px-4 py-3">TOTAL</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.total_unit) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.realisasi) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.kpr) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.cash) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.proses) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.open_stock) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ n(statusTotal.hold) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <p v-if="statusCatatan" class="border-t border-neutral-200 px-4 py-2.5 text-[11px] italic leading-relaxed text-neutral-500 dark:border-neutral-800">{{ statusCatatan }}</p>
            </div>
            <EmptyState v-else title="Belum ada data Proses KPR per Cluster"
                description="Unggah tabelnya dari Exsum Collection lewat tombol Import di atas." />
        </section>

        <!-- ══ Tabel 2: Outstanding per Cluster & Tahap ══ -->
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-4">
                <h2 class="text-sm font-bold">Outstanding (O/S) KPR per Cluster &amp; Tahap</h2>
                <button type="button" @click="buka = 'os'" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-primary-300 px-2.5 text-xs font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-800 dark:text-primary-300"><Upload class="h-3.5 w-3.5" /> Import</button>
            </div>

            <div v-if="os.length" class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-4 py-3">Cluster / Tahap</th>
                            <th class="px-4 py-3">Retensi</th>
                            <th class="px-4 py-3">Proses</th>
                            <th class="px-4 py-3">Open Stok</th>
                            <th class="px-4 py-3">Lain-lain</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in os" :key="r.label" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="px-4 py-2.5 font-medium text-primary-700 dark:text-primary-300">{{ r.label }}</td>
                            <td class="px-4 py-2.5">{{ teks(r.retensi) }}</td>
                            <td class="px-4 py-2.5">{{ teks(r.proses) }}</td>
                            <td class="px-4 py-2.5">{{ teks(r.open_stok) }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ teks(r.lain_lain) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="osCatatan" class="border-t border-neutral-200 px-4 py-2.5 text-[11px] italic leading-relaxed text-neutral-500 dark:border-neutral-800">{{ osCatatan }}</p>
            </div>
            <EmptyState v-else title="Belum ada data Outstanding KPR per Cluster"
                description="Unggah tabelnya dari Exsum Collection lewat tombol Import di atas." />
        </section>

        <p class="px-1 text-[11px] leading-relaxed text-neutral-400">
            Kedua tabel ini <b>diisi lewat import</b>, bukan dihitung dari transaksi: sebagian kolomnya
            berisi kalimat yang ditulis saat menyusun Exsum (Escrow / Sisa Retensi, Lain-lain), dan kolom
            seperti Hold &amp; Open Stock tidak punya padanan transaksi di aplikasi ini. Setiap import
            <b>mengganti seluruh isi tabelnya</b> — Exsum adalah potret bulanan, sehingga baris yang sudah
            tidak ada lagi di Exsum terbaru ikut hilang, bukan tertinggal sebagai sisa bulan lalu.
            Untuk angka yang dihitung sendiri dari data akad, lihat <b>Realisasi KPR per Bank</b>.
        </p>

        <!-- Modal Import -->
        <Teleport to="body">
            <div v-if="buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="buka = null">
                <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Upload class="h-5 w-5 text-primary-600" />
                            Import {{ buka === 'status' ? 'Proses KPR per Cluster' : 'Outstanding per Cluster' }}</h2>
                        <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="buka = null"><X class="h-4 w-4" /></button>
                    </div>

                    <p class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">
                        Kolom: <b v-if="buka === 'status'">URUTAN · CLUSTER · TOTAL_UNIT · REALISASI · KPR · CASH · PROSES · OPEN_STOCK · HOLD · ESCROW · CATATAN</b>
                        <b v-else>URUTAN · CLUSTER · RETENSI · PROSES · OPEN_STOK · LAIN_LAIN · CATATAN</b>
                    </p>

                    <ul class="mb-3 space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <li>Import <b>mengganti seluruh isi tabel ini</b>, bukan menambahkan — baris yang tidak ada di berkas akan hilang.</li>
                        <li><b>URUTAN</b> menjaga barisnya tampil seperti di Exsum; tanpa itu urutannya mengikuti abjad dan alur tahapannya kacau.</li>
                        <li><b>CATATAN</b> cukup diisi di satu baris — ia menjadi catatan kaki seluruh tabel.</li>
                        <li v-if="buka === 'os'">Kolom nilainya <b>teks bebas</b>: "Rp 1,2 M (2 unit RUHA)" atau "Clear" disimpan apa adanya.</li>
                        <li>Berkas <b>.xlsx</b> atau <b>.csv</b>; format .xls lama tidak didukung.</li>
                    </ul>

                    <a :href="route('kpr.database.realisasi-cluster.template', { jenis: buka })" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline"><Download class="h-4 w-4" /> Unduh Template (.xlsx)</a>

                    <input type="file" accept=".xlsx,.csv" class="mb-1 block w-full rounded-lg border border-neutral-300 p-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" @change="pilihBerkas" />
                    <p v-if="form.errors.file" class="mb-2 text-xs text-danger-600">{{ form.errors.file }}</p>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700" @click="buka = null">Batal</button>
                        <button type="button" :disabled="! form.file || form.processing"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            @click="kirim">{{ form.processing ? 'Mengimpor…' : 'Import' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
