<!-- Account Payable: landing per sub-modul (grup BO). Detail item muncul di halaman ini. -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ section: string; title: string }>();

function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

type Item = { label: string; route: string | null; summary?: string | null };
const sections = computed<Record<string, Item[]>>(() => ({
    setup: [
        { label: 'Transaction Rule', route: r('bs.gl.transaction-rule.index', { source: 'ap' }) },
        { label: 'Kategori Invoice', route: r('bs.ap.categories.index') },
        { label: 'Repair Data', route: r('bs.ap.repair.index') },
        { label: 'Kelengkapan Berkas', route: r('bs.ap.berkas.index') },
    ],
    master: [
        { label: 'Invoice Hutang', route: r('bs.ap.index') },
        { label: 'Kuitansi Hutang', route: r('bs.ap.kuitansi.index') },
    ],
    query: [
        { label: 'Kartu Hutang', route: r('bs.ap.card'), summary: r('bs.ap.card', { view: 'summary' }) },
        { label: 'Aging Hutang', route: r('bs.ap.aging', { view: 'detail' }), summary: r('bs.ap.aging', { view: 'summary' }) },
        { label: 'Proyeksi Hutang', route: r('bs.projection', { mode: 'hutang' }), summary: r('bs.projection', { mode: 'hutang', view: 'summary' }) },
        { label: 'Kartu Pembayaran Hutang', route: r('bs.ap.payment-card') },
        { label: 'Laporan Invoice Hutang', route: r('bs.ap.report') },
        { label: 'Rekap PPh Dipotong', route: r('bs.tax.pph') },
    ],
    generate: [
        { label: 'Registrasi Invoice Hutang', route: r('bs.ap.create') },
    ],
    penyesuaian: [
        { label: 'Penghapusan Nilai Hutang', route: r('bs.ap.penghapusan.index') },
    ],
    'posting-po': [
        { label: 'Posting Invoice', route: r('bs.ap.posting.index', { source: 'po' }) },
    ],
    'posting-spk': [
        { label: 'Posting Invoice', route: r('bs.ap.posting.index', { source: 'spk' }) },
    ],
    saldoawal: [
        { label: 'Saldo Awal', route: r('bs.ap.opening.index') },
    ],
}));

const items = computed<Item[]>(() => sections.value[props.section] ?? []);
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('bs.ap.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-primary-600" /> {{ title }}</h1>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="it in items" :key="it.label" class="card flex items-center justify-between gap-2 p-4">
                <a v-if="it.route" :href="it.route" class="flex-1 font-medium text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                <span v-else class="flex-1 font-medium text-neutral-400">{{ it.label }}</span>
                <span class="flex items-center gap-2">
                    <a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a>
                    <Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge>
                    <span v-else class="text-primary-400">→</span>
                </span>
            </div>
        </div>
    </div>
</template>
