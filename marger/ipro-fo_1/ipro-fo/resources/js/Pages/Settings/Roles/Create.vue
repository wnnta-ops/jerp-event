<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';

type Perm = { id: number; name: string; module: string | null; label: string | null };

const props = defineProps<{ all_permissions: Record<string, Perm[]> }>();

const form = useForm({ name: '', description: '', permissions: [] as string[] });

const modules = computed(() =>
    Object.keys(props.all_permissions).map((k) => ({ key: k, title: k && k.trim() !== '' ? k : 'Umum', perms: props.all_permissions[k] })),
);
const totalPerm = computed(() => Object.values(props.all_permissions).reduce((s, a) => s + a.length, 0));

function permLabel(p: Perm) { return p.label || p.name; }
function names(perms: Perm[]) { return perms.map((p) => p.name); }
function moduleAll(perms: Perm[]) { return perms.length > 0 && names(perms).every((n) => form.permissions.includes(n)); }
function moduleSome(perms: Perm[]) { return names(perms).some((n) => form.permissions.includes(n)); }
function moduleCount(perms: Perm[]) { return perms.filter((p) => form.permissions.includes(p.name)).length; }
function toggleModule(perms: Perm[], on: boolean) {
    const ns = names(perms);
    form.permissions = on ? Array.from(new Set([...form.permissions, ...ns])) : form.permissions.filter((n) => !ns.includes(n));
}
function selectAll(on: boolean) {
    form.permissions = on ? Object.values(props.all_permissions).flat().map((p) => p.name) : [];
}
function submit() { form.post(route('settings.roles.store')); }
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <Link :href="route('settings.roles.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali ke Roles</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-bold">Role Baru</h1>
            <div class="text-sm text-neutral-500"><b class="text-primary-600">{{ form.permissions.length }}</b> / {{ totalPerm }} hak akses dipilih</div>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card space-y-3 p-4">
                <div>
                    <label class="label-base">Nama Role *</label>
                    <input v-model="form.name" required class="h-9 w-full rounded-lg border border-neutral-300 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="mis. Auditor" />
                    <p v-if="form.errors.name" class="mt-1 text-xs font-medium text-danger-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="label-base">Deskripsi (opsional)</label>
                    <input v-model="form.description" class="h-9 w-full rounded-lg border border-neutral-300 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="Keterangan role…" />
                </div>
                <div class="flex flex-wrap items-center gap-2 border-t border-neutral-200 pt-3 dark:border-neutral-800">
                    <button type="button" @click="selectAll(true)" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Pilih Semua</button>
                    <button type="button" @click="selectAll(false)" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Kosongkan</button>
                    <p v-if="form.errors.permissions" class="text-xs font-medium text-danger-600">{{ form.errors.permissions }}</p>
                </div>
            </div>

            <div v-for="m in modules" :key="m.key" class="card overflow-hidden">
                <label class="flex cursor-pointer items-center gap-2 bg-neutral-50 px-4 py-2.5 text-sm font-semibold dark:bg-neutral-800">
                    <input type="checkbox" :checked="moduleAll(m.perms)" :indeterminate.prop="!moduleAll(m.perms) && moduleSome(m.perms)" @change="toggleModule(m.perms, ($event.target as HTMLInputElement).checked)" />
                    <span class="uppercase tracking-wide">{{ m.title }}</span>
                    <span class="text-xs font-normal text-neutral-400">({{ moduleCount(m.perms) }}/{{ m.perms.length }})</span>
                </label>
                <div class="grid gap-x-6 gap-y-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="p in m.perms" :key="p.id" class="flex items-start gap-2 text-sm">
                        <input type="checkbox" :value="p.name" v-model="form.permissions" class="mt-0.5" />
                        <span>{{ permLabel(p) }}<span class="block font-mono text-[11px] text-neutral-400">{{ p.name }}</span></span>
                    </label>
                </div>
            </div>

            <div class="sticky bottom-0 flex items-center justify-end gap-3 border-t border-neutral-200 bg-white/90 py-3 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/90">
                <span v-if="form.permissions.length === 0" class="mr-auto text-xs text-warning-600">Pilih minimal 1 hak akses.</span>
                <Link :href="route('settings.roles.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing" :disabled="!form.name || form.permissions.length === 0">Simpan Role</Button>
            </div>
        </form>
    </div>
</template>
