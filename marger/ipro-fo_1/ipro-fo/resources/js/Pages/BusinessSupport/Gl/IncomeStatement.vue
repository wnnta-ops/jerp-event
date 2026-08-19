<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, TrendingUp, FileSpreadsheet } from 'lucide-vue-next';

type Row = { kind: string; label: string; amount: number | null };
const props = defineProps<{
    company: string;
    as_of: string;
    // Laba rugi menyatakan hasil SELAMA satu periode, jadi rentangnya yang dipilih.
    periode: string;
    date_from: string;
    date_to: string;
    rows: Row[];
    net_profit: number;
}>();

const dari = ref(props.date_from);
const sampai = ref(props.date_to);
function display() { router.get(route('bs.gl.incomestatement'), { date_from: dari.value, date_to: sampai.value }, { preserveState: true, replace: true, preserveScroll: true }); }
function excel() { window.location.href = route('bs.gl.incomestatement.export', { date_from: dari.value, date_to: sampai.value }); }

function fmt(v: number | null) {
    const n = Number(v ?? 0);
    const s = Math.abs(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `(${s})` : s;
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><TrendingUp class="h-6 w-6 text-success-600" /> Laporan Laba Rugi</h1>
                <p class="mt-0.5 text-sm text-neutral-500">{{ company }} &middot; Periode {{ periode }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Dari Tanggal</label>
                    <input v-model="dari" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Sampai Tanggal</label>
                    <input v-model="sampai" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
                <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
            </div>
        </div>

        <div class="card mx-auto w-full max-w-3xl p-5 sm:p-7">
            <template v-for="(r, i) in rows" :key="i">
                <!-- Header kategori -->
                <div v-if="r.kind === 'header'" class="mt-5 border-b-2 border-neutral-300 pb-1 text-sm font-bold uppercase tracking-wide text-neutral-800 first:mt-0 dark:border-neutral-600 dark:text-neutral-100">{{ r.label }}</div>

                <!-- Sub-grup -->
                <div v-else-if="r.kind === 'group'" class="mt-2 pl-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ r.label }}</div>

                <!-- Baris akun -->
                <div v-else-if="r.kind === 'line'" class="flex justify-between gap-4 py-1 text-sm">
                    <span class="pl-6 text-neutral-700 dark:text-neutral-300">{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : 'text-neutral-800 dark:text-neutral-200'">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Sub-total per grup -->
                <div v-else-if="r.kind === 'subtotal'" class="flex justify-between gap-4 border-t border-neutral-200 py-1.5 text-sm font-semibold dark:border-neutral-700">
                    <span class="pl-2">{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : ''">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Sub-total gabungan -->
                <div v-else-if="r.kind === 'subtotal2'" class="flex justify-between gap-4 border-t-2 border-neutral-300 py-1.5 text-sm font-bold dark:border-neutral-600">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : ''">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Baris penegasan (Gross, EBITDA) -->
                <div v-else-if="r.kind === 'emphasis'" class="my-2 flex justify-between gap-4 rounded-md bg-neutral-100 px-3 py-2 text-sm font-bold dark:bg-neutral-800">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : 'text-neutral-900 dark:text-white'">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Laba Rugi Netto -->
                <div v-else-if="r.kind === 'total'" class="mt-3 flex justify-between gap-4 rounded-lg px-4 py-3 text-base font-bold text-white" :class="(r.amount ?? 0) < 0 ? 'bg-danger-600' : 'bg-primary-600'">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums">{{ fmt(r.amount) }}</span>
                </div>
            </template>
        </div>
    </div>
</template>
