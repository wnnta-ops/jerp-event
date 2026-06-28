<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    projects: Array<{ id: number; name: string }>;
    clusters: Array<{ id: number; project_id: number; name: string }>;
    unit_types: Array<{ id: number; project_id: number; name: string }>;
}>();

const form = useForm({
    project_id: '' as string | number,
    cluster_id: '' as string | number,
    unit_type_id: '' as string | number,
    unit_code: '',
    address: '',
    base_price: 850_000_000,
    current_price: 0,
});

const clustersFiltered  = computed(() => form.project_id ? props.clusters.filter(c => c.project_id === Number(form.project_id)) : []);
const typesFiltered     = computed(() => form.project_id ? props.unit_types.filter(t => t.project_id === Number(form.project_id)) : []);

function submit() {
    form.transform(d => ({ ...d, current_price: d.current_price || d.base_price }))
        .post(route('units.store'));
}
</script>

<template>
    <div class="space-y-4 max-w-2xl">
        <Link :href="route('units.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">Unit Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div>
                <label class="label-base">Project <span class="text-danger-500">*</span></label>
                <select v-model="form.project_id" class="input-base" required>
                    <option value="">Pilih...</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label-base">Cluster</label>
                    <select v-model="form.cluster_id" class="input-base" :disabled="!form.project_id">
                        <option value="">-</option>
                        <option v-for="c in clustersFiltered" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="label-base">Tipe Unit</label>
                    <select v-model="form.unit_type_id" class="input-base" :disabled="!form.project_id">
                        <option value="">-</option>
                        <option v-for="t in typesFiltered" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>
            </div>
            <Input v-model="form.unit_code" label="Kode Unit" placeholder="A-01" required :error="form.errors.unit_code" />
            <Input v-model="form.address" label="Alamat" />
            <Input v-model.number="form.base_price" label="Harga Dasar (Rp)" type="number" required :error="form.errors.base_price" />
            <Input v-model.number="form.current_price" label="Harga Saat Ini (Rp)" type="number" hint="Kosongkan untuk pakai harga dasar" />

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('units.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan Unit</Button>
            </div>
        </form>
    </div>
</template>
