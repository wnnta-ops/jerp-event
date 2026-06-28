<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    user: any;
    roles: Array<{ id: number; name: string; description: string | null }>;
}>();

const form = useForm({
    name:      props.user.name,
    phone:     props.user.phone ?? '',
    is_active: props.user.is_active,
    roles:     props.user.roles.map((r: any) => r.name),
    password:  '',
});

function submit() { form.put(route('settings.users.update', props.user.id)); }
</script>

<template>
    <div class="max-w-xl mx-auto space-y-4">
        <Link :href="route('settings.users.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">Edit User · {{ user.name }}</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <Input v-model="form.name" label="Nama" required />
            <Input :modelValue="user.email" label="Email" readonly disabled />
            <Input v-model="form.phone" label="No HP" />
            <Input v-model="form.password" label="Reset Password (kosongkan kalau tidak ganti)" type="password" />

            <div>
                <label class="label-base">Roles</label>
                <div class="space-y-2">
                    <label v-for="r in roles" :key="r.id" class="flex gap-2 text-sm">
                        <input type="checkbox" :value="r.name" v-model="form.roles" />
                        <span>{{ r.name }}</span>
                    </label>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.is_active" /> Aktif
            </label>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('settings.users.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan</Button>
            </div>
        </form>
    </div>
</template>
