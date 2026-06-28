<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeft, FileBarChart, Send, Clock, RotateCcw, HandCoins, FileSpreadsheet, Printer, Building2, Filter } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Bucket = { label: string; desc: string; rows: Array<any>; count: number; total: number };
type Department = { id: number; code: string | null; name: string };
const props = defineProps<{
    buckets: Record<string, Bucket>;
    departments: Department[];
    filters: { department: number | null };
}>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const meta: Record<string, { icon: any; color: string; settlement?: boolean }> = {
    sudah_transfer:  { icon: Send, color: 'text-amber-600' },
    belum_transfer:  { icon: Clock, color: 'text-neutral-500' },
    belum_refund:    { icon: RotateCcw, color: 'text-success-600', settlement: true },
    belum_reimburse: { icon: HandCoins, color: 'text-danger-600', settlement: true },
};

// #150: filter departemen — navigasi server-side (juga dipakai tombol Excel/PDF).
const department = ref<number | ''>(props.filters.department ?? '');
function applyFilter() {
    // Reset filter kolom client-side dulu — pemegang/keperluan antar departemen berbeda, agar tidak nyangkut → kosong.
    resetFilters();
    router.get(route('bs.advance.report'), { department: department.value || undefined }, { preserveState: true, replace: true });
}
const deptName = computed(() => props.departments.find((d) => d.id === Number(props.filters.department))?.name ?? null);

// ── Filter per-kolom client-side (Pemegang, Keperluan) + periode tanggal bon — ringkasan ikut ter-update ──
const fHolder = ref<string[]>([]);
const fPurpose = ref<string[]>([]);
const from = ref('');
const to = ref('');
const allRows = computed(() => Object.values(props.buckets).flatMap((b) => b.rows));
const uniq = (arr: (string | null)[]) => [...new Set(arr.map((v) => v ?? ''))].sort((a, b) => a.localeCompare(b));
const holderOptions = computed(() => uniq(allRows.value.map((r) => r.holder)));
const purposeOptions = computed(() => uniq(allRows.value.map((r) => r.purpose)));
const pass = (val: string, sel: string[]) => sel.length === 0 || sel.includes(val);
const anyFilter = computed(() => fHolder.value.length || fPurpose.value.length || from.value || to.value);
function resetFilters() { fHolder.value = []; fPurpose.value = []; from.value = ''; to.value = ''; }

// #175: parameter unduhan = SEMUA filter aktif (departemen + nama pemegang + keperluan + rentang tgl)
// agar Excel/PDF yang terdownload "cukup" data sesuai yang difilter di layar (mis. hanya Andi Wijaya).
const exportParams = computed<Record<string, any>>(() => {
    const p: Record<string, any> = {};
    if (props.filters.department) p.department = props.filters.department;
    if (fHolder.value.length) p.holders = fHolder.value;
    if (fPurpose.value.length) p.purposes = fPurpose.value;
    if (from.value) p.from = from.value;
    if (to.value) p.to = to.value;
    return p;
});

