<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Layers, Plus, Search, ChevronRight } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rows: any; filters: { search?: string; type?: string }; types: Record<string, string> }>();

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
function apply() { router.get(route('setup-sales.scheme.index'), { search: search.value || undefined, type: type.value || undefined }, { preserveState: true, replace: true }); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Skema Cara Bayar</h1>
            <Link :href="route('setup-sales.scheme.create')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Plus class="h-4 w-4" /> Skema Baru</Link>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari kode / nama skema…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="type" @change="apply" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua tipe</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Tipe</th><th class="px-4 py-3">Project</th><th class="px-4 py-3 text-center">Termin</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.visit(route('setup-sales.scheme.show', r.id))">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2">{{ r.name }}</td>
                        <td class="px-4 py-2">{{ types[r.type] ?? r.type }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.project?.name ?? 'Semua' }}</td>
                        <td class="px-4 py-2 text-center">{{ r.terms_count }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2 text-right"><ChevronRight class="inline h-4 w-4 text-neutral-300" /></td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Belum ada skema. Klik “Skema Baru”.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
