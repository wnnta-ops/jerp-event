<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ groups: Record<string, any[]> }>();

const values = reactive<Record<number, string>>({});

const form = useForm({ updates: [] as Array<{ id: number; value: string }> });

function save() {
    form.updates = Object.entries(values).map(([id, value]) => ({ id: Number(id), value }));
    form.post(route('settings.parameters.update'));
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Parameter Sistem</h1>
        <p class="text-sm text-neutral-500">Konfigurasi parameter bisnis (PPN, denda, hold default, dll.)</p>

        <div v-for="(items, group) in groups" :key="group" class="card p-6">
            <h2 class="font-semibold capitalize mb-3">{{ group }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div v-for="p in items" :key="p.id" class="space-y-1">
                    <label class="text-sm font-medium">{{ p.label || p.key }}</label>
                    <input
                        :value="values[p.id] ?? p.value"
                        @input="values[p.id] = ($event.target as HTMLInputElement).value"
                        class="input-base"
                    />
                    <p class="text-xs text-neutral-500">Key: <code>{{ p.key }}</code> · Tipe: {{ p.data_type }}</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <Button @click="save" :loading="form.processing">Simpan Semua</Button>
        </div>
    </div>
</template>
