<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ users: any; filters: Record<string, any> }>();

const search = ref(props.filters.search ?? '');
const refresh = debounce(() => router.get(route('settings.users.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
watch(search, refresh);

function blockUser(id: number) {
    if (confirm('Toggle blokir user ini?')) router.post(route('settings.users.block', id));
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Users</h1>
            <Link :href="route('settings.users.create')">
                <Button><template #icon-left><Plus class="h-4 w-4" /></template>User Baru</Button>
            </Link>
        </div>

        <div class="card p-4">
            <Input v-model="search" label="Cari nama / email" />
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="u in users.data" :key="u.id">
                        <td class="px-4 py-3 font-medium">{{ u.name }}</td>
                        <td class="px-4 py-3 text-neutral-500">{{ u.email }}</td>
                        <td class="px-4 py-3">
                            <Badge v-for="r in u.roles" :key="r.id" variant="info" class="mr-1">{{ r.name }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge v-if="u.is_blocked" variant="danger" dot>Blocked</Badge>
                            <Badge v-else-if="u.is_active" variant="success" dot>Active</Badge>
                            <Badge v-else dot>Inactive</Badge>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <Link :href="route('settings.users.edit', u.id)" class="text-primary-600 text-sm">Edit</Link>
                            <button @click="blockUser(u.id)" class="text-danger-600 text-sm">{{ u.is_blocked ? 'Unblock' : 'Block' }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
