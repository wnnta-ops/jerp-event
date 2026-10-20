<script setup lang="ts">
/**
 * Navigasi halaman untuk hasil paginate() Laravel (25 baris per halaman).
 *
 * Dipasang di bawah daftar yang servernya sudah memotong data per halaman — tanpa ini
 * daftarnya "mentok" di halaman pertama dan sisa datanya tidak pernah terlihat.
 * Filter & pencarian ikut terbawa karena controller memakai ->withQueryString().
 */
import { Link } from '@inertiajs/vue3';

const props = withDefaults(defineProps<{
    data: { links?: Array<{ url: string | null; label: string; active: boolean }>; from?: number | null; to?: number | null; total?: number } | null | undefined;
    satuan?: string;
}>(), { satuan: 'data' });

function label(l: string) {
    return l.replace('&laquo; Previous', '‹ Sebelumnya').replace('Next &raquo;', 'Berikutnya ›')
        .replace('pagination.previous', '‹ Sebelumnya').replace('pagination.next', 'Berikutnya ›');
}
</script>
<template>
    <div v-if="props.data && (props.data.total ?? 0) > 0" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
        <div class="text-neutral-500">
            Menampilkan {{ (props.data.from ?? 0).toLocaleString('id-ID') }}–{{ (props.data.to ?? 0).toLocaleString('id-ID') }}
            dari {{ (props.data.total ?? 0).toLocaleString('id-ID') }} {{ satuan }}
        </div>
        <div v-if="(props.data.links?.length ?? 0) > 3" class="flex flex-wrap items-center gap-1">
            <template v-for="(l, i) in props.data.links" :key="i">
                <Link v-if="l.url" :href="l.url" v-html="label(l.label)"
                      :class="['min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm', l.active
                          ? 'border-amber-500 bg-amber-50 font-semibold text-amber-700 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-300'
                          : 'border-neutral-300 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300']" />
                <span v-else v-html="label(l.label)" class="min-w-9 rounded-lg border border-neutral-200 px-3 py-1.5 text-center text-sm text-neutral-400 dark:border-neutral-800" />
            </template>
        </div>
    </div>
</template>
