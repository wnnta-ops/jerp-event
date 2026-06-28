<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FileBarChart, Users, Tag, History, Boxes, Building2, ChevronRight } from 'lucide-vue-next';

const reports = [
    { label: 'Master Sales', desc: 'Daftar lengkap sales / marketing', icon: Users, route: 'setup-sales.reports.master-sales', color: 'text-primary-600' },
    { label: 'Price List', desc: 'Daftar harga aktif per unit', icon: Tag, route: 'setup-sales.reports.price-list', color: 'text-accent-600' },
    { label: 'History Price List', desc: 'Riwayat perubahan harga unit', icon: History, route: 'setup-sales.reports.history-price', color: 'text-amber-600' },
    { label: 'Summary Stock Per Tipe', desc: 'Rekap stok per tipe unit', icon: Boxes, route: 'setup-sales.reports.summary-stock', color: 'text-success-600' },
    { label: 'Master Unit', desc: 'Daftar lengkap unit & harga', icon: Building2, route: 'setup-sales.reports.master-unit', color: 'text-primary-600' },
];
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('setup-sales.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Sales</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-success-600" /> Laporan Setup Sales</h1>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="r in reports" :key="r.label" :href="route(r.route)" class="card group flex items-start gap-3 p-4 transition hover:ring-2 hover:ring-primary-300">
                <component :is="r.icon" class="h-7 w-7 shrink-0" :class="r.color" />
                <div class="flex-1"><div class="font-semibold leading-tight">{{ r.label }}</div><div class="mt-0.5 text-xs text-neutral-500">{{ r.desc }}</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-primary-500" />
            </Link>
        </div>
    </div>
</template>
