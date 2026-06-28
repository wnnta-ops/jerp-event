<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    ArrowLeft, Settings2, Database, Repeat, BarChart3, ChevronRight,
    ShoppingCart, Boxes, FileDown, FileUp, Wallet, Calculator, HardHat, PieChart, Briefcase,
} from 'lucide-vue-next';

const props = defineProps<{
    moduleKey: string;
    module: { label: string; icon: string; desc: string; sections: Record<string, string[]> };
}>();

// Quick-link ke halaman operasional yang sudah aktif (Phase 2: Inventory + Purchasing).
const quickLinks = computed<Array<{ label: string; route: string }>>(() => {
    const map: Record<string, Array<{ label: string; route: string }>> = {
        inventory: [
            { label: 'Item / Stok', route: route('bs.items.index') },
            { label: 'Satuan', route: route('bs.inv.setup.index', 'uom') },
            { label: 'Kategori Stok', route: route('bs.inv.setup.index', 'category') },
            { label: 'Gudang', route: route('bs.inv.setup.index', 'warehouse') },
            { label: 'FPB', route: route('bs.fpb.index') },
            { label: 'Penerimaan', route: route('bs.receipt.index') },
            { label: 'Pengeluaran', route: route('bs.issue.index') },
            { label: 'Kartu Stok', route: route('bs.stockcard.index') },
        ],
        purchasing: [
            { label: 'Purchase Order', route: route('bs.po.index') },
            { label: 'Supplier', route: route('bs.suppliers.index') },
        ],
    };
    return map[props.moduleKey] ?? [];
});

const icons: Record<string, any> = { ShoppingCart, Boxes, FileDown, FileUp, Wallet, Calculator, HardHat, PieChart };
const sectionIcon: Record<string, any> = { Setup: Settings2, Master: Database, Transaksi: Repeat, Laporan: BarChart3, Upload: Repeat, Alokasi: Repeat, Koreksi: Repeat };
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Business Support
        </Link>

        <div class="flex items-start gap-3">
            <component :is="icons[module.icon] ?? Briefcase" class="h-9 w-9 text-primary-600" />
            <div>
                <h1 class="text-2xl font-bold">{{ module.label }}</h1>
                <p class="text-sm text-neutral-500">{{ module.desc }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-info-500/30 bg-info-500/5 px-4 py-2.5 text-sm text-info-700 dark:text-info-400">
            Struktur menu sesuai user-manual eProperty. <strong>Setup &amp; Master</strong> memakai fondasi bersama (Chart of Account/GL, Cost Center, Supplier) yang sudah aktif. Transaksi inti + jurnal GL ditambahkan bertahap (Phase berikutnya).
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div v-for="(items, section) in module.sections" :key="section" class="card p-4">
                <h2 class="mb-2 flex items-center gap-2 font-semibold">
                    <component :is="sectionIcon[section] ?? Settings2" class="h-4 w-4 text-primary-600" />
                    {{ section }}
                </h2>
                <ul class="space-y-1">
                    <li v-for="item in items" :key="item" class="flex items-center gap-2 rounded px-2 py-1.5 text-sm text-neutral-600 hover:bg-neutral-50 dark:text-neutral-300 dark:hover:bg-neutral-800">
                        <ChevronRight class="h-3.5 w-3.5 text-neutral-300" />
                        {{ item }}
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="quickLinks.length" class="rounded-lg border border-success-500/30 bg-success-500/5 p-4">
            <div class="mb-2 text-sm font-semibold text-success-700 dark:text-success-400">✅ Sudah aktif (Phase 2) — buka langsung:</div>
            <div class="flex flex-wrap gap-2">
                <a v-for="ql in quickLinks" :key="ql.label" :href="ql.route" class="rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium hover:bg-primary-50 hover:text-primary-700 dark:border-neutral-700 dark:bg-neutral-900">{{ ql.label }} →</a>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Link :href="route('bs.accounts.index')" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Chart of Account →</Link>
            <Link :href="route('bs.cost-centers.index')" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Cost Center →</Link>
            <Link :href="route('bs.suppliers.index')" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Supplier →</Link>
        </div>
    </div>
</template>
