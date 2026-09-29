<!-- COGS Baru — kepala SATU sub-modul: namanya sendiri, keterangannya sendiri, periodenya sendiri -->
<script setup lang="ts">
/**
 * Keempat layar COGS Baru berdiri SENDIRI-SENDIRI, sama seperti di sidebar: Perhitungan COGS,
 * Set Up Kategori COGS, Actual Costing COGS, dan Rincian COGS masing-masing satu sub-modul.
 * Kepala ini memberi tiap layar identitasnya — ikon dan nama yang sama persis dengan butir
 * menunya — bukan satu kepala bersama dengan deretan tab yang membuat keempatnya terlihat seperti
 * satu halaman ber-tab.
 *
 * Yang TIDAK ikut terpisah adalah hubungan dan hitungannya: keempat layar tetap membaca satu
 * pemanggilan kalkulator atas periode yang sama, dan angka di satu layar tetap angka yang sama di
 * layar lain. Yang dipisah hanya tampilannya.
 *
 * ── Mengapa identitas periode tetap ada di tiap layar ──
 * Seluruh layar COGS Baru berbutir periode (lokasi + tahap). Layar yang berdiri sendiri tanpa
 * menyebut periodenya membuat orang menyunting lokasi yang salah tanpa satu pun galat — angkanya
 * tetap masuk, tetap terlihat wajar, dan baru ketahuan jauh di belakang.
 *
 * ── Pemindah komponen ──
 * Hanya pada Set Up Kategori & Actual Costing, karena hanya kedua sub-modul itu yang butir
 * menunya memang dipecah per komponen. Tanah/Housing/Infra/License BUKAN sub-modul tersendiri —
 * keempatnya empat tampilan dari sub-modul yang sama, dan menyeberanginya adalah pekerjaan
 * sehari-hari di layar itu.
 *
 * Blok <style> di sini juga tempat variabel warna komponen didefinisikan untuk kedua mode —
 * satu-satunya tempat, sehingga grafik di seluruh modul tidak mungkin berbeda warna.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MapPin } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import { KOMPONEN } from './warna';
import { SUBMODUL, judulLayar, type Layar } from './submodul';

const props = defineProps<{
    periode: { id: number; lokasi_kode: string | null; lokasi_nama: string | null; tahap: string; cakupan: string };
    /** Sub-modul yang sedang terbuka. */
    layar: Layar;
    /** Komponen yang sedang terbuka, hanya untuk Set Up Kategori & Actual Costing. */
    komponen?: string | null;
}>();

const submodul = computed(() => SUBMODUL[props.layar]);
const judul = computed(() => judulLayar(props.layar, props.komponen));

/**
 * Warna kepala mengikuti KOMPONEN-nya, bukan sub-modulnya. Warna di modul ini menandai identitas
 * Tanah/Housing/Infra/License; memberi tiap sub-modul rona karangannya sendiri akan membuat warna
 * chrome bersaing dengan warna data, dan "Housing" tidak lagi berarti satu warna tertentu.
 */
const aksen = computed(() => {
    const k = KOMPONEN.find((x) => x.kunci === props.komponen);

    return submodul.value.berkomponen && k ? 'var(--c2-' + k.kunci + ')' : null;
});
</script>

<template>
    <div class="c2-root space-y-4">
        <Head :title="judul" />

        <Link :href="route('bs.cogs2.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> COGS Baru
        </Link>

        <!-- Identitas sub-modul: ikon & nama yang sama persis dengan butir menunya -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-white shadow-sm"
                      :style="{ backgroundColor: aksen || '#0ea5e9' }">
                    <component :is="submodul.ikon" class="h-5 w-5" />
                </span>
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold leading-tight">{{ judul }}</h1>
                    <p class="mt-1 text-sm text-neutral-500">{{ submodul.keterangan }}</p>
                </div>
            </div>
            <slot name="aksi" />
        </div>

        <!--
            Periode yang sedang dikerjakan. Ditaruh sebagai baris konteks tersendiri, bukan sebagai
            judul halaman: yang dibuka orang adalah sub-modulnya, sedangkan periodenya keterangan
            atas sub-modul itu.
        -->
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-neutral-800 dark:bg-neutral-900/60">
            <MapPin class="h-4 w-4 shrink-0 text-neutral-400" />
            <span class="text-neutral-500">Periode</span>
            <Badge variant="info">{{ periode.lokasi_kode || '—' }}</Badge>
            <span class="truncate font-medium">{{ periode.lokasi_nama || ('Lokasi #' + periode.id) }}</span>
            <Badge v-if="periode.tahap" variant="neutral">Tahap {{ periode.tahap }}</Badge>
            <span v-else class="text-neutral-500">seluruh tahap</span>
            <Link :href="route('bs.cogs2.index')"
                  class="ml-auto rounded-lg px-2 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-950/40">
                Ganti periode
            </Link>
        </div>

        <!-- Pindah antar komponen — hanya pada sub-modul yang butir menunya memang berbutir komponen -->
        <nav v-if="submodul.berkomponen" class="flex flex-wrap gap-1.5" aria-label="Komponen COGS">
            <Link v-for="k in KOMPONEN" :key="k.kunci"
                  :href="route(layar === 'kategori' ? 'bs.cogs2.kategori' : 'bs.cogs2.actual', [periode.id, k.slug])"
                  class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                  :class="k.kunci === komponen
                      ? 'border-transparent text-white shadow-sm'
                      : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                  :style="k.kunci === komponen ? { backgroundColor: 'var(--c2-' + k.kunci + ')' } : undefined"
                  :aria-current="k.kunci === komponen ? 'page' : undefined">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: k.kunci === komponen ? 'rgba(255,255,255,.85)' : 'var(--c2-' + k.kunci + ')' }"></span>
                {{ k.label }}
            </Link>
        </nav>

        <slot />
    </div>
</template>

<style>
/*
 * Warna komponen COGS Baru. Kedua mode DIPILIH, bukan dibalik otomatis: langkah gelapnya adalah
 * langkah tersendiri dari rona yang sama, dan keduanya sudah lulus pemeriksa palet terhadap latar
 * masing-masing. Didefinisikan di :root (bukan di .c2-root) supaya elemen yang di-teleport ke
 * <body> — modal, tooltip — ikut mewarisi warna yang sama.
 */
:root {
    --c2-tanah: #2a78d6;
    --c2-housing: #eb6834;
    --c2-infra: #1baf7a;
    --c2-license: #eda100;
    --c2-lahan-1: #0369a1;
    --c2-lahan-2: #0ea5e9;
    --c2-lahan-3: #bae6fd;
}
@media (prefers-color-scheme: dark) {
    :root:not([data-theme='light']) {
        --c2-tanah: #3987e5;
        --c2-housing: #d95926;
        --c2-infra: #199e70;
        --c2-license: #c98500;
        --c2-lahan-1: #38bdf8;
        --c2-lahan-2: #0284c7;
        --c2-lahan-3: #1e3a5f;
    }
}
:root[data-theme='dark'] {
    --c2-tanah: #3987e5;
    --c2-housing: #d95926;
    --c2-infra: #199e70;
    --c2-license: #c98500;
    --c2-lahan-1: #38bdf8;
    --c2-lahan-2: #0284c7;
    --c2-lahan-3: #1e3a5f;
}
</style>
