<!-- COGS Baru — kepala bersama: tautan kembali, judul periode, dan pindah antar layar -->
<script setup lang="ts">
/**
 * Dipakai keempat layar COGS Baru supaya tata letak, urutan tab, dan warna komponennya SAMA di
 * mana pun. Menyalin kepala ini ke tiap layar berarti empat tempat yang harus diubah bersamaan,
 * dan yang terlupa akan terlihat seperti layar dari modul lain.
 *
 * Blok <style> di sini juga tempat variabel warna komponen didefinisikan untuk kedua mode —
 * satu-satunya tempat, sehingga grafik di seluruh modul tidak mungkin berbeda warna.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Calculator } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import { KOMPONEN } from './warna';

const props = defineProps<{
    periode: { id: number; lokasi_kode: string | null; lokasi_nama: string | null; tahap: string; cakupan: string };
    /** Layar yang sedang terbuka. */
    layar: 'perhitungan' | 'kategori' | 'actual' | 'rincian';
    /** Komponen yang sedang terbuka, hanya untuk layar kategori & actual. */
    komponen?: string | null;
}>();

const tab = computed(() => [
    { kunci: 'perhitungan', label: 'Perhitungan COGS', href: route('bs.cogs2.perhitungan', props.periode.id) },
    { kunci: 'kategori', label: 'Set Up Kategori COGS', href: route('bs.cogs2.kategori', [props.periode.id, props.komponen && props.layar === 'kategori' ? props.komponen : 'tanah']) },
    { kunci: 'actual', label: 'Actual Costing COGS', href: route('bs.cogs2.actual', [props.periode.id, props.komponen && props.layar === 'actual' ? props.komponen : 'tanah']) },
    { kunci: 'rincian', label: 'Rincian COGS', href: route('bs.cogs2.rincian', props.periode.id) },
]);
</script>

<template>
    <div class="c2-root space-y-4">
        <Link :href="route('bs.cogs2.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> COGS Baru
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="flex items-center gap-2 text-2xl font-bold">
                    <Calculator class="h-6 w-6 text-sky-600" />
                    {{ periode.lokasi_nama || ('Lokasi #' + periode.id) }}
                </h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-neutral-500">
                    <Badge variant="info">{{ periode.lokasi_kode || '—' }}</Badge>
                    <Badge v-if="periode.tahap" variant="neutral">Tahap {{ periode.tahap }}</Badge>
                    <span v-else>seluruh tahap</span>
                </p>
            </div>
            <slot name="aksi" />
        </div>

        <!-- Pindah antar layar -->
        <nav class="flex flex-wrap gap-1 rounded-xl border border-neutral-200 bg-white p-1 dark:border-neutral-800 dark:bg-neutral-900" aria-label="Layar COGS Baru">
            <Link v-for="t in tab" :key="t.kunci" :href="t.href"
                  class="rounded-lg px-4 py-2 text-sm font-medium transition"
                  :class="t.kunci === layar
                      ? 'bg-sky-600 text-white shadow-sm'
                      : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                  :aria-current="t.kunci === layar ? 'page' : undefined">
                {{ t.label }}
            </Link>
        </nav>

        <!-- Pindah antar komponen (hanya pada layar yang memang berbutir komponen) -->
        <nav v-if="layar === 'kategori' || layar === 'actual'"
             class="flex flex-wrap gap-1.5" aria-label="Komponen COGS">
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
