<script setup lang="ts">
/**
 * Input nominal Rupiah dengan pemisah ribuan otomatis (mis. 1.000.000).
 * v-model = nilai numerik; tampilan diformat id-ID. tabular-nums agar angka 0 jelas.
 */
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{ modelValue: number | string | null; placeholder?: string; min?: number; disabled?: boolean }>(), {
    placeholder: '0', min: 0, disabled: false,
});
const emit = defineEmits<{ (e: 'update:modelValue', v: number): void }>();

const parse = (s: any): number => Number(String(s ?? '').replace(/[^\d]/g, '')) || 0;
const fmt = (n: any): string => { const x = Number(n) || 0; return x ? x.toLocaleString('id-ID') : ''; };

const display = ref(fmt(props.modelValue));
watch(() => props.modelValue, (v) => { if (parse(display.value) !== (Number(v) || 0)) display.value = fmt(v); });

function onInput(e: Event) {
    const num = parse((e.target as HTMLInputElement).value);
    display.value = num ? num.toLocaleString('id-ID') : '';
    emit('update:modelValue', num);
}
</script>
<template>
    <input
        :value="display" @input="onInput" inputmode="numeric" :placeholder="placeholder" :disabled="disabled"
        class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900 disabled:cursor-not-allowed disabled:bg-neutral-100 dark:disabled:bg-neutral-800"
    />
</template>
