<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

defineProps<{ roles: Array<{ id: number; name: string; description: string | null }> }>();

const form = useForm({
    name: '', email: '', phone: '', password: '', is_active: true, roles: [] as string[],
});

function submit() { form.post(route('settings.users.store')); }
</script>

<template>
    <div class="max-w-xl mx-auto space-y-4">
        <Link :href="route('settings.users.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">User Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <Input v-model="form.name" label="Nama Lengkap" required :error="form.errors.name" />
            <Input v-model="form.email" label="Email" type="email" required :error="form.errors.email" />
            <Input v-model="form.phone" label="No HP" />
            <Input v-model="form.password" label="Password" type="password" hint="Minimal 12 karakter" required :error="form.errors.password" />
            <div>
                <label class="label-base">Role (minimal 1)</label>
                <div class="space-y-2">
                    <label v-for="r in roles" :key="r.id" class="flex items-start gap-2 text-sm">
                        <input type="checkbox" :value="r.name" v-model="form.roles" class="mt-1" />
                        <div>
                            <p class="font-medium">{{ r.name }}</p>
                            <p class="text-xs text-neutral-500">{{ r.description }}</p>
                        </div>
                    </label>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.is_active" />
                Aktif (bisa login)
            </label>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('settings.users.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan</Button>
            </div>
        </form>
    </div>
</template>
