<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, FolderGit2, Check } from 'lucide-vue-next';

const props = defineProps<{
    mode: string;
    title: string;
    users: Array<any>;
    items: Array<any>;
    access: Record<number, number[]>;
}>();

function has(userId: number, itemId: number) { return (props.access[userId] ?? []).includes(itemId); }
function toggle(userId: number, itemId: number) {
    router.post(route('security.matrikulasi.toggle'), { mode: props.mode, user_id: userId, item_id: itemId }, { preserveScroll: true, preserveState: false });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('security.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Security</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><component :is="mode === 'company' ? Building2 : FolderGit2" class="h-6 w-6 text-accent-600" /> {{ title }}</h1>
        <p class="text-sm text-neutral-500">Centang sel untuk memberi akses user ke {{ mode === 'company' ? 'perusahaan' : 'project' }}. Perubahan tersimpan otomatis.</p>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="sticky left-0 bg-neutral-50 px-4 py-3 dark:bg-neutral-800">User</th>
                        <th v-for="it in items" :key="it.id" class="px-3 py-3 text-center"><div class="whitespace-nowrap">{{ it.name }}</div><div class="font-mono text-[10px] text-neutral-400">{{ it.code }}</div></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="u in users" :key="u.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="sticky left-0 bg-white px-4 py-2.5 dark:bg-neutral-900"><div class="font-medium">{{ u.name }}</div><div class="text-xs text-neutral-400">{{ u.email }}</div></td>
                        <td v-for="it in items" :key="it.id" class="px-3 py-2.5 text-center">
                            <button type="button" @click="toggle(u.id, it.id)" class="inline-flex h-6 w-6 items-center justify-center rounded border" :class="has(u.id, it.id) ? 'border-success-500 bg-success-500 text-white' : 'border-neutral-300 dark:border-neutral-600'">
                                <Check v-if="has(u.id, it.id)" class="h-4 w-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!items.length" class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada {{ mode === 'company' ? 'perusahaan' : 'project' }} terdaftar.</div>
        </div>
    </div>
</template>
