<script setup lang="ts">
import { Inbox } from 'lucide-vue-next';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div class="text-center py-12">
        <div class="mx-auto h-12 w-12 text-neutral-400">
            <slot name="icon"><Inbox class="h-12 w-12" /></slot>
        </div>
        <h3 class="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ title ?? 'Belum ada data' }}</h3>
        <p v-if="description" class="mt-1 text-sm text-neutral-500">{{ description }}</p>
        <div v-if="$slots.action" class="mt-6"><slot name="action" /></div>
    </div>
</template>
