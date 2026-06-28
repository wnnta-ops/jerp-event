<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ScrollText, Search } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ logs: any; filters: { event?: string; search?: string }; events: Array<string> }>();

const event = ref(props.filters.event ?? '');
const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('setup-sales.log'), { event: event.value || undefined, search: search.value || undefined }, { preserveState: true, replace: true }); }
function fmt(d: string) { return d ? new Date(d).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
const evVar: Record<string, any> = { created: 'success', updated: 'primary', deleted: 'danger' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><ScrollText class="h-6 w-6 text-danger-600" /> Log File <span class="text-base font-normal text-neutral-400">(Audit Setup Sales)</span></h1>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari entitas…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="event" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua event</option><option v-for="e in events" :key="e" :value="e">{{ e }}</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">User</th><th class="px-4 py-3 text-center">Event</th><th class="px-4 py-3">Entitas</th><th class="px-4 py-3">IP</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in logs.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2 font-mono text-xs">{{ fmt(r.created_at) }}</td>
                        <td class="px-4 py-2">{{ r.user_name ?? '—' }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="evVar[r.event] ?? 'neutral'" size="sm">{{ r.event }}</Badge></td>
                        <td class="px-4 py-2"><span class="font-medium">{{ r.short_type }}</span> <span class="text-neutral-400">#{{ r.auditable_id }}</span></td>
                        <td class="px-4 py-2 font-mono text-xs text-neutral-500">{{ r.ip_address ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!logs.data?.length" class="px-4 py-6 text-center text-sm text-neutral-400">Tidak ada log.</div>
        </div>
        <div v-if="logs.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in logs.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
