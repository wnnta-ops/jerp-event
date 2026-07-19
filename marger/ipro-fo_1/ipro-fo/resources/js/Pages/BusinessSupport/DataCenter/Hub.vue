<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Database, Truck, IdCard } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { suppliers: number; suppliers_active: number; employees: number; employees_active: number };
}>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// DATA CENTER — 2 sub-modul (Supplier / Employee Center). Print-Out di tiap center.
// Customer Center DIHILANGKAN: data customer dikelola lewat sub-modul Customer di
// Marketing & Sales (FO/J-ERP), tidak dikelola dua kali di Data Center.
const menu: Array<{ group: string; icon: any; items: Array<{ label: string; route: string | null }> }> = [
    { group: 'Supplier Center', icon: Truck, items: [
        { label: 'Print-Out', route: r('bs.dc.supplier.printout') },
        { label: 'Supplier', route: r('bs.suppliers.index') },
        { label: 'Laporan Master Supplier', route: r('bs.dc.supplier.report') },
        { label: 'Registrasi Supplier', route: r('bs.suppliers.index') },
        { label: 'Merge Supplier', route: r('bs.dc.supplier.merge') },
        { label: 'Upload Supplier', route: r('bs.dc.supplier.upload') },
        { label: 'Kategori Supplier', route: r('bs.dc.supplier.category.index') },
        { label: 'Repair Data Supplier', route: r('bs.dc.supplier.repair') },
    ]},
    { group: 'Employee Center', icon: IdCard, items: [
        { label: 'Print-Out', route: r('bs.dc.employee.printout') },
        { label: 'Karyawan', route: r('bs.employees.index') },
        { label: 'HR Department', route: r('bs.dc.department.index') },
        { label: 'Registrasi Karyawan', route: r('bs.dc.employee.create') },
        { label: 'Merge Karyawan', route: r('bs.dc.employee.merge') },
        { label: 'Repair Data Karyawan', route: r('bs.dc.employee.repair') },
    ]},
];

const totalItems = menu.reduce((n, g) => n + g.items.length, 0);
const builtItems = menu.reduce((n, g) => n + g.items.filter(i => i.route).length, 0);
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Dashboard</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Database class="h-6 w-6 text-primary-600" /> Data Center</h1>
        <p class="text-sm text-neutral-500">Master data terpusat — Supplier · Employee Center ({{ totalItems }} sub-modul, {{ builtItems }} aktif).</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><Truck class="h-4 w-4" /> Supplier</div><div class="mt-1 text-2xl font-bold">{{ summary.suppliers.toLocaleString('id-ID') }}</div><div class="text-xs text-neutral-500">{{ summary.suppliers_active }} aktif</div></div>
            <div class="card p-4"><div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><IdCard class="h-4 w-4" /> Karyawan</div><div class="mt-1 text-2xl font-bold">{{ summary.employees.toLocaleString('id-ID') }}</div><div class="text-xs text-neutral-500">{{ summary.employees_active }} aktif</div></div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div v-for="g in menu" :key="g.group" class="card p-4">
                <h2 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-accent-600"><component :is="g.icon" class="h-4 w-4" /> {{ g.group }}</h2>
                <ul class="space-y-1">
                    <li v-for="it in g.items" :key="it.label">
                        <a v-if="it.route" :href="it.route" class="flex items-center justify-between rounded px-2 py-1.5 text-sm text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-neutral-800">{{ it.label }} <span class="text-primary-400">→</span></a>
                        <div v-else class="flex items-center justify-between rounded px-2 py-1.5 text-sm text-neutral-400"><span>{{ it.label }}</span><Badge variant="neutral" size="sm">segera</Badge></div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
