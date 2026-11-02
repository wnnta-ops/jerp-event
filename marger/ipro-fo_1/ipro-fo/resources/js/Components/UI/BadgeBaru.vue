<script setup lang="ts">
// Penanda "BARU" untuk modul/fitur yang baru ditambahkan — dipakai di sidebar & judul bagian.
// mode 'titik' = bulatan kecil saja (sidebar diciutkan / induk yang memuat menu baru).
withDefaults(defineProps<{ mode?: 'label' | 'titik' }>(), { mode: 'label' });
</script>

<template>
    <span v-if="mode === 'titik'" class="relative inline-flex h-2 w-2" title="Ada menu baru">
        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
    </span>
    <span v-else class="inline-flex items-center gap-1 rounded-full bg-emerald-500 px-1.5 py-px text-[10px] font-bold uppercase leading-4 tracking-wide text-white shadow-sm" title="Modul baru">
        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>Baru
    </span>
</template>
