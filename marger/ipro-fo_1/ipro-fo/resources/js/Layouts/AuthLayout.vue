<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ title?: string }>();
</script>

<template>
    <Head :title="title ?? 'Masuk — Java Core System'" />
    <div class="min-h-screen w-full">
        <slot />
    </div>
</template>
