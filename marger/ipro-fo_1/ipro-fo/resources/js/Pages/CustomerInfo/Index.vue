<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { IdCard, Search, ChevronRight } from 'lucide-vue-next';

const props = defineProps<{ rows: any; filters: { search?: string } }>();
const search = ref(props.filters.search ?? '');
function apply() { router.get(route('cif.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }
</script>
<template>
    <div class="space-y-4">
        <h1 class="flex items-center gap-2 text-2xl font-bold"><IdCard class="h-6 w-6 text-primary-600" /> Customer Information File</h1>
        <p class="text-sm text-neutral-500">Cari customer untuk melihat profil 360°: kontrak, jadwal &amp; riwayat bayar, tunggakan, denda, surat peringatan.</p>

        <div class="relative max-w-md"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="apply" placeholder="Cari kode / nama customer…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Telepon</th><th class="px-4 py-3">Kota</th><th class="px-4 py-3 text-center">Kontrak</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.visit(route('cif.show', r.id))">
                        <td class="px-4 py-2 font-mono font-semibold">{{ r.code }}</td>
                        <td class="px-4 py-2">{{ r.full_name }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.phone || '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.city || '—' }}</td>
                        <td class="px-4 py-2 text-center">{{ r.bookings_count }}</td>
                        <td class="px-4 py-2 text-right"><ChevronRight class="inline h-4 w-4 text-neutral-300" /></td>
                    </tr>
                    <tr v-if="!rows.data?.length"><td colspan="6" class="px-4 py-8 text-center text-neutral-400">Tidak ada customer.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-if="rows.last_page > 1" class="flex justify-center gap-1 text-sm">
            <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" v-html="l.label" class="rounded px-3 py-1.5" :class="[l.active ? 'bg-primary-600 text-white' : 'border border-neutral-300 dark:border-neutral-700', !l.url ? 'pointer-events-none opacity-40' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800']" />
        </div>
    </div>
</template>
