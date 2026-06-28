<script setup lang="ts">
import { Toaster, toast } from 'vue-sonner';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

const page = usePage();

watch(() => page.props.flash, (flash: any) => {
    if (! flash) return;
    if (flash.success) toast.success(flash.success);
    if (flash.error)   toast.error(flash.error);
    if (flash.warning) toast.warning(flash.warning);
    if (flash.info)    toast.info(flash.info);
}, { deep: true });
</script>

<template>
    <Toaster position="top-right" rich-colors close-button />
</template>
