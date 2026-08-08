<script setup lang="ts">
/**
 * Label kolom yang bisa diklik untuk mengurutkan. Klik 1 = naik, 2 = turun,
 * 3 = kembali ke urutan asli. Panah hanya pekat saat kolom itu sedang dipakai.
 */
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next';

defineProps<{
    label: string;
    /** '' | 'asc' | 'desc' — keadaan urut kolom ini. */
    state: string;
    align?: 'left' | 'right' | 'center';
}>();
const emit = defineEmits<{ (e: 'sort'): void }>();
</script>

<template>
    <button type="button" @click="emit('sort')"
        :title="state === 'asc' ? 'Urut naik — klik untuk turun' : state === 'desc' ? 'Urut turun — klik untuk urutan asli' : 'Klik untuk mengurutkan'"
        class="group inline-flex w-full items-center gap-1 rounded px-0.5 py-0.5 text-xs font-semibold uppercase transition-colors duration-150"
        :class="[
            align === 'right' ? 'justify-end' : align === 'center' ? 'justify-center' : 'justify-start',
            state ? 'text-primary-600 dark:text-primary-400' : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200',
        ]">
        <span class="truncate">{{ label }}</span>
        <ArrowUp v-if="state === 'asc'" class="h-3 w-3 shrink-0" />
        <ArrowDown v-else-if="state === 'desc'" class="h-3 w-3 shrink-0" />
        <ChevronsUpDown v-else class="h-3 w-3 shrink-0 text-neutral-300 opacity-0 transition-opacity duration-150 group-hover:opacity-100 dark:text-neutral-600" />
    </button>
</template>
