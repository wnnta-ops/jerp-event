<script setup lang="ts">
/** Pesan merah kecil di bawah kolom wajib — pasangan tandai()/CINCIN_MERAH di useWajib.ts. */
defineProps<{ pesan?: string | null | false }>();
</script>
<template>
    <p v-if="pesan" class="mt-1 text-[11px] font-medium text-danger-500">{{ pesan }}</p>
</template>
