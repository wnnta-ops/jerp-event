<script setup lang="ts">
// Report Budget — anggaran vs realisasi per cost center, satu blok per cost center.
//
// Angkanya SELURUHNYA datang dari controller (BudgetQueryController::report), yang memakai
// BudgetRealizationService yang sama dengan Budget Control, Realisasi Budget, dan gerbang
// over-budget. Layar ini tidak menghitung ulang apa pun — hanya menyusunnya.
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, PieChart } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';

interface Angka { budget: number; real: number; sisa: number; pct_sisa: number; absorpsi: number | null }
interface Baris extends Angka { code: string; name: string }
interface Blok { key: string; name: string; code: string | null; lintas: boolean; rows: Baris[]; summary: Angka & { akun: number } }

const props = defineProps<{
    filters: { year: number; cost_center_id: number | null; sd_bulan: number };
    sections: Blok[];
    total: Angka & { akun: number; cost_center: number; lintas: number };
    costCenters: Array<{ id: number; code: string; name: string }>;
    monthLabels: string[];
}>();

const year = ref(props.filters.year);
const cc = ref<number | string>(props.filters.cost_center_id ?? '');
const sd = ref<number>(props.filters.sd_bulan);

function apply() {
    router.get(route('bs.budget.query.report'),
        { year: year.value, cost_center_id: cc.value || undefined, sd_bulan: sd.value },
        { preserveState: true, replace: true });
}

/* Rupiah penuh di dalam tabel (angka yang dipakai orang untuk mencocokkan), ringkas di kepala
   blok (yang dibaca sekilas). Negatif memakai kurung, kebiasaan laporan keuangan. */
