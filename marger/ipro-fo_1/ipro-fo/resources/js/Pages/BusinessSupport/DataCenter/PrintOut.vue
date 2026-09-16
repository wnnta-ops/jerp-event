<!-- Data Center: Print-Out per center (customer/supplier/employee) -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from 'lucide-vue-next';

const props = defineProps<{ center: string; title: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

const links = computed(() => {
    const map: Record<string, Array<{ label: string; desc: string; route: string | null }>> = {
        customer: [
            { label: 'Daftar Customer', desc: 'Cetak daftar master customer.', route: r('customers.index') },
            { label: 'Customer Info (CIF)', desc: 'Profil customer 360 — cetak per customer.', route: r('cif.index') },
        ],
        supplier: [
            { label: 'Laporan Master Supplier', desc: 'Cetak daftar master supplier.', route: r('bs.dc.supplier.report') },
            { label: 'Daftar Supplier', desc: 'Master supplier (list).', route: r('bs.suppliers.index') },
        ],
        employee: [
            { label: 'Daftar Karyawan', desc: 'Cetak daftar master karyawan.', route: r('bs.employees.index') },
        ],
    };

    return map[props.center] ?? [];
});
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Data Center</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Printer class="h-6 w-6 text-primary-600" /> Print-Out — {{ title }}</h1>
        <p class="text-sm text-neutral-500">Pilih daftar/laporan untuk dicetak.</p>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <a v-for="l in links" :key="l.label" :href="l.route ?? '#'" class="card block p-4 transition hover:border-primary-300 hover:shadow-sm">
                <div class="flex items-center justify-between"><span class="font-semibold text-primary-700 dark:text-primary-300">{{ l.label }}</span><span class="text-primary-400">→</span></div>
                <p class="mt-1 text-xs text-neutral-500">{{ l.desc }}</p>
            </a>
        </div>
    </div>
</template>
