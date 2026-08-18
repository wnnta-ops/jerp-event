<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookText, Plus, ChevronDown } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    source: string;
    sources: Record<string, string>;
    companies: Array<{ id: number | string; name: string }>;
    periods: Array<{ month: number; baru: number; final: number; approved: number; closed: number; isClosed: boolean; total: number; debit: number; status: string }>;
    current: { year: number; month: number };
    totals: { journals: number; accounts: number };
}>();

const yearInput = ref(props.year);
const sourceInput = ref(props.source ?? '');
function display() {
    router.get(route('bs.gl.index'), { year: yearInput.value, source: sourceInput.value || undefined }, { preserveState: true, replace: true });
}
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
const monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

function closePeriod(m: number) { if (!confirm(`Tutup periode ${props.year}/${m}? Jurnal tidak bisa ditambah setelah ditutup.`)) return; router.post(route('bs.gl.period.close'), { year: props.year, month: m }, { preserveScroll: true }); }
function openPeriod(m: number) { if (!confirm(`Buka kembali periode ${props.year}/${m}?`)) return; router.post(route('bs.gl.period.open'), { year: props.year, month: m }, { preserveScroll: true }); }

// route() melempar bila nama tak dikenal â€” bungkus aman; null = belum dibangun ("segera").
function r(name?: string | null, params?: Record<string, any>): string | null {
    if (!name) return null;
    try { return route(name, params); } catch { return null; }
}

