<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

type Line = { name: string; amount: number };
type Group = { name: string; code: string; total: number; lines: Line[] };
const props = defineProps<{
    company: string;
    as_of: string;
    // Neraca menyatakan posisi pada SATU tanggal, jadi tanggalnya yang dipilih.
    as_of_date: string;
    assets: Group[];
    liabilities: Group[];
    equity: Group[];
    total_assets: number;
    total_passiva: number;
}>();

function fmt(v: number) {
    const n = Number(v ?? 0);
    const s = Math.abs(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `(${s})` : s;
}
const balanced = computed(() => Math.abs(props.total_assets - props.total_passiva) < 1);

const asOf = ref(props.as_of_date);
function display() { router.get(route('bs.gl.balancesheet'), { as_of: asOf.value }, { preserveState: true, replace: true, preserveScroll: true }); }
function excel() { window.location.href = route('bs.gl.balancesheet.export', { as_of: asOf.value }); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> Neraca</h1>
                <p class="mt-0.5 text-sm text-neutral-500">{{ company }} &middot; Per {{ as_of }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Per Tanggal</label>
                    <input v-model="asOf" type="date" @change="display" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
                <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
                <Badge :variant="balanced ? 'success' : 'danger'" dot>{{ balanced ? 'Balance' : 'Tidak Balance' }}</Badge>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- ================= ASET (kiri) ================= -->
            <section class="card flex flex-col overflow-hidden">
                <header class="bg-primary-600 px-4 py-2.5 text-sm font-bold uppercase tracking-wide text-white">Aset</header>
                <div class="flex-1 divide-y divide-neutral-100 dark:divide-neutral-800">
                    <div v-for="g in assets" :key="g.code" class="px-4 py-3">
                        <div class="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">{{ g.name }}</div>
                        <div v-for="(l, i) in g.lines" :key="i" class="flex justify-between gap-4 py-1 text-sm">
                            <span class="pl-3 text-neutral-700 dark:text-neutral-300">{{ l.name }}</span>
                            <span class="shrink-0 font-mono tabular-nums" :class="l.amount < 0 ? 'text-danger-600' : ''">{{ fmt(l.amount) }}</span>
                        </div>
                        <div class="mt-1 flex justify-between gap-4 border-t border-neutral-200 pt-1.5 text-sm font-semibold dark:border-neutral-700">
                            <span>Jumlah {{ g.name }}</span>
                            <span class="shrink-0 font-mono tabular-nums">{{ fmt(g.total) }}</span>
                        </div>
                    </div>
                    <p v-if="!assets.length" class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada saldo aset.</p>
                </div>
                <footer class="flex justify-between gap-4 bg-primary-50 px-4 py-3 text-base font-bold text-primary-800 dark:bg-primary-900/20 dark:text-primary-200">
                    <span>JUMLAH AKTIVA</span>
                    <span class="shrink-0 font-mono tabular-nums">{{ fmt(total_assets) }}</span>
                </footer>
            </section>

            <!-- ============ KEWAJIBAN & EKUITAS (kanan) ============ -->
            <section class="card flex flex-col overflow-hidden">
                <header class="bg-primary-600 px-4 py-2.5 text-sm font-bold uppercase tracking-wide text-white">Kewajiban &amp; Ekuitas</header>
                <div class="flex-1">
                    <div class="bg-neutral-50 px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-neutral-400 dark:bg-neutral-800/50">Kewajiban</div>
                    <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <div v-for="g in liabilities" :key="g.code" class="px-4 py-3">
                            <div class="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">{{ g.name }}</div>
                            <div v-for="(l, i) in g.lines" :key="i" class="flex justify-between gap-4 py-1 text-sm">
                                <span class="pl-3 text-neutral-700 dark:text-neutral-300">{{ l.name }}</span>
                                <span class="shrink-0 font-mono tabular-nums" :class="l.amount < 0 ? 'text-danger-600' : ''">{{ fmt(l.amount) }}</span>
                            </div>
                            <div class="mt-1 flex justify-between gap-4 border-t border-neutral-200 pt-1.5 text-sm font-semibold dark:border-neutral-700">
                                <span>Jumlah {{ g.name }}</span>
                                <span class="shrink-0 font-mono tabular-nums">{{ fmt(g.total) }}</span>
                            </div>
                        </div>
                        <p v-if="!liabilities.length" class="px-4 py-4 text-center text-sm text-neutral-400">Tidak ada kewajiban.</p>
                    </div>
                    <div class="bg-neutral-50 px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-neutral-400 dark:bg-neutral-800/50">Modal</div>
                    <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <div v-for="g in equity" :key="g.code" class="px-4 py-3">
                            <div class="mb-1 text-xs font-bold uppercase tracking-wide text-neutral-500">{{ g.name }}</div>
                            <div v-for="(l, i) in g.lines" :key="i" class="flex justify-between gap-4 py-1 text-sm">
                                <span class="pl-3 text-neutral-700 dark:text-neutral-300">{{ l.name }}</span>
                                <span class="shrink-0 font-mono tabular-nums" :class="l.amount < 0 ? 'text-danger-600' : ''">{{ fmt(l.amount) }}</span>
                            </div>
                            <div class="mt-1 flex justify-between gap-4 border-t border-neutral-200 pt-1.5 text-sm font-semibold dark:border-neutral-700">
                                <span>Jumlah {{ g.name }}</span>
                                <span class="shrink-0 font-mono tabular-nums">{{ fmt(g.total) }}</span>
                            </div>
                        </div>
                        <p v-if="!equity.length" class="px-4 py-4 text-center text-sm text-neutral-400">Tidak ada modal.</p>
                    </div>
                </div>
                <footer class="flex justify-between gap-4 bg-primary-50 px-4 py-3 text-base font-bold text-primary-800 dark:bg-primary-900/20 dark:text-primary-200">
                    <span>JUMLAH PASSIVA</span>
                    <span class="shrink-0 font-mono tabular-nums">{{ fmt(total_passiva) }}</span>
                </footer>
            </section>
        </div>

        <p v-if="!balanced" class="text-center text-sm text-danger-600">Selisih Aktiva vs Passiva: {{ fmt(total_assets - total_passiva) }} — periksa jurnal.</p>
    </div>
</template>
