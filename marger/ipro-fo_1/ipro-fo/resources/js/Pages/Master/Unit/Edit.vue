<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    unit: { data: any };
    clusters: Array<{ id: number; name: string }>;
    unit_types: Array<{ id: number; name: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const u = props.unit.data;
const form = useForm({
    cluster_id:    u.cluster?.id ?? '',
    unit_type_id:  u.unit_type?.id ?? '',
    address:       u.address ?? '',
    base_price:    u.base_price,
    current_price: u.current_price,
    status:        u.status,
});

function submit() { form.put(route('units.update', u.id)); }
</script>

<template>
    <div class="space-y-4 max-w-2xl">
        <h1 class="text-2xl font-bold">Edit Unit · {{ u.unit_code }}</h1>
        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="label-base">Cluster</label>
                    <select v-model="form.cluster_id" class="input-base">
                        <option value="">-</option>
                        <option v-for="c in clusters" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="label-base">Tipe</label>
                    <select v-model="form.unit_type_id" class="input-base">
                        <option value="">-</option>
                        <option v-for="t in unit_types" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>
            </div>
            <Input v-model="form.address" label="Alamat" />
            <Input v-model.number="form.base_price" label="Harga Dasar (Rp)" type="number" />
            <Input v-model.number="form.current_price" label="Harga Saat Ini (Rp)" type="number" />
            <div>
                <label class="label-base">Status</label>
                <select v-model="form.status" class="input-base">
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('units.show', u.id)"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan</Button>
            </div>
        </form>
    </div>
</template>
