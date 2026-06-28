<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Users, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ rows: Array<any> }>();
const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const doPrint = () => window.print();
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between print:hidden">
            <Link :href="route('setup-sales.reports.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Laporan</Link>
            <button @click="doPrint" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Cetak</button>
        </div>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Laporan Master Sales</h1>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr class="text-left"><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Telepon</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Atasan</th><th class="px-4 py-3">Bergabung</th><th class="px-4 py-3 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-4 py-2 font-mono">{{ r.code }}</td>
                        <td class="px-4 py-2 font-medium">{{ r.name }}</td>
                        <td class="px-4 py-2">{{ r.phone || '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.email || '—' }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ r.manager?.name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ fmt(r.joined_at) }}</td>
                        <td class="px-4 py-2 text-center"><Badge :variant="r.is_active ? 'success' : 'neutral'" size="sm">{{ r.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-400">Belum ada data.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="text-xs text-neutral-400">Total: {{ rows.length }} sales</div>
    </div>
</template>
