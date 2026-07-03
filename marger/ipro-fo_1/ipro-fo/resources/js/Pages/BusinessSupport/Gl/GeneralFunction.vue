<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock } from 'lucide-vue-next';

const props = defineProps<{
    year: number;
    source: string;
    sources: Record<string, string>;
    companies: Array<{ id: number | string; name: string }>;
    periods: Array<{ month: number; baru: number; final: number; approved: number; closed: number; isClosed: boolean; frontier: boolean; debit: number }>;
    totals: { baru: number; final: number; approved: number; closed: number };
    closing: { year: number; month: number };
    current: { year: number; month: number };
}>();

const yearInput = ref(props.year);
const sourceInput = ref(props.source ?? '');
const companyInput = ref(props.companies[0]?.id ?? '');
function display() {
    router.get(route('bs.gl.printhub'), { year: yearInput.value, source: sourceInput.value || undefined }, { preserveState: true, replace: true, preserveScroll: true });
}
function fmtInt(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
const monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const years = Array.from(new Set([props.year, ...Array.from({ length: 7 }, (_, i) => new Date().getFullYear() + 1 - i)])).sort((a, b) => b - a);

const stats = [
    { key: 'baru', label: 'Jurnal Baru', hint: 'draft', cls: 'text-sky-600', ring: 'bg-sky-50 dark:bg-sky-900/20' },
    { key: 'final', label: 'Jurnal Final', hint: 'difinalisasi', cls: 'text-amber-600', ring: 'bg-amber-50 dark:bg-amber-900/20' },
    { key: 'approved', label: 'Jurnal Approved', hint: 'posted / efektif GL', cls: 'text-emerald-600', ring: 'bg-emerald-50 dark:bg-emerald-900/20' },
    { key: 'closed', label: 'Jurnal Closed', hint: 'periode ditutup', cls: 'text-slate-700 dark:text-slate-200', ring: 'bg-slate-100 dark:bg-slate-800/40' },
] as const;
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-primary-600" /> General Function</h1>

        <!-- Periode fiskal + filter -->
        <div class="card flex flex-wrap items-end justify-between gap-4 p-5">
            <div>
                <div class="text-xs uppercase tracking-wide text-neutral-400">Periode Fiskal (sudah closing s.d.)</div>
                <div class="mt-1 text-3xl font-bold tabular-nums">
                    <template v-if="closing.month > 0">{{ closing.year }} <span class="text-neutral-400">/</span> {{ closing.month }}
                        <span class="ml-1 align-middle text-base font-medium text-amber-600">{{ monthNames[closing.month] }}</span>
                    </template>
                    <span v-else class="text-lg font-medium text-neutral-400">Belum ada closing</span>
                </div>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Tahun</label>
                    <select v-model.number="yearInput" @change="display" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Sumber</label>
                    <select v-model="sourceInput" @change="display" class="h-9 w-48 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="">— Semua Sumber —</option>
                        <option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Perusahaan</label>
                    <select v-model="companyInput" class="h-9 w-56 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <button @click="display" class="h-9 rounded-lg bg-emerald-600 px-5 text-sm font-semibold text-white hover:bg-emerald-700">Display</button>
            </div>
        </div>

        <!-- Ringkasan jumlah jurnal per status -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="s in stats" :key="s.key" class="card flex items-center justify-between p-4" :class="s.ring">
                <div>
                    <div class="text-xs font-medium text-neutral-500">{{ s.label }}</div>
                    <div class="text-[11px] text-neutral-400">{{ s.hint }}</div>
                </div>
                <div class="text-2xl font-bold tabular-nums" :class="s.cls">{{ fmtInt(totals[s.key]) }}</div>
            </div>
        </div>

        <!-- Papan per bulan -->
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">Tahun</th><th class="px-4 py-2.5">Bulan</th>
                    <th class="px-4 py-2.5 text-right">Baru</th><th class="px-4 py-2.5 text-right">Final</th>
                    <th class="px-4 py-2.5 text-right">Approved</th><th class="px-4 py-2.5 text-right">Closed</th>
                    <th class="px-4 py-2.5"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="p in periods" :key="p.month" class="cursor-default transition-colors" :class="p.frontier ? 'bg-amber-400/80 font-semibold text-neutral-900 hover:bg-amber-500' : (p.isClosed ? 'bg-neutral-50/60 hover:bg-primary-50 dark:bg-neutral-800/30 dark:hover:bg-primary-900/20' : 'hover:bg-primary-50 dark:hover:bg-primary-900/20')">
                        <td class="px-4 py-2 tabular-nums">{{ year }}</td>
                        <td class="px-4 py-2"><span class="font-medium tabular-nums">{{ p.month }}</span> <span class="text-xs" :class="p.frontier ? 'text-neutral-800' : 'text-neutral-400'">{{ monthNames[p.month] }}</span></td>
                        <td class="px-4 py-2 text-right font-mono" :class="p.baru ? 'text-sky-600' : 'text-neutral-300 dark:text-neutral-600'">{{ p.baru || '' }}</td>
                        <td class="px-4 py-2 text-right font-mono" :class="p.final ? 'text-amber-600' : 'text-neutral-300 dark:text-neutral-600'">{{ p.final || '' }}</td>
                        <td class="px-4 py-2 text-right font-mono" :class="p.approved ? 'text-emerald-600' : 'text-neutral-300 dark:text-neutral-600'">{{ p.approved || '' }}</td>
                        <td class="px-4 py-2 text-right font-mono" :class="p.closed ? 'font-semibold' : 'text-neutral-300 dark:text-neutral-600'">{{ p.closed || '' }}</td>
                        <td class="px-4 py-2 text-right"><span v-if="p.frontier" class="rounded-full bg-white/70 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700">batas closing</span></td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t border-neutral-100 px-4 py-2.5 text-[11px] text-neutral-400 dark:border-neutral-800">
                Baru = jurnal draft · Final = jurnal final · Approved = jurnal posted (efektif di GL) · Closed = total jurnal pada periode yang sudah ditutup. Baris <span class="font-semibold text-amber-600">oranye</span> = batas closing terakhir.
            </p>
        </div>
    </div>
</template>
