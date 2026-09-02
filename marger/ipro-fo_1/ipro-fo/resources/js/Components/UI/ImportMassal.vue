<script setup lang="ts">
/**
 * Import massal — tombol + dialog, dipakai bersama oleh Supplier Center & Employee Center.
 *
 * Dijadikan satu komponen karena alurnya identik: unduh template → pilih berkas → pilih
 * perlakuan atas data yang sudah ada → kirim → baca hasilnya. Yang berbeda hanya rute dan
 * daftar kolomnya, dan itu masuk lewat prop.
 */
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Upload, Download, X, TriangleAlert, CircleCheck } from 'lucide-vue-next';

const props = defineProps<{
    /** Nama route tujuan POST berkas. */
    postRoute: string;
    /** Nama route unduh template .xlsx. */
    templateRoute: string;
    /** Kata benda yang dipakai di teks, mis. "supplier" / "karyawan". */
    entitas: string;
    /** Judul kolom yang dikenali, urut — ditampilkan supaya operator tahu isinya sebelum mengunduh. */
    kolom: string[];
    /** Kolom yang wajib ada isinya. */
    wajib: string[];
}>();

const page = usePage();
const buka = ref(false);
const berkas = ref<File | null>(null);
const mode = ref<'perbarui' | 'lewati'>('perbarui');
const mengirim = ref(false);
const inputRef = ref<HTMLInputElement | null>(null);

type Hasil = { baru: number; ubah: number; lewat: number; galat: string[]; catatan?: string[] };
const hasil = computed<Hasil | null>(() => (page.props as any)?.flash?.import ?? null);

// Dialog dibiarkan TERBUKA setelah kirim bila ada hasilnya — daftar galat per baris adalah
// inti dari fitur ini, dan menutup dialognya akan membuangnya sebelum sempat dibaca.
watch(hasil, (h) => { if (h) { buka.value = true; berkas.value = null; if (inputRef.value) inputRef.value.value = ''; } });

function pilih(e: Event) {
    berkas.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function kirim() {
    if (!berkas.value || mengirim.value) return;
    mengirim.value = true;
    // route(...), BUKAN nama rutenya mentah-mentah: router.post() menerima URL. Mengirim
    // "bs.suppliers.import" apa adanya membuat browser memposting ke path harfiah itu —
    // relatif terhadap halaman yang sedang dibuka — dan jawabannya 404. Tautan unduh
    // template di bawah sudah benar sejak awal, jadi gejalanya khas: templatenya bisa
    // diunduh, tombol Import-nya menghasilkan "Halaman Tidak Ditemukan".
    router.post(route(props.postRoute), { file: berkas.value, mode: mode.value }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { mengirim.value = false; },
    });
}

function tutup() {
    buka.value = false;
    berkas.value = null;
    if (inputRef.value) inputRef.value.value = '';
}
</script>

<template>
    <button type="button" @click="buka = true"
            class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800">
        <Upload class="h-4 w-4" /> Import Massal
    </button>

    <div v-if="buka" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8">
        <div class="card w-full max-w-2xl p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">Import Massal {{ entitas }}</h2>
                    <p class="text-sm text-neutral-500">Unggah .xlsx, .xls, atau .csv. Unduh templatenya dulu agar judul kolomnya cocok.</p>
                </div>
                <button type="button" @click="tutup" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800"><X class="h-5 w-5" /></button>
            </div>

            <a :href="route(templateRoute)"
               class="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-3 py-2 text-sm font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-700/50 dark:bg-primary-900/30 dark:text-primary-300">
                <Download class="h-4 w-4" /> Unduh Template Import {{ entitas }} (.xlsx)
            </a>

            <div class="mt-4 rounded-lg border border-neutral-200 p-3 text-xs dark:border-neutral-700">
                <p class="font-semibold uppercase text-neutral-400">Kolom yang dikenali</p>
                <p class="mt-1 text-neutral-600 dark:text-neutral-300">
                    <template v-for="(k, i) in kolom" :key="k"><span v-if="i"> · </span><span :class="wajib.includes(k) ? 'font-semibold text-neutral-800 dark:text-neutral-100' : ''">{{ k }}</span></template>
                </p>
                <p class="mt-1.5 text-neutral-400">Yang <b>tebal</b> wajib diisi. Urutan kolom bebas — judulnya yang dicocokkan, jadi "No. Telp" tetap terbaca sebagai Telepon.</p>
            </div>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="block text-sm font-medium">Berkas</label>
                    <input ref="inputRef" type="file" accept=".xlsx,.xls,.csv,.txt" @change="pilih"
                           class="mt-1 block w-full rounded-lg border border-neutral-300 bg-white p-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-neutral-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:border-neutral-700 dark:bg-neutral-900 dark:file:bg-neutral-800" />
                </div>
                <div>
                    <label class="block text-sm font-medium">Bila datanya sudah ada</label>
                    <select v-model="mode" class="mt-1 h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="perbarui">Perbarui datanya</option>
                        <option value="lewati">Lewati, jangan diubah</option>
                    </select>
                    <p class="mt-1 text-xs text-neutral-400">Dicocokkan lewat {{ entitas === 'Supplier' ? 'Kode' : 'NIK' }}. Kolom yang tidak ada di berkas tidak akan menimpa data lama.</p>
                </div>
            </div>

            <!-- Hasil import. Galat ditampilkan SELURUHNYA, bukan beberapa saja: baris yang
                 gagal itulah yang perlu dibetulkan, dan memotongnya membuat sisanya tak terlacak. -->
            <div v-if="hasil" class="mt-4 rounded-lg border p-3 text-sm"
                 :class="hasil.lewat ? 'border-warning-300 bg-warning-50 dark:border-warning-700/50 dark:bg-warning-900/20' : 'border-success-300 bg-success-50 dark:border-success-700/50 dark:bg-success-900/20'">
                <p class="flex items-center gap-1.5 font-semibold">
                    <CircleCheck v-if="!hasil.lewat" class="h-4 w-4 text-success-600" />
                    <TriangleAlert v-else class="h-4 w-4 text-warning-600" />
                    {{ hasil.baru }} baru · {{ hasil.ubah }} diperbarui<span v-if="hasil.lewat"> · {{ hasil.lewat }} dilewati</span>
                </p>
                <ul v-if="hasil.catatan?.length" class="mt-2 list-inside list-disc space-y-0.5 text-xs text-neutral-600 dark:text-neutral-300">
                    <li v-for="c in hasil.catatan" :key="c">{{ c }}</li>
                </ul>
                <div v-if="hasil.galat?.length" class="mt-2">
                    <p class="text-xs font-semibold uppercase text-neutral-500">Baris yang dilewati</p>
                    <ul class="mt-1 max-h-56 space-y-0.5 overflow-y-auto text-xs text-neutral-600 dark:text-neutral-300">
                        <li v-for="(g, i) in hasil.galat" :key="i">{{ g }}</li>
                    </ul>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" @click="tutup" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Tutup</button>
                <button type="button" @click="kirim" :disabled="!berkas || mengirim"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                    <Upload class="h-4 w-4" /> {{ mengirim ? 'Mengunggah…' : 'Import' }}
                </button>
            </div>
        </div>
    </div>
</template>
