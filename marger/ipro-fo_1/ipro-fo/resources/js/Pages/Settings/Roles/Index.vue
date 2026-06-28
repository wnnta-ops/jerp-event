<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Shield, Plus } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

defineProps<{
    roles: Array<{ id: number; name: string; description: string | null; permissions: Array<{ id: number; name: string }> }>;
}>();

function hapus(r: { id: number; name: string }) {
    if (confirm(`Hapus role "${r.name}"? Tindakan ini permanen. (Role yang masih dipakai user tidak bisa dihapus.)`)) {
        router.delete(route('settings.roles.destroy', r.id));
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link :href="route('settings.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Settings</Link>
                <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Shield class="h-6 w-6 text-primary-600" /> Roles &amp; Hak Akses</h1>
                <p class="text-sm text-neutral-500">Atur permission (hak akses) tiap role di sini. Penetapan role ke user dilakukan di menu <b>Users</b>.</p>
            </div>
            <Link :href="route('settings.roles.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Role</Button></Link>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Deskripsi</th>
                        <th class="px-4 py-3 text-center">Jml. Hak Akses</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in roles" :key="r.id">
                        <td class="px-4 py-3 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-3 text-neutral-500">{{ r.description || '—' }}</td>
                        <td class="px-4 py-3 text-center"><Badge variant="info">{{ r.permissions.length }}</Badge></td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            <Link :href="route('settings.roles.edit', r.id)" class="text-sm font-medium text-primary-600 hover:underline">Edit Hak Akses</Link>
                            <button v-if="r.name.toLowerCase() !== 'superadmin'" type="button" @click="hapus(r)" class="text-sm font-medium text-danger-600 hover:underline">Hapus</button>
                        </td>
                    </tr>
                    <tr v-if="!roles.length"><td colspan="4" class="px-4 py-8 text-center text-sm text-neutral-400">Belum ada role.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
