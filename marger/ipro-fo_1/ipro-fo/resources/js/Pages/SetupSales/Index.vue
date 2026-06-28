<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Settings2, Layers, Users, Handshake, Tag, LayoutDashboard, FileBarChart, BellRing, ScrollText, ChevronRight, Boxes } from 'lucide-vue-next';

defineProps<{ stats: { schemes: number; sales: number; agents: number; pricelists: number; units: number; available: number; sold: number } }>();

const tiles = [
    { label: 'Skema Cara Bayar', desc: 'Builder Rumus Global / Nilai / Jadwal', icon: Layers, route: 'setup-sales.scheme.index', color: 'text-primary-600' },
    { label: 'Master Sales / Marketing', desc: 'Data sales & struktur supervisor', icon: Users, route: 'setup-sales.sales.index', color: 'text-primary-600' },
    { label: 'Master Agen / Broker', desc: 'Agen properti & rekening komisi', icon: Handshake, route: 'setup-sales.agent.index', color: 'text-accent-600' },
    { label: 'Price List', desc: 'Daftar harga unit + aktivasi', icon: Tag, route: 'setup-sales.pricelist.index', color: 'text-accent-600' },
    { label: 'Dashboard', desc: 'Ringkasan stok per tipe & skema', icon: LayoutDashboard, route: 'setup-sales.dashboard', color: 'text-success-600' },
    { label: 'Laporan', desc: 'Master Sales, Price List, History, Stock', icon: FileBarChart, route: 'setup-sales.reports.index', color: 'text-success-600' },
    { label: 'Reminder', desc: 'Price list berakhir, unit belum berharga', icon: BellRing, route: 'setup-sales.reminder', color: 'text-amber-600' },
    { label: 'Log File', desc: 'Audit trail setup penjualan', icon: ScrollText, route: 'setup-sales.log', color: 'text-danger-600' },
];
</script>
<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Settings2 class="h-6 w-6 text-primary-600" /> Setup Sales</h1>
            <p class="text-sm text-neutral-500">Setup penjualan: skema cara bayar, master sales/agen, price list, dashboard, laporan, reminder &amp; log.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Skema Aktif</div><div class="mt-1 text-xl font-bold">{{ stats.schemes }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sales</div><div class="mt-1 text-xl font-bold text-primary-600">{{ stats.sales }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Agen</div><div class="mt-1 text-xl font-bold text-accent-600">{{ stats.agents }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Price List Aktif</div><div class="mt-1 text-xl font-bold">{{ stats.pricelists }}</div></div>
            <div class="card p-4 sm:col-span-2 lg:col-span-2 flex items-center gap-4">
                <Boxes class="h-8 w-8 text-neutral-400" />
                <div class="grid flex-1 grid-cols-3 gap-2 text-center">
                    <div><div class="text-xs uppercase text-neutral-400">Unit</div><div class="text-lg font-bold">{{ stats.units }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Tersedia</div><div class="text-lg font-bold text-success-600">{{ stats.available }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Terjual/Booked</div><div class="text-lg font-bold text-danger-600">{{ stats.sold }}</div></div>
                </div>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <Link v-for="t in tiles" :key="t.label" :href="route(t.route)" class="card group flex items-start gap-3 p-4 transition hover:ring-2 hover:ring-primary-300">
                <component :is="t.icon" class="h-7 w-7 shrink-0" :class="t.color" />
                <div class="flex-1"><div class="font-semibold leading-tight">{{ t.label }}</div><div class="mt-0.5 text-xs text-neutral-500">{{ t.desc }}</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-primary-500" />
            </Link>
        </div>
    </div>
</template>
