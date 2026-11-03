<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Banknote, ChevronDown, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    summary: { saldo: number; accounts: number; trx: number; bon_outstanding: number; bon_count: number; giro: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// Sub-modul Cash Book â€” dikelompokkan persis seperti menu eProperty BO.
const menu: Array<{ group: string; items: Array<{ label: string; route: string | null; summary?: string | null }> }> = [
    { group: 'General Function', items: [
        { label: 'General Function', route: r('bs.cashbook.printhub') },
    ]},
    { group: 'Setup', items: [
        { label: 'Rekening', route: r('rekening.index') },
        { label: 'Repair Data', route: r('bs.cashbook.repair.index') },
    ]},
    { group: 'Master', items: [
        { label: 'Transfer Rekening', route: r('bs.cashbook.index', { type: 'transfer' }) },
        { label: 'Kas Masuk', route: r('bs.cashbook.index', { type: 'in' }) },
        { label: 'Kas Keluar', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Kas Kecil Masuk', route: r('bs.pettycash.index', { type: 'in' }) },
        { label: 'Kas Kecil Keluar', route: r('bs.pettycash.index', { type: 'out' }) },
        { label: 'Cek Giro Masuk', route: r('bs.giro.index') },
        { label: 'Pengeluaran Kas & Bank', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Pengeluaran Kas & Bank (AP)', route: r('bs.ap.kuitansi.index') },
    ]},
    { group: 'Report/Query', items: [
        { label: 'Rekening Ledger', route: r('bs.cashbook.ledger') },
        { label: 'Rekening Saldo', route: r('bs.cashbook.balances') },
        { label: 'Kalender Cek Giro', route: r('bs.cashbook.giro-calendar') },
        { label: 'Pengeluaran Kas & Bank', route: r('bs.cashbook.index', { type: 'out' }) },
        { label: 'Kartu Bon Sementara', route: r('bs.advance.card') },
        { label: 'Saldo Debit Card', route: r('bs.advance.card-balance') },
        { label: 'Rekap Bon Sementara', route: r('bs.advance.report') },
        { label: 'Outstanding Kasbon', route: r('bs.cashbook.outstanding-kasbon') },
        { label: 'Bon Sementara Masih Outstanding', route: r('bs.advance.report') },
    ]},
    { group: 'Saldo Awal', items: [
        { label: 'Saldo Awal Rekening', route: r('bs.cashbook.opening.index') },
    ]},
    { group: 'Transaksi Pengeluaran Bank', items: [
        { label: 'Registrasi Pengeluaran Kas & Bank', route: r('bs.cashbook.create', { type: 'out' }) },
        { label: 'Registrasi Pengeluaran Kas & Bank (AP)', route: r('bs.ap.kuitansi.create') },
        { label: 'Memo Urgent Belum Invoice', route: r('bs.ap.kuitansi.memo-outstanding') },
        { label: 'Approval Pengeluaran Kas & Bank', route: r('bs.cashbook.approvals') },
    ]},
    { group: 'Transaksi Penerimaan Bank', items: [
        { label: 'Transfer Antar Rekening', route: r('bs.cashbook.create', { type: 'transfer' }) },
        { label: 'Penerimaan Bank', route: r('bs.cashbook.create', { type: 'in' }) },
    ]},
    { group: 'Transaksi Bon Sementara', items: [
        { label: 'Pengajuan Bon Sementara', route: r('bs.advance.create') },
        { label: 'Realisasi Bon Sementara', route: r('bs.advance.index') },
        // Proses Finance dikumpulkan di grupnya sendiri — lihat navGroups.
        { label: 'Bon Sementara Finance', route: r('bs.advance.payments') },
        { label: 'Alokasi Bon Sementara Finance', route: r('bs.advance.allocation.index') },
        { label: 'Realisasi Bon Sementara Finance', route: r('bs.advance.index') },
        { label: 'Transaksi Bon Batal (Riwayat)', route: r('bs.advance.cancelled') },
    ]},
    { group: 'Transaksi Kas Kecil', items: [
        { label: 'Penerimaan Kas Kecil', route: r('bs.pettycash.create', { type: 'in' }) },
        { label: 'Pengeluaran Kas Kecil', route: r('bs.pettycash.create', { type: 'out' }) },
    ]},
    { group: 'Transaksi Cek Giro Masuk', items: [
        { label: 'Penerimaan Cek Giro', route: r('bs.giro.create') },
        { label: 'Kliring Cek Giro Masuk', route: r('bs.giro.index') },
        { label: 'Tolakan Cek Giro Masuk', route: r('bs.giro.index') },
    ]},
];

const totalItems = menu.reduce((n, g) => n + g.items.length, 0);
const builtItems = menu.reduce((n, g) => n + g.items.filter(i => i.route).length, 0);

const open = ref<Record<string, boolean>>({});
function toggle(g: string) { open.value[g] = !open.value[g]; }
</script>

<template>
    <div class="space-y-5">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Dashboard</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> Cash Book</h1>
            <Link :href="route('bs.cashbook.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Transaksi Kas/Bank</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">Modul kas & bank â€” {{ totalItems }} sub-modul ({{ builtItems }} aktif, {{ totalItems - builtItems }} dalam pembangunan). Klik sub-modul untuk melihat detailnya.</p>

        <!-- Ringkasan kas/bank -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Saldo Kas & Bank</div><div class="mt-1 font-mono text-xl font-bold">{{ fmtRp(summary.saldo) }}</div><div class="text-xs text-neutral-500">{{ summary.accounts }} rekening</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Bon Outstanding</div><div class="mt-1 font-mono text-xl font-bold text-amber-600">{{ fmtRp(summary.bon_outstanding) }}</div><div class="text-xs text-neutral-500">{{ summary.bon_count }} bon</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Transaksi Kas</div><div class="mt-1 text-xl font-bold">{{ summary.trx }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Cek/Giro Masuk</div><div class="mt-1 text-xl font-bold">{{ summary.giro }}</div></div>
        </div>

        <!-- Direktori sub-modul -->
        <div class="grid gap-3 md:grid-cols-2">
            <div v-for="g in menu" :key="g.group" class="card overflow-hidden">
                <button type="button" @click="toggle(g.group)" class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                    <span class="text-xs font-semibold uppercase tracking-wider text-accent-600">{{ g.group }}</span>
                    <span class="flex items-center gap-2"><span class="text-xs text-neutral-400">{{ g.items.length }}</span><ChevronDown class="h-4 w-4 text-neutral-400 transition-transform" :class="{ 'rotate-180': open[g.group] }" /></span>
                </button>
                <ul v-show="open[g.group]" class="border-t border-neutral-200 px-4 py-2 dark:border-neutral-800">
                    <li v-for="it in g.items" :key="it.label" class="flex items-center justify-between gap-2 rounded px-1 py-1.5 text-sm">
                        <a v-if="it.route" :href="it.route" class="flex-1 text-primary-700 hover:underline dark:text-primary-300">{{ it.label }}</a>
                        <span v-else class="flex-1 text-neutral-400">{{ it.label }}</span>
                        <span class="flex items-center gap-2"><a v-if="it.summary" :href="it.summary" class="text-xs text-neutral-500 hover:text-primary-600 hover:underline">| Summary</a><Badge v-if="!it.route" variant="neutral" size="sm">segera</Badge><span v-else class="text-primary-400">â†’</span></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

