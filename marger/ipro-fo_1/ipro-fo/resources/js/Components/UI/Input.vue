<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue?: string | number | null;
    label?: string;
    type?: string;
    placeholder?: string;
    hint?: string;
    error?: string;
    required?: boolean;
    disabled?: boolean;
    readonly?: boolean;
    id?: string;
    name?: string;
    autocomplete?: string;
    inputmode?: 'text' | 'numeric' | 'decimal' | 'email' | 'tel' | 'url' | 'search';
    minlength?: number;
    maxlength?: number;
    autofocus?: boolean;
}>(), { type: 'text' });

defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const fieldId = computed(() => props.id ?? `field-${Math.random().toString(36).slice(2, 8)}`);
</script>

<template>
    <div class="space-y-1">
        <label v-if="label" :for="fieldId" class="block text-sm font-medium text-neutral-700 dark:text-neutral-200">
            {{ label }} <span v-if="required" class="text-danger-500">*</span>
        </label>
        <div class="relative">
            <slot name="prefix" />
            <input
                :id="fieldId" :type="type" :value="modelValue"
                :name="name"
                :placeholder="placeholder" :disabled="disabled" :readonly="readonly"
                :required="required"
                :autocomplete="autocomplete"
                :inputmode="inputmode"
                :minlength="minlength"
                :maxlength="maxlength"
                :autofocus="autofocus"
                :aria-invalid="!! error || undefined"
                :aria-describedby="error ? `${fieldId}-error` : (hint ? `${fieldId}-hint` : undefined)"
                :class="[
                    'block w-full rounded-lg border px-3 h-10 text-sm transition',
                    'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500',
                    'disabled:bg-neutral-100 dark:disabled:bg-neutral-800',
                    error ? 'border-danger-500' : 'border-neutral-300 dark:border-neutral-700',
                    'bg-white dark:bg-neutral-900 text-neutral-900 dark:text-neutral-100',
                ]"
                @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
            <slot name="suffix" />
        </div>
        <p v-if="hint && !error" :id="`${fieldId}-hint`" class="text-xs text-neutral-500">{{ hint }}</p>
        <p v-if="error" :id="`${fieldId}-error`" class="text-xs text-danger-500" role="alert">{{ error }}</p>
    </div>
</template>
