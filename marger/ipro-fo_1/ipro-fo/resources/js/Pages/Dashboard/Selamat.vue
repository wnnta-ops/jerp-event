<script setup lang="ts">
/**
 * Halaman awal BO untuk pengguna TANPA izin Dashboard Back Office (dashboard.{grup}.view).
 * Tidak ada grafik yang dihitung maupun dikirim — hanya sapaan, hari & tanggal berjalan, dan
 * kata motivasi hari ini (Settings → Konfigurasi Sistem → Halaman Selamat Datang).
 */
import { Head } from '@inertiajs/vue3';
import { Hand } from 'lucide-vue-next';

const props = defineProps<{
    nama: string | null;
    /** "Rabu, 14-10-2026" — dirakit server dalam bahasa Indonesia. */
    hariIni: string;
    /** Satu kalimat, bergantian per hari; null bila daftarnya dikosongkan. */
    motivasi: string | null;
}>();
</script>
<template>
    <Head title="Selamat Datang" />
    <div class="mx-auto mt-10 max-w-2xl">
        <div class="card p-8">
            <p class="text-sm font-medium text-primary-600">{{ hariIni }}</p>
            <h1 class="mt-2 flex items-center gap-3 text-3xl font-bold">
                Selamat Datang, {{ props.nama ?? 'Pengguna' }} <Hand class="h-7 w-7 text-amber-500" />
            </h1>
            <p v-if="motivasi" class="mt-5 border-l-4 border-primary-400 pl-4 text-lg italic text-neutral-600 dark:text-neutral-300">
                “{{ motivasi }}”
            </p>
        </div>
    </div>
</template>
