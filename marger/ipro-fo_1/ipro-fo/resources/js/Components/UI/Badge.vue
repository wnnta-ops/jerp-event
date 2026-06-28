<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    variant?: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
    size?: 'sm' | 'md';
    dot?: boolean;
}>(), { variant: 'neutral', size: 'sm', dot: false });

const variantClass = computed(() => ({
    neutral: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200',
    primary: 'bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200',
    success: 'bg-success-500/10 text-success-600 dark:text-success-500',
    warning: 'bg-warning-500/10 text-warning-600 dark:text-warning-500',
    danger:  'bg-danger-500/10 text-danger-600 dark:text-danger-500',
    info:    'bg-info-500/10 text-info-600 dark:text-info-500',
}[props.variant]));

const dotClass = computed(() => ({
    neutral: 'bg-neutral-400',
    primary: 'bg-primary-500',
    success: 'bg-success-500',
    warning: 'bg-warning-500',
    danger:  'bg-danger-500',
    info:    'bg-info-500',
}[props.variant]));

const sizeClass = computed(() => props.size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs');
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full font-medium', variantClass, sizeClass]">
        <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dotClass" />
        <slot />
    </span>
</template>
