<script setup lang="ts">
// Layout kosong untuk halaman cetak (tanpa sidebar/topbar) — dipakai override `layout`
// pada halaman dokumen agar hasil cetak bersih. Dipilih ketimbang AppLayout supaya
// chrome aplikasi tidak ikut tercetak.
</script>
<template>
    <slot />
</template>
