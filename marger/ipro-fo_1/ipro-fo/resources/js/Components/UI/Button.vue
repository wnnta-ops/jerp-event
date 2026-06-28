<script setup lang="ts">
import { computed } from 'vue';
import { Loader2 } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    variant?: 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'ghost' | 'outline';
    size?: 'sm' | 'md' | 'lg';
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    disabled?: boolean;
    block?: boolean;
}>(), {
    variant: 'primary',
    size: 'md',
    type: 'button',
    loading: false,
    disabled: false,
    block: false,
});

const variantClasses = computed(() => ({
    primary:   'bg-primary-600 text-white hover:bg-primary-700 focus:ring-primary-500',
    secondary: 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-100 dark:hover:bg-neutral-700',
    success:   'bg-success-500 text-white hover:bg-success-600 focus:ring-success-500',
    warning:   'bg-warning-500 text-white hover:bg-warning-600 focus:ring-warning-500',
    danger:    'bg-danger-500 text-white hover:bg-danger-600 focus:ring-danger-500',
    ghost:     'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-200 dark:hover:bg-neutral-800',
    outline:   'border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-600 dark:hover:bg-neutral-800',
}[props.variant]));

const sizeClasses = computed(() => ({
    sm: 'h-8  px-3 text-sm',
    md: 'h-10 px-4 text-sm',
    lg: 'h-12 px-6 text-base',
}[props.size]));
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :class="[
            'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition',
            'focus:outline-none focus:ring-2 focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            variantClasses, sizeClasses,
            block && 'w-full',
        ]"
    >
        <Loader2 v-if="loading" class="h-4 w-4 animate-spin" />
        <slot name="icon-left" />
        <slot />
        <slot name="icon-right" />
    </button>
</template>
