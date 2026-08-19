<script setup lang="ts">
// Baris total di kaki grafik — sengaja dibuat menyerupai baris "Jumlah" pada tabel
// akuntansi, bentuk yang paling dikenal pembacanya.
//
// Angkanya ditulis PENUH (Rp 3.220.470.100), bukan disingkat seperti label di dalam
// grafik: di sini tersedia selebar kartu, dan justru di baris inilah angka pastinya
// dicari. Grafiknya untuk membandingkan, baris ini untuk membaca angka.
//
// Satu aturan yang tidak boleh dilanggar pemanggil: JANGAN menjumlahkan deret yang
// bersarang atau bertahap. Dua corong KPR menghitung berkas yang sama berulang di tiap
// tahap (tahap 2 adalah bagian dari tahap 1), dan BAST → AJB → Booked Revenue adalah tiga
// tahap atas unit yang sama. Untuk deret semacam itu yang sah hanyalah angka tahap
// pertama atau angka per tahap — bukan penjumlahannya.
import { computed } from 'vue';
import { kosong } from './viz';

export interface Pos {
    /** keterangan singkat di kiri angka */
    label: string;
    /** nilai mentah — dipakai menentukan tanda dan keadaan kosong */
    v: number;
    /** nilai siap cetak (rupiah penuh / jumlah + satuan) */
    teks: string;
    /** keterangan tambahan yang muncul saat kursor menyentuh angkanya */
    catatan?: string;
}

const props = withDefaults(defineProps<{
    items: Pos[];
    /**
     * Angka yang BENAR-BENAR digambar grafiknya, dipakai memutuskan apakah baris total
     * ini tampil. Perlu terpisah dari `items` untuk grafik yang totalnya bisa nol padahal
     * datanya ada — laba rugi delapan bulan bisa berjumlah nol tanpa satu pun bulan nol.
     * Bila tidak diisi, dipakai nilai pada `items` itu sendiri.
     */
    sumber?: number[] | null;
}>(), { sumber: null });

const tampil = computed(() => !kosong(props.sumber ?? props.items.map((i) => i.v)));
</script>

<template>
    <dl v-if="tampil" class="mt-3 flex flex-wrap items-baseline gap-x-6 gap-y-1.5 border-t border-neutral-200 pt-2.5 dark:border-neutral-800">
        <div v-for="(it, i) in items" :key="i" class="flex items-baseline gap-1.5" :title="it.catatan">
            <dt class="text-[11px] text-neutral-500">{{ it.label }}</dt>
            <dd class="text-[13px] font-bold tabular-nums"
                :class="it.v < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-neutral-900 dark:text-neutral-100'">{{ it.teks }}</dd>
        </div>
    </dl>
</template>