// 35 sub-modul GL â€” dikelompokkan persis seperti menu eProperty BO.
const menu: Array<{ group: string; items: Array<{ label: string; route: string | null; summary?: string | null }> }> = [
    { group: 'General Function', items: [
        { label: 'General Function', route: r('bs.gl.printhub') },
    ]},
    { group: 'Setup', items: [
        { label: 'Multi Currency', route: r('bs.gl.currency.index') },
        { label: 'Kurs Sistem', route: r('bs.gl.kurs.index') },
        { label: 'Repair Data', route: r('bs.gl.repair.index') },
        { label: 'Kategori Cash Flow Direct', route: r('bs.gl.cf-category.index', { kind: 'direct' }) },
        { label: 'Kategori Cash Flow Indirect', route: r('bs.gl.cf-category.index', { kind: 'indirect' }) },
    ]},
    { group: 'Master', items: [
        { label: 'Chart of Account', route: r('bs.accounts.index') },
        { label: 'Jurnal', route: r('bs.gl.journal.index') },
        { label: 'Cost Center', route: r('bs.cost-centers.index') },
        { label: 'Mapping Cash Flow Direct', route: r('bs.gl.cf-mapping.index') },
        { label: 'Dimensi Jurnal (Dept/Lokasi)', route: r('bs.journal-dimensions.index') },
    ]},
    { group: 'Query', items: [
        { label: 'Detail Transaksi Jurnal', route: r('bs.gl.journal.index') },
        { label: 'General Ledger (Buku Besar)', route: r('bs.gl.ledger') },
    ]},
    { group: 'Neraca', items: [
        { label: 'Neraca Saldo (Trial Balance)', route: r('bs.gl.trialbalance') },
        { label: 'Neraca (Balance Sheet)', route: r('bs.gl.balancesheet') },
        { label: 'Laba Rugi (Income Statement)', route: r('bs.gl.incomestatement') },
        { label: 'Neraca Lajur (Worksheet)', route: r('bs.gl.worksheet') },
        { label: 'Histori Saldo', route: r('bs.gl.extra', { mode: 'histori' }) },
        { label: 'Neraca | Multi Periode', route: r('bs.gl.extra', { mode: 'multi' }) },
        { label: 'Neraca Komparasi (Bulanan)', route: r('bs.gl.comparative') },
        { label: 'Neraca Komparasi (Tahunan)', route: r('bs.gl.extra', { mode: 'tahunan' }) },
    ]},
    { group: 'Laba Rugi', items: [
        { label: 'Laba Rugi vs Budget', route: r('bs.gl.lr-budget') },
        { label: 'Laba Rugi | Multi Periode', route: r('bs.gl.extra', { mode: 'lr-multi' }) },
        { label: 'Laba Rugi Komparasi (Bulanan)', route: r('bs.gl.comparative') },
        { label: 'Laba Rugi Komparasi (Tahunan)', route: r('bs.gl.extra', { mode: 'lr-tahunan' }) },
        { label: 'Laba Rugi Perbandingan', route: r('bs.gl.extra', { mode: 'lr-banding' }) },
    ]},
    { group: 'Cash Flow Direct', items: [
        { label: 'Cash Flow Direct | Multi Periode', route: r('bs.gl.cashflow', { method: 'direct' }) },
    ]},
    { group: 'Cash Flow Indirect', items: [
        { label: 'Cash Flow Indirect | Multi Periode', route: r('bs.gl.cashflow', { method: 'indirect' }) },
    ]},
    { group: 'Saldo Awal', items: [
        { label: 'Input Saldo Awal', route: r('bs.closing.saldoawal') },
        { label: 'Confirm Saldo Awal', route: r('bs.gl.saldo-awal.confirm') },
    ]},
    { group: 'Jurnal Memorial', items: [
        { label: 'Registrasi Jurnal Memorial', route: r('bs.gl.journal.create') },
        { label: 'Import Jurnal', route: r('bs.gl.journal.import') },
    ]},
    { group: 'Generate Laporan Keuangan', items: [
        { label: 'Finalisasi Jurnal', route: r('bs.gl.journal.index', { stage: 'finalisasi' }) },
        { label: 'Approval Jurnal', route: r('bs.gl.journal.index', { stage: 'approval' }) },
        { label: 'Late Closing', route: r('bs.gl.late-closing') },
    ]},
    { group: 'Generate Laporan Keuangan Perusahaan', items: [
        { label: 'Closing Fiskal', route: r('bs.closing.index') },
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
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookText class="h-6 w-6 text-primary-600" /> General Ledger</h1>
            <Link :href="route('bs.gl.journal.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Jurnal Memorial</Button></Link>
        </div>
        <p class="text-sm text-neutral-500">
            Modul buku besar double-entry â€” {{ totalItems }} sub-modul ({{ builtItems }} aktif, {{ totalItems - builtItems }} dalam pembangunan).
            {{ totals.journals }} jurnal Â· {{ totals.accounts }} akun. Klik sub-modul untuk melihat detailnya.
        </p>

        <div class="grid gap-5 lg:grid-cols-5">
            <!-- Direktori 35 sub-modul -->
            <div class="space-y-3 lg:col-span-2">
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

            <!-- Periode Fiskal (board status seperti eProperty) -->
            <div class="lg:col-span-3">
                <div class="card p-5">
                    <div class="mb-1 text-sm text-neutral-500">Periode Fiskal :</div>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div class="text-3xl font-bold">{{ year }}/{{ current.year === year ? current.month : 1 }}</div>
                        <div class="flex flex-wrap items-end gap-2">
                            <div>
                                <label class="mb-1 block text-[10px] uppercase text-neutral-400">Tahun</label>
                                <input v-model.number="yearInput" type="number" class="h-9 w-24 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                            </div>
                            <button @click="display" class="h-9 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700">Display</button>
                        </div>
                    </div>
                    <div class="mb-3 grid gap-2 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[10px] uppercase text-neutral-400">Sumber</label>
                            <select v-model="sourceInput" @change="display" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">â€” Semua Sumber â€”</option>
                                <option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] uppercase text-neutral-400">Perusahaan</label>
                            <select class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-3 py-2">Tahun</th><th class="px-3 py-2">Bulan</th>
                            <th class="px-3 py-2 text-right">Baru</th><th class="px-3 py-2 text-right">Final</th>
                            <th class="px-3 py-2 text-right">Approved</th><th class="px-3 py-2 text-right">Closed</th>
                            <th class="px-3 py-2 text-right">Aksi</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="p in periods" :key="p.month" :class="{ 'bg-amber-100 dark:bg-amber-900/30': p.month === current.month && year === current.year }">
                                <td class="px-3 py-2">{{ year }}</td>
                                <td class="px-3 py-2 font-medium">{{ p.month }} <span class="text-xs text-neutral-400">{{ monthNames[p.month] }}</span></td>
                                <td class="px-3 py-2 text-right font-mono" :class="p.baru ? 'text-sky-600' : 'text-neutral-300'">{{ p.baru || '' }}</td>
                                <td class="px-3 py-2 text-right font-mono" :class="p.final ? 'text-amber-600' : 'text-neutral-300'">{{ p.final || '' }}</td>
                                <td class="px-3 py-2 text-right font-mono" :class="p.approved ? 'text-primary-600' : 'text-neutral-300'">{{ p.approved || '' }}</td>
                                <td class="px-3 py-2 text-right font-mono" :class="p.closed ? 'font-semibold text-neutral-700 dark:text-neutral-200' : 'text-neutral-300'">{{ p.closed || '' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button v-if="!p.isClosed" @click="closePeriod(p.month)" class="text-xs text-danger-500 hover:underline">Tutup</button>
                                    <button v-else @click="openPeriod(p.month)" class="text-xs text-primary-600 hover:underline">Buka</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-[11px] text-neutral-400">Baru = jurnal draft Â· Final = jurnal final Â· Approved = jurnal posted (efektif di GL) Â· Closed = total jurnal pada periode yang sudah ditutup.</p>
                </div>
            </div>
        </div>
    </div>
</template>

