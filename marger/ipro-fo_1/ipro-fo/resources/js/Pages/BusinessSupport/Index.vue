<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase, BookOpen, Building2, Truck, ChevronRight, BookText,
    ShoppingCart, Boxes, FileDown, FileUp, Wallet, Calculator, HardHat, PieChart,
    HandCoins, Coins, Banknote, ReceiptText,
} from 'lucide-vue-next';

defineProps<{
    modules: Array<{ key: string; label: string; icon: string; desc: string; sections: Record<string, number> }>;
    foundation: { accounts: number; cost_centers: number; suppliers: number };
}>();

const icons: Record<string, any> = { ShoppingCart, Boxes, FileDown, FileUp, Wallet, Calculator, HardHat, PieChart };
function totalMenus(sections: Record<string, number>) {
    return Object.values(sections).reduce((a, b) => a + b, 0);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <Briefcase class="h-6 w-6 text-primary-600" /> Business Support
            </h1>
            <p class="text-sm text-neutral-500">Back-office akuntansi: pengadaan, hutang/piutang, kas/bank, persediaan, budget &amp; HPP — terintegrasi General Ledger.</p>
        </div>

        <!-- General Ledger (inti) -->
        <Link :href="route('bs.gl.index')" class="card flex items-center gap-4 p-5 ring-1 ring-primary-200 transition hover:ring-2 hover:ring-primary-400 dark:ring-primary-900">
            <BookText class="h-10 w-10 text-primary-600" />
            <div class="flex-1">
                <div class="text-lg font-bold">General Ledger</div>
                <div class="text-sm text-neutral-500">Buku besar double-entry: jurnal, periode fiskal, neraca saldo, neraca & laba rugi — inti akuntansi semua modul.</div>
            </div>
            <ChevronRight class="h-5 w-5 text-neutral-300" />
        </Link>

        <!-- Hutang, Piutang & Kas (terhubung GL) -->
        <div class="grid gap-3 md:grid-cols-3">
            <Link :href="route('bs.ap.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-danger-300">
                <HandCoins class="h-8 w-8 text-danger-600" />
                <div class="flex-1"><div class="font-semibold">Account Payable</div><div class="text-sm text-neutral-500">Hutang usaha ke supplier — register &amp; pelunasan, jurnal otomatis.</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300" />
            </Link>
            <Link :href="route('bs.ar.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-success-300">
                <Coins class="h-8 w-8 text-success-600" />
                <div class="flex-1"><div class="font-semibold">Account Receivable</div><div class="text-sm text-neutral-500">Piutang usaha ke customer — tagihan &amp; penerimaan, jurnal otomatis.</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300" />
            </Link>
            <Link :href="route('bs.cashbook.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-primary-300">
                <Banknote class="h-8 w-8 text-primary-600" />
                <div class="flex-1"><div class="font-semibold">Cash Book</div><div class="text-sm text-neutral-500">Kas masuk/keluar &amp; transfer antar rekening — saldo bank realtime, jurnal otomatis.</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300" />
            </Link>
            <Link :href="route('bs.tax.rekap')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-accent-300">
                <ReceiptText class="h-8 w-8 text-accent-600" />
                <div class="flex-1"><div class="font-semibold">Faktur Pajak (PPN)</div><div class="text-sm text-neutral-500">Rekap PPN Keluaran (AR) &amp; Masukan (AP) per masa pajak — SPT Masa &amp; e-Faktur.</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300" />
            </Link>
        </div>

        <!-- Fondasi bersama -->
        <div>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-accent-600">Fondasi (Setup bersama)</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <Link :href="route('bs.accounts.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-primary-300">
                    <BookOpen class="h-8 w-8 text-primary-600" />
                    <div><div class="font-semibold">Chart of Account (GL)</div><div class="text-sm text-neutral-500">{{ foundation.accounts }} akun</div></div>
                </Link>
                <Link :href="route('bs.cost-centers.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-primary-300">
                    <Building2 class="h-8 w-8 text-primary-600" />
                    <div><div class="font-semibold">Cost Center / Divisi</div><div class="text-sm text-neutral-500">{{ foundation.cost_centers }} cost center</div></div>
                </Link>
                <Link :href="route('bs.suppliers.index')" class="card flex items-center gap-3 p-4 hover:ring-2 hover:ring-primary-300">
                    <Truck class="h-8 w-8 text-primary-600" />
                    <div><div class="font-semibold">Supplier / Vendor</div><div class="text-sm text-neutral-500">{{ foundation.suppliers }} supplier</div></div>
                </Link>
            </div>
        </div>

        <!-- 8 sub-modul -->
        <div>
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wider text-accent-600">Sub-Modul</h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <Link v-for="m in modules" :key="m.key" :href="route('bs.module', m.key)"
                    class="card group flex flex-col gap-2 p-4 transition hover:ring-2 hover:ring-primary-300">
                    <div class="flex items-center justify-between">
                        <component :is="icons[m.icon] ?? Briefcase" class="h-7 w-7 text-primary-600" />
                        <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-primary-500" />
                    </div>
                    <div class="font-semibold leading-tight">{{ m.label }}</div>
                    <div class="text-xs text-neutral-500 line-clamp-2">{{ m.desc }}</div>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <span v-for="(cnt, sec) in m.sections" :key="sec" class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-500 dark:bg-neutral-800">
                            {{ sec }} · {{ cnt }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </div>
</template>
