<!-- Data Center: landing per sub-modul (Supplier / Employee Center) -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Truck, IdCard } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ center: string; title: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// Customer Center DIHILANGKAN — data customer dikelola di sub-modul Customer (Marketing & Sales).
const icons: Record<string, any> = { supplier: Truck, employee: IdCard };

const items = computed<Array<{ label: string; route: string | null }>>(() => {
    const map: Record<string, Array<{ label: string; route: string | null }>> = {
        supplier: [
            { label: 'Supplier', route: r('bs.suppliers.index') },
            { label: 'Laporan Master Supplier', route: r('bs.dc.supplier.report') },
            { label: 'Registrasi Supplier', route: r('bs.suppliers.index') },
            { label: 'Merge Supplier', route: r('bs.dc.supplier.merge') },
            { label: 'Upload Supplier', route: r('bs.dc.supplier.upload') },
            { label: 'Kategori Supplier', route: r('bs.dc.supplier.category.index') },
            { label: 'Repair Data Supplier', route: r('bs.dc.supplier.repair') },
            { label: 'Print-Out', route: r('bs.dc.supplier.printout') },
        ],
        employee: [
            { label: 'Karyawan', route: r('bs.employees.index') },
            { label: 'HR Department', route: r('bs.dc.department.index') },
            { label: 'Registrasi Karyawan', route: r('bs.dc.employee.create') },
            { label: 'Merge Karyawan', route: r('bs.dc.employee.merge') },
            { label: 'Repair Data Karyawan', route: r('bs.dc.employee.repair') },
            { label: 'Print-Out', route: r('bs.dc.employee.printout') },
        ],
    };

    return map[props.center] ?? [];
});
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Data Center</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><component :is="icons[center]" class="h-6 w-6 text-primary-600" /> {{ title }}</h1>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a v-for="it in items" :key="it.label" :href="it.route ?? '#'" class="card block p-4 transition hover:border-primary-300 hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="font-semibold" :class="it.route ? 'text-primary-700 dark:text-primary-300' : 'text-neutral-400'">{{ it.label }}</span>
                    <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                    <span v-else class="text-primary-400">→</span>
                </div>
            </a>
        </div>
    </div>
</template>
