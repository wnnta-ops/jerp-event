<script setup lang="ts">
/**
 * Indonesian structured address fields (6 sub-fields).
 *
 * Supports optional "Sama dengan KTP" toggle pattern via `cloneable`
 * + `sourceAddress` prop. Saat toggle on → fields di-disable & nilai
 * disinkron dari source.
 */
import { computed, watch } from 'vue';
import Input from '@/Components/UI/Input.vue';

interface AddressShape {
    address: string | null;
    rt_rw: string | null;
    kelurahan: string | null;
    kecamatan: string | null;
    kota: string | null;
    postal_code: string | null;
}

const props = withDefaults(defineProps<{
    modelValue: AddressShape;
    label?: string;
    cloneable?: boolean;
    cloneLabel?: string;
    sourceAddress?: AddressShape | null;
    sameAsSource?: boolean;
    disabled?: boolean;
    errors?: Partial<Record<keyof AddressShape, string>>;
}>(), {
    cloneable: false,
    cloneLabel: 'Sama dengan KTP',
    sourceAddress: null,
    sameAsSource: false,
    disabled: false,
    errors: () => ({}),
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: AddressShape): void;
    (e: 'update:sameAsSource', value: boolean): void;
}>();

const effectiveDisabled = computed(() => props.disabled || (props.cloneable && props.sameAsSource));

function setField<K extends keyof AddressShape>(key: K, value: string) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function toggleSame(value: boolean) {
    emit('update:sameAsSource', value);
}

// Saat sameAsSource jadi true → sync from source.
watch(
    () => [props.sameAsSource, props.sourceAddress],
    () => {
        if (props.sameAsSource && props.sourceAddress) {
            emit('update:modelValue', { ...props.sourceAddress });
        }
    },
    { deep: true },
);
</script>

<template>
    <div class="space-y-3">
        <div v-if="label || cloneable" class="flex items-center justify-between">
            <h3 v-if="label" class="text-sm font-semibold text-neutral-700 dark:text-neutral-200">{{ label }}</h3>
            <label v-if="cloneable" class="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-300">
                <input
                    type="checkbox"
                    :checked="sameAsSource"
                    class="rounded"
                    :disabled="disabled"
                    @change="toggleSame(($event.target as HTMLInputElement).checked)"
                />
                <span>{{ cloneLabel }}</span>
            </label>
        </div>

        <Input
            label="Alamat"
            :model-value="modelValue.address"
            :disabled="effectiveDisabled"
            :error="errors.address"
            @update:model-value="(v) => setField('address', v)"
        />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <Input
                label="RT/RW"
                placeholder="003/005"
                :model-value="modelValue.rt_rw"
                :disabled="effectiveDisabled"
                :error="errors.rt_rw"
                @update:model-value="(v) => setField('rt_rw', v)"
            />
            <Input
                label="Kelurahan"
                :model-value="modelValue.kelurahan"
                :disabled="effectiveDisabled"
                :error="errors.kelurahan"
                @update:model-value="(v) => setField('kelurahan', v)"
            />
            <Input
                label="Kecamatan"
                :model-value="modelValue.kecamatan"
                :disabled="effectiveDisabled"
                :error="errors.kecamatan"
                @update:model-value="(v) => setField('kecamatan', v)"
            />
            <Input
                label="Kota / Kabupaten"
                :model-value="modelValue.kota"
                :disabled="effectiveDisabled"
                :error="errors.kota"
                @update:model-value="(v) => setField('kota', v)"
            />
            <Input
                label="Kode Pos"
                placeholder="12345"
                :model-value="modelValue.postal_code"
                :disabled="effectiveDisabled"
                :error="errors.postal_code"
                @update:model-value="(v) => setField('postal_code', v)"
            />
        </div>
    </div>
</template>
