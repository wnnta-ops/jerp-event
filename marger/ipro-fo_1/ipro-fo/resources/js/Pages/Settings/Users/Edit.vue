<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    user: any;
    roles: Array<{ id: number; name: string; description: string | null }>;
    signatureUrl: string | null;
}>();

// TTD — form terpisah dari data user (multipart), langsung tersimpan saat diunggah.
const ttdInput = ref<HTMLInputElement | null>(null);
const ttdForm = useForm({ signature: null as File | null });
const ttdPreview = ref<string | null>(null);

function pilihTtd(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    ttdForm.signature = file;
    ttdPreview.value = file ? URL.createObjectURL(file) : null;
}

function simpanTtd() {
    ttdForm.post(route('settings.users.signature.store', props.user.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { ttdForm.reset(); ttdPreview.value = null; if (ttdInput.value) ttdInput.value.value = ''; },
    });
}

function hapusTtd() {
    if (!confirm(`Hapus TTD ${props.user.name}?`)) return;
    router.delete(route('settings.users.signature.destroy', props.user.id), { preserveScroll: true });
}

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

        <div class="card p-6 space-y-4">
            <div>
                <h2 class="text-base font-semibold">Tanda Tangan (TTD)</h2>
                <p class="text-sm text-neutral-500">PNG, latar transparan disarankan, maks. 1 MB. Distempel otomatis pada tahap approval yang disetujui user ini.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="label-base">TTD saat ini</p>
                    <div class="flex h-32 items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-[repeating-conic-gradient(#f5f5f5_0_25%,#fff_0_50%)] bg-[length:16px_16px] dark:border-neutral-700">
                        <img v-if="signatureUrl" :src="signatureUrl" alt="TTD" class="max-h-28 max-w-full object-contain" />
                        <span v-else class="text-sm text-neutral-400">Belum ada TTD</span>
                    </div>
                </div>
                <div v-if="ttdPreview">
                    <p class="label-base">Pratinjau baru</p>
                    <div class="flex h-32 items-center justify-center rounded-lg border border-primary-300 bg-[repeating-conic-gradient(#f5f5f5_0_25%,#fff_0_50%)] bg-[length:16px_16px]">
                        <img :src="ttdPreview" alt="Pratinjau TTD" class="max-h-28 max-w-full object-contain" />
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input ref="ttdInput" type="file" accept="image/png" class="text-sm" @change="pilihTtd" />
                <Button :disabled="!ttdForm.signature" :loading="ttdForm.processing" @click="simpanTtd">
                    {{ signatureUrl ? 'Ganti TTD' : 'Unggah TTD' }}
                </Button>
                <Button v-if="signatureUrl" variant="outline" @click="hapusTtd">Hapus TTD</Button>
            </div>
            <p v-if="ttdForm.errors.signature" class="text-xs text-red-600">{{ ttdForm.errors.signature }}</p>
        </div>
    </div>
</template>