const filteredBuckets = computed<Record<string, Bucket>>(() => {
    const out: Record<string, Bucket> = {};
    for (const [key, b] of Object.entries(props.buckets)) {
        const rows = b.rows.filter((r) =>
            pass(r.holder ?? '', fHolder.value)
            && pass(r.purpose ?? '', fPurpose.value)
            && (!from.value || (r.advance_date ?? '') >= from.value)
            && (!to.value || (r.advance_date ?? '') <= to.value));
        const total = meta[key]?.settlement
            ? rows.reduce((s, r) => s + Math.abs(Number(r.settlement ?? 0)), 0)
            : rows.reduce((s, r) => s + Number(r.amount ?? 0), 0);
        out[key] = { ...b, rows, count: rows.length, total };
    }
    return out;
});
const grandTotal = computed(() => Object.values(filteredBuckets.value).reduce((s, b) => s + Number(b.total ?? 0), 0));
const grandCount = computed(() => Object.values(filteredBuckets.value).reduce((s, b) => s + Number(b.count ?? 0), 0));
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.advance.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Bon Sementara</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileBarChart class="h-6 w-6 text-primary-600" /> Rekap Bon Sementara</h1>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <Building2 class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <select v-model="department" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option value="">Semua departemen</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code ? d.code + ' — ' + d.name : d.name }}</option>
                    </select>
                </div>
                <a :href="route('bs.advance.report.excel', exportParams)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.advance.report.pdf', exportParams)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> PDF</a>
            </div>
        </div>

        <!-- Filter per-kolom + periode (mempengaruhi ringkasan & daftar di bawah) -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex items-center gap-1.5 text-xs font-medium text-neutral-500"><Filter class="h-4 w-4" /> Filter</div>
            <div class="min-w-[180px]"><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Pemegang</label><ColumnFilter v-model="fHolder" :options="holderOptions" placeholder="Semua pemegang" /></div>
            <div class="min-w-[180px]"><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Keperluan</label><ColumnFilter v-model="fPurpose" :options="purposeOptions" placeholder="Semua keperluan" /></div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Tgl Bon — dari</label><input v-model="from" type="date" class="h-7 rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">s/d</label><input v-model="to" type="date" class="h-7 rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <button v-if="anyFilter" type="button" @click="resetFilters" class="ml-auto rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Reset filter</button>
        </div>

        <!-- ringkasan total (departemen / filter terpilih) -->
        <div v-if="filters.department || anyFilter" class="card flex flex-wrap items-center justify-between gap-2 border-primary-200 bg-primary-50/60 p-4 dark:border-primary-900/50 dark:bg-primary-900/10">
            <div class="flex items-center gap-2 text-sm"><Building2 class="h-4 w-4 text-primary-600" /> <span v-if="filters.department">Departemen: <span class="font-semibold">{{ deptName ?? '—' }}</span></span><span v-else class="text-neutral-500">Hasil terfilter</span></div>
            <div class="text-sm">Total {{ grandCount }} bon · <span class="font-mono font-bold text-primary-600">{{ fmtRp(grandTotal) }}</span></div>
        </div>

        <!-- ringkasan 4 bucket (reaktif terhadap filter) -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="(b, key) in filteredBuckets" :key="key" class="card p-4">
                <div class="flex items-center gap-2 text-xs uppercase text-neutral-400"><component :is="meta[key]?.icon" class="h-4 w-4" :class="meta[key]?.color" /> {{ b.label }}</div>
                <div class="mt-1 text-xl font-bold" :class="meta[key]?.color">{{ fmtRp(b.total) }}</div>
                <div class="text-xs text-neutral-400">{{ b.count }} bon</div>
            </div>
        </div>

        <!-- daftar per bucket -->
        <div v-for="(b, key) in filteredBuckets" :key="key" class="card overflow-hidden">
            <div class="flex items-center gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <component :is="meta[key]?.icon" class="h-4 w-4" :class="meta[key]?.color" />
                <div><div class="font-semibold">{{ b.label }}</div><div class="text-xs text-neutral-400">{{ b.desc }}</div></div>
                <div class="ml-auto font-mono font-bold" :class="meta[key]?.color">{{ fmtRp(b.total) }}</div>
            </div>
            <table v-if="b.rows.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">No.</th><th class="px-4 py-2.5">Tanggal</th><th class="px-4 py-2.5">Pemegang</th><th class="px-4 py-2.5">Departemen</th><th class="px-4 py-2.5">Keperluan</th><th class="px-4 py-2.5 text-right">{{ meta[key]?.settlement ? 'Sisa/Kurang' : 'Nilai' }}</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in b.rows" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="$inertia.visit(route('bs.advance.show', r.id))">
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ r.advance_number }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(meta[key]?.settlement ? r.realized_date : (key === 'sudah_transfer' ? r.paid_date : r.advance_date)) }}</td>
                        <td class="px-4 py-2.5">{{ r.holder }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.department ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(meta[key]?.settlement ? Math.abs(r.settlement) : r.amount) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else :title="anyFilter ? 'Tidak ada data sesuai filter' : 'Tidak ada data'" description="Tidak ada bon pada kategori ini." />
        </div>
    </div>
</template>