function rp(v: number | null) {
    const n = Number(v ?? 0);
    const s = 'Rp ' + Math.abs(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });

    return n < 0 ? '(' + s + ')' : s;
}
function ringkas(v: number | null) {
    const n = Number(v ?? 0);
    const abs = Math.abs(n);
    const inti = abs >= 1_000_000_000
        ? (n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + ' M'
        : (abs >= 1_000_000
            ? (n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
            : n.toLocaleString('id-ID', { maximumFractionDigits: 0 }));

    return 'Rp ' + inti;
}
function pct(v: number | null) { return Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }

/* Absorpsi ditulis bulat (98%, 104%) tetapi WARNANYA memakai angka aslinya — supaya 69,6%
   yang tertulis "70%" tidak ikut berpindah pita hanya karena pembulatan tampilan. */
function absorpsiTeks(v: number | null) { return v === null ? '—' : Math.round(v).toLocaleString('id-ID') + '%'; }
function pita(v: number | null) {
    if (v === null) return 'bg-neutral-300 dark:bg-neutral-700';
    if (v > 100) return 'bg-danger-500';
    if (v >= 95) return 'bg-amber-500';
    if (v >= 70) return 'bg-success-500';

    return 'bg-primary-500';
}
function lebar(v: number | null) { return Math.max(0, Math.min(100, Number(v ?? 0))) + '%'; }

const adaLintas = computed(() => props.sections.some((s) => s.lintas));
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><PieChart class="h-6 w-6 text-primary-600" /> Report Budget</h1>
            <p class="mt-1 text-sm text-neutral-500">Anggaran &amp; realisasi per cost center — satu blok per cost center, dirinci per akun.</p>
        </div>

        <div class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs text-neutral-500">Tahun</label><input v-model.number="year" type="number" :class="[inputCls, 'w-28']" /></div>
            <div><label class="mb-1 block text-xs text-neutral-500">Cost Center</label>
                <select v-model="cc" :class="[inputCls, 'w-52']"><option value="">Semua</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div><label class="mb-1 block text-xs text-neutral-500">s/d Bulan</label>
                <select v-model.number="sd" :class="[inputCls, 'w-32']"><option v-for="(lbl, i) in monthLabels" :key="lbl" :value="i + 1">{{ lbl }}</option></select></div>
            <button @click="apply" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
        </div>

        <p v-if="filters.sd_bulan < 12" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            Dipotong s/d <b>{{ monthLabels[filters.sd_bulan - 1] }} {{ filters.year }}</b>: anggaran yang dibandingkan adalah anggaran
            bulan Jan s/d {{ monthLabels[filters.sd_bulan - 1] }}, bukan anggaran setahun penuh — supaya sisanya tidak terlihat
            aman hanya karena tahunnya belum habis.
        </p>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi</div><div class="mt-1 font-mono text-lg font-bold text-primary-600" :title="rp(total.real)">{{ ringkas(total.real) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Budget</div><div class="mt-1 font-mono text-lg font-bold" :title="rp(total.budget)">{{ ringkas(total.budget) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa</div><div class="mt-1 font-mono text-lg font-bold" :class="total.sisa >= 0 ? 'text-success-700 dark:text-success-400' : 'text-danger-600'" :title="rp(total.sisa)">{{ ringkas(total.sisa) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">% Sisa</div><div class="mt-1 font-mono text-lg font-bold" :class="total.pct_sisa >= 0 ? 'text-success-700 dark:text-success-400' : 'text-danger-600'">{{ pct(total.pct_sisa) }}</div></div>
        </div>

        <p v-if="sections.length" class="text-xs text-neutral-500">
            Mencakup <b>{{ total.cost_center }} blok</b> dan <b>{{ total.akun }} akun</b>.
            Realisasi dihitung dari jurnal berstatus Baru, Final &amp; Approved tahun {{ filters.year }} — sama dengan
            angka yang dipakai <b>Budget Control</b> dan gerbang over-budget, jadi tidak perlu diunggah terpisah.
        </p>

        <div v-for="s in sections" :key="s.key" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
                <h2 class="text-sm font-bold uppercase tracking-wide text-primary-700 dark:text-primary-300">
                    {{ s.name }}
                    <span v-if="s.code" class="ml-1 font-normal normal-case text-neutral-400">{{ s.code }}</span>
                    <span class="ml-2 font-normal normal-case text-neutral-400">{{ s.summary.akun }} akun</span>
                </h2>
                <div class="flex flex-wrap items-end gap-5 text-right">
                    <div><div class="text-[10px] uppercase text-neutral-400">Realisasi</div><div class="font-mono text-xs font-bold text-primary-600" :title="rp(s.summary.real)">{{ ringkas(s.summary.real) }}</div></div>
                    <div><div class="text-[10px] uppercase text-neutral-400">Budget</div><div class="font-mono text-xs font-bold" :title="rp(s.summary.budget)">{{ ringkas(s.summary.budget) }}</div></div>
                    <div><div class="text-[10px] uppercase text-neutral-400">Sisa</div><div class="font-mono text-xs font-bold" :class="s.summary.sisa >= 0 ? 'text-success-700 dark:text-success-400' : 'text-danger-600'" :title="rp(s.summary.sisa)">{{ ringkas(s.summary.sisa) }}</div></div>
                    <div><div class="text-[10px] uppercase text-neutral-400">% Sisa</div><div class="font-mono text-xs font-bold" :class="s.summary.pct_sisa >= 0 ? 'text-success-700 dark:text-success-400' : 'text-danger-600'">{{ pct(s.summary.pct_sisa) }}</div></div>
                </div>
            </div>

            <div class="mt-3 flex items-center gap-3 px-4">
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                    <div class="h-full rounded-full" :class="pita(s.summary.absorpsi)" :style="{ width: lebar(s.summary.absorpsi) }"></div>
                </div>
                <span class="shrink-0 text-[11px] text-neutral-500">{{ absorpsiTeks(s.summary.absorpsi) }} terpakai</span>
            </div>

            <p v-if="s.lintas" class="mx-4 mt-3 rounded-lg bg-neutral-100 px-3 py-2 text-[11px] leading-relaxed text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                Anggaran di blok ini dibuat <b>tanpa cost center</b>, artinya plafonnya berlaku lintas cost center — realisasinya
                pun dihitung lintas cost center. Karena angkanya sudah termasuk di dalam blok-blok di atas,
                blok ini <b>tidak ikut dijumlahkan</b> ke total keseluruhan.
            </p>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left uppercase text-neutral-500">
                            <th class="px-4 py-2.5">Kode</th>
                            <th class="px-4 py-2.5">Nama Account</th>
                            <th class="px-4 py-2.5 text-right">Realisasi</th>
                            <th class="px-4 py-2.5 text-right">Budget</th>
                            <th class="px-4 py-2.5 text-right">Sisa/(Over)</th>
                            <th class="px-4 py-2.5 text-right">% Sisa</th>
                            <th class="px-4 py-2.5">Absorpsi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in s.rows" :key="r.code" :class="r.sisa < 0 ? 'bg-danger-50/60 dark:bg-danger-950/20' : ''">
                            <td class="px-4 py-2 font-mono text-neutral-500">{{ r.code }}</td>
                            <td class="px-4 py-2 text-primary-700 dark:text-primary-300">{{ r.name }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rp(r.real) }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ rp(r.budget) }}</td>
                            <td class="px-4 py-2 text-right font-mono" :class="r.sisa < 0 ? 'text-danger-600 dark:text-danger-400' : ''">{{ rp(r.sisa) }}</td>
                            <td class="px-4 py-2 text-right font-mono" :class="r.pct_sisa < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-700 dark:text-success-400'">{{ pct(r.pct_sisa) }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                                        <div class="h-full rounded-full" :class="pita(r.absorpsi)" :style="{ width: lebar(r.absorpsi) }"></div>
                                    </div>
                                    <span class="font-mono text-[11px] text-neutral-500">{{ absorpsiTeks(r.absorpsi) }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p v-if="adaLintas" class="text-[11px] text-neutral-500">
            Total keseluruhan di atas menjumlahkan <b>{{ total.cost_center }}</b> blok cost center dan sengaja melewati
            <b>{{ total.lintas }}</b> blok lintas cost center, supaya realisasi yang sama tidak terhitung dua kali.
        </p>

        <EmptyState v-if="!sections.length" title="Tidak ada data"
            :description="'Belum ada anggaran yang disetujui maupun realisasi jurnal untuk tahun ' + filters.year + ' pada filter ini.'" />
    </div>
</template>
