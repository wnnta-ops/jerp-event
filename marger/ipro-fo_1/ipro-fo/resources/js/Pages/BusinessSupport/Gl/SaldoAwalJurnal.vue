<script setup lang="ts">
import { ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Download, CheckCircle2, AlertTriangle, Info, Upload, X } from 'lucide-vue-next';

const props = defineProps<{
    year: number;
    lines: Array<any>;
    totals: { debit: number; credit: number };
    priorClosed: boolean;
}>();

const year = ref(props.year);
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i + 1);
function changeYear() { router.get(route('bs.closing.saldoawal'), { year: year.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return Number(v ?? 0) === 0 ? '—' : 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
const balanced = Math.abs(props.totals.debit - props.totals.credit) < 0.01;

/* IMPORT SALDO AWAL. Halaman ini semula hanya bisa dibaca & diunduh — tidak ada satu pun cara
   memasukkan saldo pembuka dari sistem lama, padahal justru itu keperluan yang disebut
   keterangan di atas ("inisialisasi buku terisolasi / migrasi sistem baru"). */
const bukaImport = ref(false);
const formImport = useForm<{ year: number; file: File | null }>({ year: props.year, file: null });

function pilihBerkas(e: Event) {
    formImport.file = (e.target as HTMLInputElement).files?.[0] ?? null;
}
function kirimImport() {
    formImport.year = year.value;
    formImport.post(route('bs.closing.saldoawal.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { bukaImport.value = false; formImport.reset('file'); },
    });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.closing.neraca', { year })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Neraca Awal</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> Jurnal Saldo Awal <span class="text-base font-normal text-neutral-400">Tahun {{ year }}</span></h1>
            <div class="flex gap-2">
                <select v-model.number="year" @change="changeYear" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <a :href="route('bs.closing.saldoawal.csv', { year })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> Unduh CSV Jurnal</a>
                <button type="button" @click="bukaImport = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-medium text-white hover:bg-primary-700"><Upload class="h-4 w-4" /> Import Excel</button>
            </div>
        </div>

        <!-- Modal Import Saldo Awal -->
        <Teleport to="body">
            <div v-if="bukaImport" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="bukaImport = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Upload class="h-5 w-5 text-primary-600" /> Import Saldo Awal {{ year }}</h2>
                        <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="bukaImport = false"><X class="h-4 w-4" /></button>
                    </div>

                    <p class="mb-3 text-sm text-neutral-600 dark:text-neutral-300">
                        Kolom: <b>KODE_AKUN</b> · NAMA_AKUN · <b>DEBIT</b> · <b>KREDIT</b> · KETERANGAN.
                        Hasil <b>Unduh CSV Jurnal</b> bisa disunting lalu diunggah kembali.
                    </p>

                    <!-- Dua akibat yang tidak terbaca dari tombolnya sendiri, dan keduanya sulit
                         dibatalkan: tanggal jurnalnya, dan penolakan import kedua. -->
                    <ul class="mb-3 space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                        <li>Jurnalnya bertanggal <b>31 Desember {{ year - 1 }}</b> agar terbaca sebagai saldo pembuka {{ year }}, bukan transaksi 1 Januari.</li>
                        <li>Total <b>Debit harus sama dengan Kredit</b>. Satu baris bermasalah membatalkan seluruh berkas — saldo awal yang timpang membuat neraca salah sejak hari pertama.</li>
                        <li>Satu tahun hanya bisa diimpor <b>sekali</b>; mengulanginya akan melipatgandakan saldo pembukanya.</li>
                        <li>Berkas <b>.xlsx</b> atau <b>.csv</b>. Format .xls lama tidak didukung.</li>
                    </ul>

                    <a :href="route('bs.closing.saldoawal.template')" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline"><Download class="h-4 w-4" /> Unduh Template (.xlsx)</a>

                    <input type="file" accept=".xlsx,.csv" class="mb-1 block w-full rounded-lg border border-neutral-300 p-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" @change="pilihBerkas" />
                    <p v-if="formImport.errors.file" class="mb-2 text-xs text-danger-600">{{ formImport.errors.file }}</p>

                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700" @click="bukaImport = false">Batal</button>
                        <button type="button" :disabled="! formImport.file || formImport.processing"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            @click="kirimImport">{{ formImport.processing ? 'Mengimpor…' : 'Import' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <div class="card flex items-start gap-3 p-4 ring-1 ring-primary-100 dark:ring-primary-900/40">
            <Info class="mt-0.5 h-5 w-5 shrink-0 text-primary-600" />
            <div class="text-sm text-neutral-600 dark:text-neutral-300">
                Jurnal ini adalah <b>bentuk jurnal dari Neraca Awal {{ year }}</b> (saldo akun permanen per 1 Januari {{ year }}). Disediakan sebagai <b>pratinjau &amp; unduhan CSV</b> untuk inisialisasi buku terisolasi / migrasi sistem baru.
                <span class="text-amber-700 dark:text-amber-400">Sengaja <b>tidak di-posting otomatis</b> ke GL berjalan agar saldo akun yang sudah kumulatif tidak terhitung ganda (double-count).</span>
            </div>
        </div>

        <div v-if="!priorClosed" class="card flex items-center gap-3 p-4 ring-1 ring-amber-200 dark:ring-amber-900">
            <AlertTriangle class="h-5 w-5 text-amber-600" /><div class="text-sm">Tahun {{ year - 1 }} belum ditutup — L/R berjalan disertakan sebagai baris "Laba Ditahan (belum ditutup)" agar jurnal seimbang.</div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama Akun</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(l, i) in lines" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono text-xs text-neutral-400">{{ l.code }}</td>
                        <td class="px-4 py-2">{{ l.name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.debit) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(l.credit) }}</td>
                    </tr>
                </tbody>
                <tfoot><tr class="border-t-2 border-primary-400 bg-neutral-50 font-bold dark:bg-neutral-800"><td colspan="2" class="px-4 py-2.5">TOTAL</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.debit) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.credit) }}</td></tr></tfoot>
            </table>
        </div>

        <div class="card flex items-center gap-2 p-4" :class="balanced ? 'ring-1 ring-success-200 dark:ring-success-900' : 'ring-1 ring-danger-200 dark:ring-danger-900'">
            <CheckCircle2 v-if="balanced" class="h-5 w-5 text-success-600" /><AlertTriangle v-else class="h-5 w-5 text-danger-600" />
            <span class="text-sm font-semibold">{{ balanced ? 'Jurnal seimbang (Debit = Kredit)' : 'Jurnal tidak seimbang — periksa GL' }}</span>
        </div>
    </div>
</template>
