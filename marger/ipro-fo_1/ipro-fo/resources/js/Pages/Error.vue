<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{ status: number }>();

const info = computed(() => {
    switch (props.status) {
        case 503:
            return { emoji: '🔧', title: 'Sedang Pemeliharaan', desc: 'Sistem sedang dalam pemeliharaan. Silakan coba beberapa saat lagi.' };
        case 500:
            return { emoji: '💥', title: 'Terjadi Kesalahan Server', desc: 'Maaf, terjadi kesalahan internal. Tim teknis sudah diberi tahu.' };
        case 419:
            return { emoji: '⏰', title: 'Sesi Berakhir', desc: 'Sesi Anda telah berakhir karena tidak aktif. Silakan login ulang.', reasons: ['Akses Anda sudah kadaluwarsa.', 'Token keamanan tidak valid.'] };
        case 404:
            return { emoji: '🔍', title: 'Halaman Tidak Ditemukan', desc: 'Data atau halaman yang Anda cari tidak dapat ditampilkan.', reasons: ['Data sudah dihapus oleh user / proses lain.', 'URL salah ketik atau sudah berubah.', 'Data belum menjalani prosedur bisnis tertentu (PPJB, ST, dst).'] };
        case 403:
            return { emoji: '🔒', title: 'Akses Ditolak', desc: 'Anda tidak memiliki izin untuk mengakses halaman ini.', reasons: ['Data bersifat rahasia & Anda tidak punya akses cukup.', 'Role Anda tidak diizinkan untuk aksi ini.'] };
        default:
            return { emoji: '⚠️', title: `Error ${props.status}`, desc: 'Terjadi kesalahan yang tidak terduga.' };
    }
});

const isAuthError = computed(() => props.status === 419);
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary-700 to-primary-900 p-4">
        <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-2xl max-w-lg w-full p-8 text-center">
            <div class="text-6xl mb-4">{{ info.emoji }}</div>
            <h1 class="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{{ info.title }}</h1>
            <p class="text-neutral-500 mt-2">{{ info.desc }}</p>

            <div v-if="info.reasons" class="mt-4 text-left bg-neutral-50 dark:bg-neutral-800 rounded-lg p-4">
                <p class="text-xs font-semibold text-neutral-600 dark:text-neutral-300 mb-2">Kemungkinan Terjadi Karena:</p>
                <ul class="text-xs text-neutral-500 space-y-1 list-disc list-inside">
                    <li v-for="(r, i) in info.reasons" :key="i">{{ r }}</li>
                </ul>
            </div>

            <div class="mt-6 flex gap-3 justify-center">
                <a v-if="isAuthError" href="/login" class="px-5 py-2.5 rounded-lg bg-primary-600 text-white font-medium hover:bg-primary-700 transition">
                    Login Ulang
                </a>
                <Link v-else href="/dashboard" class="px-5 py-2.5 rounded-lg bg-primary-600 text-white font-medium hover:bg-primary-700 transition">
                    ← Kembali ke Dashboard
                </Link>
            </div>

            <p class="text-xs text-neutral-400 mt-6">Java Core System v2.0 · Error {{ status }}</p>
        </div>
    </div>
</template>
