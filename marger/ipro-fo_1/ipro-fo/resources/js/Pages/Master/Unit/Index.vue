<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { Plus, Map } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    units: any;
    filters: Record<string, any>;
    projects: Array<{ id: number; code: string; name: string }>;
    clusters: Array<{ id: number; project_id: number; code: string; name: string }>;
    statuses: Array<{ value: string; label: string }>;
}>();

const search    = ref(props.filters.search ?? '');
const projectId = ref(props.filters.project_id ?? '');
const clusterId = ref(props.filters.cluster_id ?? '');
const status    = ref(props.filters.status ?? '');

const clustersFiltered = computed(() => projectId.value ? props.clusters.filter(c => c.project_id === Number(projectId.value)) : props.clusters);

const refresh = debounce(() => {
    router.get(route('units.index'), {
        search: search.value || undefined,
        project_id: projectId.value || undefined,
        cluster_id: clusterId.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}, 300);

watch([search, projectId, clusterId, status], refresh);

function badgeFor(s: string) {
    return ({
        available: 'success', hold: 'info', booked: 'warning',
        sold: 'primary', cancelled: 'neutral', maintenance: 'neutral',
    } as Record<string, any>)[s] ?? 'neutral';
}

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Unit</h1>
                <p class="text-sm text-neutral-500">Master data unit properti</p>
            </div>
            <div class="flex gap-2">
                <Link v-if="projectId" :href="route('siteplan.show', projectId)">
                    <Button variant="secondary">
                        <template #icon-left><Map class="h-4 w-4" /></template>
                        Site Plan
                    </Button>
                </Link>
                <Link :href="route('units.create')">
                    <Button>
                        <template #icon-left><Plus class="h-4 w-4" /></template>
                        Unit Baru
                    </Button>
                </Link>
            </div>
        </div>

        <div class="card p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
            <Input v-model="search" label="Cari Kode" placeholder="A-01..." />
            <div>
                <label class="label-base">Project</label>
                <select v-model="projectId" class="input-base">
                    <option value="">Semua Project</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <div>
                <label class="label-base">Cluster</label>
                <select v-model="clusterId" class="input-base">
                    <option value="">Semua Cluster</option>
                    <option v-for="c in clustersFiltered" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="label-base">Status</label>
                <select v-model="status" class="input-base">
                    <option value="">Semua</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Cluster</th>
                        <th class="px-4 py-3">Tipe</th>
                        <th class="px-4 py-3">LT/LB</th>
                        <th class="px-4 py-3 text-right">Harga</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="u in units.data" :key="u.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono">{{ u.unit_code }}</td>
                        <td class="px-4 py-3">{{ u.cluster?.name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ u.unit_type?.name ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs">{{ u.unit_type?.lt ?? '-' }}/{{ u.unit_type?.lb ?? '-' }} m²</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(u.current_price) }}</td>
                        <td class="px-4 py-3"><Badge :variant="badgeFor(u.status)" dot>{{ u.status_label }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('units.show', u.id)" class="text-primary-600 hover:underline text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="units.data.length === 0" title="Belum ada unit" description="Tambahkan unit pertama untuk memulai." />
        </div>
    </div>
</template>
