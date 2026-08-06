<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BarChart3, Save, Info, ChevronDown, ChevronRight, TriangleAlert, FileSpreadsheet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

type Cell = { wnpb: number; projection: number; actual: number };
type Row = { kind: 'section' | 'group' | 'category'; label: string; section: string; id?: number; cells?: Cell[]; total_ytd?: number; total_multi?: number };
type SumRow = {
    month: number; beginning: number; ending: number; blokir_angsuran: number; buffer: number; effective: number; nett_effective: number;
    wnpb: any; projection: any; actual: any;
};
const props = defineProps<{
    year: number; upto: number; months: number[];
    rows: Row[]; summary: SumRow[]; opening: number;
    formulas: Array<{ code: string; name: string; expression: string; description: string | null }>;
    hasSplits: boolean;
}>();

const MON = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const SECTION_TONE: Record<string, string> = {
    inflow_ops: 'bg-emerald-600 text-white',
    outflow: 'bg-rose-600 text-white',
    inflow_non_ops: 'bg-sky-600 text-white',
};

const year = ref(props.year);
const upto = ref(props.upto);
function reload() { router.get(route('bs.cashflow.report'), { year: year.value, upto: upto.value }, { preserveState: false }); }

// Angka manual (WPNB / Proyeksi per kategori-bulan, Blokir & Buffer per bulan) disunting inline.
const edit = reactive<Record<string, number>>({});
const key = (catId: number, m: number, f: 'wnpb' | 'projection') => `${catId}:${m}:${f}`;
function cellVal(r: Row, mi: number, f: 'wnpb' | 'projection') {
    const k = key(r.id!, props.months[mi], f);
    return k in edit ? edit[k] : (r.cells?.[mi]?.[f] ?? 0);
}
function setCell(r: Row, mi: number, f: 'wnpb' | 'projection', v: any) { edit[key(r.id!, props.months[mi], f)] = Number(v) || 0; }
// Gap = Proyeksi − Aktual (rumus 7), memakai angka yang sedang diketik agar langsung terlihat.
function gapOf(r: Row, mi: number) { return cellVal(r, mi, 'projection') - (r.cells?.[mi]?.actual ?? 0); }

const setEdit = reactive<Record<string, number>>({});
const skey = (m: number, f: 'blokir_angsuran' | 'buffer') => `${m}:${f}`;
function setVal(s: SumRow, f: 'blokir_angsuran' | 'buffer') { const k = skey(s.month, f); return k in setEdit ? setEdit[k] : s[f]; }
function setSet(s: SumRow, f: 'blokir_angsuran' | 'buffer', v: any) { setEdit[skey(s.month, f)] = Number(v) || 0; }

const dirty = computed(() => Object.keys(edit).length > 0 || Object.keys(setEdit).length > 0);
const saving = ref(false);
function simpan() {
    saving.value = true;
    const figures = Object.entries(edit).reduce((acc: any[], [k, v]) => {
        const [cat, m, f] = k.split(':');
        let row = acc.find(x => x.cf_category_id === Number(cat) && x.period_month === Number(m));
        if (!row) { row = { cf_category_id: Number(cat), period_month: Number(m), wnpb: null, projection: null }; acc.push(row); }
        row[f] = v;
        return acc;
    }, []);
    // Kirim nilai tersimpan untuk field yang tidak diketik, supaya updateOrCreate tidak menimpanya jadi 0.
    for (const f of figures) {
        const r = props.rows.find(x => x.id === f.cf_category_id);
        const mi = props.months.indexOf(f.period_month);
        if (f.wnpb === null) f.wnpb = r?.cells?.[mi]?.wnpb ?? 0;
        if (f.projection === null) f.projection = r?.cells?.[mi]?.projection ?? 0;
    }
    const settings = Object.entries(setEdit).reduce((acc: any[], [k, v]) => {
        const [m, f] = k.split(':');
        let row = acc.find(x => x.period_month === Number(m));
        if (!row) {
            const s = props.summary.find(x => x.month === Number(m));
            row = { period_month: Number(m), blokir_angsuran: s?.blokir_angsuran ?? 0, buffer: s?.buffer ?? 0 };
            acc.push(row);
        }
        row[f] = v;
        return acc;
    }, []);
    router.post(route('bs.cashflow.report.save'), { year: props.year, figures, settings }, {
        preserveScroll: true,
        onSuccess: () => { for (const k of Object.keys(edit)) delete edit[k]; for (const k of Object.keys(setEdit)) delete setEdit[k]; },
        onFinish: () => (saving.value = false),
    });
}

// Rincian tiap baris Nett = kategori penyusunnya menurut Set Up Kategori, mengikuti rumus
// di controller: Nett Ops = CASH IN OPS − CASH OUT, Nett Non Ops = CASH IN NON OPS.
const OWNER: Record<string, 'nett_ops' | 'nett_non_ops'> = {
    inflow_ops: 'nett_ops',
    outflow: 'nett_ops',
    inflow_non_ops: 'nett_non_ops',
};
const NETT_ROWS: Array<{ key: string; label: string; drill: boolean; hint: string }> = [
    { key: 'nett_ops', label: 'Nett Cashplan Ops', drill: true, hint: 'CASH IN OPS − CASH OUT' },
    { key: 'nett_non_ops', label: 'Nett Cashplan Non Ops', drill: true, hint: 'CASH IN NON OPS' },
    { key: 'nett', label: 'Nett Cashflow', drill: false, hint: '' },
];
const detail = computed<Record<string, Row[]>>(() => {
    const d: Record<string, Row[]> = { nett_ops: [], nett_non_ops: [] };
    for (const r of props.rows) {
        const owner = OWNER[r.section];
        if (owner) d[owner].push(r);
    }
    return d;
});
const catCount = (k: string) => (detail.value[k] ?? []).filter(r => r.kind === 'category').length;
const openRow = reactive<Record<string, boolean>>({ nett_ops: false, nett_non_ops: false });

const excelUrl = computed(() => route('bs.cashflow.report.excel', { year: props.year, upto: props.upto }));

const rp = (v: number) => (Number(v) || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
const rpSigned = (v: number) => (Number(v) || 0) < 0 ? `(${rp(Math.abs(v))})` : rp(v);
const numCls = (v: number) => (Number(v) || 0) < 0 ? 'text-danger-600 dark:text-danger-400' : '';
const showRumus = ref(false);
const inCls = 'h-7 w-full rounded border border-neutral-200 bg-white px-1 text-right font-mono text-[11px] tabular-nums focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><BarChart3 class="h-6 w-6 text-primary-600" /> Report Cashflow</h1>
                <p class="mt-0.5 text-sm text-neutral-500">Rekap transaksi yang sudah dikelompokkan lewat <b>Simpan Pembagian</b> di Rekening Ledger. Kolom <b>Actual</b> otomatis; <b>WPNB</b> &amp; <b>Proyeksi</b> diisi di sini. Klik <b>Nett Cashplan Ops</b> / <b>Non Ops</b> untuk membuka rincian kategorinya.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-neutral-500">Tahun</label>
                    <input v-model.number="year" type="number" min="2000" max="2100" class="h-9 w-24 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-neutral-500">s/d Bulan</label>
                    <select v-model.number="upto" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option v-for="m in 12" :key="m" :value="m">{{ MON[m] }}</option>
                    </select>
                </div>
                <Button variant="secondary" @click="reload">Tampilkan</Button>
                <!-- Unduhan mengikuti periode yang SEDANG tampil, bukan isian yang belum di-"Tampilkan". -->
                <a :href="excelUrl" class="inline-flex h-9 items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 transition-colors duration-200 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/50">
                    <FileSpreadsheet class="h-4 w-4" /> Unduh Excel
                </a>
                <Button :disabled="!dirty" :loading="saving" @click="simpan"><template #icon-left><Save class="h-4 w-4" /></template>Simpan Angka</Button>
            </div>
        </div>

        <div v-if="!hasSplits" class="flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm ring-1 ring-amber-200 dark:bg-amber-950/30 dark:ring-amber-800/60">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <p class="text-amber-800 dark:text-amber-200">Belum ada mutasi yang dibagi ke kategori cash flow, jadi kolom <b>Actual</b> masih nol. Buka <b>Rekening Ledger</b> → <b>Pembagian Cash Flow</b>, pilih kategorinya, lalu Simpan Pembagian.</p>
        </div>

        <!-- Rumus (buka/tutup) -->
        <div class="overflow-hidden rounded-xl bg-white ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-800">
            <button type="button" @click="showRumus = !showRumus" class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left transition-colors duration-200 hover:bg-neutral-50 dark:hover:bg-neutral-800/60">
                <Info class="h-4 w-4 shrink-0 text-primary-600" />
                <span class="text-sm font-semibold">Rumus yang dipakai laporan ini</span>
                <ChevronDown class="ml-auto h-4 w-4 shrink-0 text-neutral-400 transition-transform duration-300" :class="showRumus ? 'rotate-180' : ''" />
            </button>
            <Transition enter-active-class="transition-all duration-300 ease-out" leave-active-class="transition-all duration-200 ease-in"
                enter-from-class="max-h-0 opacity-0" enter-to-class="max-h-[40rem] opacity-100" leave-from-class="max-h-[40rem] opacity-100" leave-to-class="max-h-0 opacity-0">
                <div v-show="showRumus" class="overflow-hidden border-t border-neutral-200 dark:border-neutral-800">
                    <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="f in formulas" :key="f.code" class="rounded-lg bg-neutral-50 p-2.5 dark:bg-neutral-800/60">
                            <div class="text-xs font-bold">{{ f.name }}</div>
                            <div class="mt-0.5 font-mono text-[10.5px] leading-snug text-primary-700 dark:text-primary-300">{{ f.expression }}</div>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>

        <!-- Tabel laporan -->
        <div class="card overflow-x-auto">
            <table class="min-w-max text-xs">
                <thead>
                    <tr class="bg-neutral-100 text-[11px] font-bold uppercase text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                        <th rowspan="2" class="sticky left-0 z-10 border border-neutral-200 bg-neutral-100 px-3 py-2 text-left dark:border-neutral-700 dark:bg-neutral-800" style="min-width:15rem">Kategori</th>
                        <th v-for="m in months" :key="'h'+m" colspan="4" class="border border-neutral-200 px-2 py-2 dark:border-neutral-700">{{ MON[m] }} {{ year }}</th>
                        <th rowspan="2" class="border border-neutral-200 bg-primary-50 px-2 py-2 dark:border-neutral-700 dark:bg-primary-950/40">Total<br>{{ year }}</th>
                        <th rowspan="2" class="border border-neutral-200 bg-primary-50 px-2 py-2 dark:border-neutral-700 dark:bg-primary-950/40">Total<br>2022–{{ year }}</th>
                    </tr>
                    <tr class="bg-neutral-50 text-[10px] font-semibold uppercase text-neutral-500 dark:bg-neutral-800/60">
                        <template v-for="m in months" :key="'s'+m">
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">WPNB</th>
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Proyeksi</th>
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Actual</th>
                            <th class="border border-neutral-200 px-2 py-1 dark:border-neutral-700">Gap</th>
                        </template>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <!-- Ringkasan; dua baris Nett bisa dibuka ke bawah untuk melihat kategori penyusunnya. -->
                    <template v-for="nr in NETT_ROWS" :key="'n'+nr.key">
                        <tr class="bg-neutral-50 font-semibold dark:bg-neutral-800/50">
                            <td class="sticky left-0 z-10 border border-neutral-200 bg-neutral-50 p-0 dark:border-neutral-700 dark:bg-neutral-800/50">
                                <button v-if="nr.drill" type="button" @click="openRow[nr.key] = !openRow[nr.key]"
                                    class="flex w-full items-center gap-1.5 px-3 py-1.5 text-left transition-colors duration-150 hover:bg-primary-100/70 dark:hover:bg-primary-950/40">
                                    <component :is="openRow[nr.key] ? ChevronDown : ChevronRight" class="h-3.5 w-3.5 shrink-0 text-primary-600" />
                                    <span>{{ nr.label }}</span>
                                    <span class="ml-auto rounded-full bg-neutral-200 px-1.5 py-px text-[9.5px] font-medium tabular-nums text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300">{{ catCount(nr.key) }}</span>
                                </button>
                                <span v-else class="block px-3 py-1.5">{{ nr.label }}</span>
                            </td>
                            <template v-for="(s, si) in summary" :key="'n'+nr.key+si">
                                <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums" :class="numCls(s.wnpb[nr.key])">{{ rpSigned(s.wnpb[nr.key]) }}</td>
                                <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums" :class="numCls(s.projection[nr.key])">{{ rpSigned(s.projection[nr.key]) }}</td>
                                <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums" :class="numCls(s.actual[nr.key])">{{ rpSigned(s.actual[nr.key]) }}</td>
                                <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums text-neutral-500" :class="numCls(s.projection[nr.key] - s.actual[nr.key])">{{ rpSigned(s.projection[nr.key] - s.actual[nr.key]) }}</td>
                            </template>
                            <td class="border border-neutral-200 bg-primary-50/60 px-2 py-1 text-right font-mono tabular-nums dark:border-neutral-700 dark:bg-primary-950/25" :class="numCls(summary.reduce((a, s) => a + s.actual[nr.key], 0))">{{ rpSigned(summary.reduce((a, s) => a + s.actual[nr.key], 0)) }}</td>
                            <td class="border border-neutral-200 bg-primary-50/60 dark:border-neutral-700 dark:bg-primary-950/25"></td>
                        </tr>

                        <!-- Rincian: seksi → grup → kategori, persis susunan Set Up Kategori. -->
                        <template v-if="nr.drill && openRow[nr.key]">
                            <tr v-if="!detail[nr.key].length">
                                <td :colspan="months.length * 4 + 3" class="border border-neutral-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800 dark:border-neutral-700 dark:bg-amber-950/30 dark:text-amber-200">
                                    Belum ada kategori pada {{ nr.hint }}. Tambahkan lewat <b>Cash Flow → Set Up Kategori</b>.
                                </td>
                            </tr>
                            <template v-for="(r, ri) in detail[nr.key]" :key="nr.key+'-'+ri">
                                <!-- Judul seksi -->
                                <tr v-if="r.kind === 'section'" :class="SECTION_TONE[r.section]">
                                    <td class="sticky left-0 z-10 border border-neutral-200 py-1.5 pl-7 pr-3 text-[11px] font-bold tracking-wide dark:border-neutral-700" :class="SECTION_TONE[r.section]">{{ r.label }}</td>
                                    <td :colspan="months.length * 4 + 2" class="border border-neutral-200 dark:border-neutral-700"></td>
                                </tr>
                                <!-- Sub-judul grup -->
                                <tr v-else-if="r.kind === 'group'" class="bg-neutral-100 dark:bg-neutral-800/70">
                                    <td class="sticky left-0 z-10 border border-neutral-200 bg-neutral-100 py-1 pl-9 pr-3 text-[11px] font-bold uppercase tracking-wide text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800/70 dark:text-neutral-300">{{ r.label }}</td>
                                    <td :colspan="months.length * 4 + 2" class="border border-neutral-200 dark:border-neutral-700"></td>
                                </tr>
                                <!-- Baris kategori -->
                                <tr v-else class="transition-colors duration-150 hover:bg-primary-50/40 dark:hover:bg-primary-950/15">
                                    <td class="sticky left-0 z-10 border border-neutral-200 bg-white py-1 pl-11 pr-3 dark:border-neutral-700 dark:bg-neutral-900">{{ r.label }}</td>
                                    <template v-for="(m, mi) in months" :key="nr.key+'-'+ri+'-'+m">
                                        <td class="border border-neutral-200 p-0.5 dark:border-neutral-700"><input :value="cellVal(r, mi, 'wnpb')" @input="setCell(r, mi, 'wnpb', ($event.target as HTMLInputElement).value)" type="number" step="any" :class="inCls" /></td>
                                        <td class="border border-neutral-200 p-0.5 dark:border-neutral-700"><input :value="cellVal(r, mi, 'projection')" @input="setCell(r, mi, 'projection', ($event.target as HTMLInputElement).value)" type="number" step="any" :class="inCls" /></td>
                                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums" :class="numCls(r.cells?.[mi]?.actual ?? 0)">{{ rpSigned(r.cells?.[mi]?.actual ?? 0) }}</td>
                                        <td class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums text-neutral-500" :class="numCls(gapOf(r, mi))">{{ rpSigned(gapOf(r, mi)) }}</td>
                                    </template>
                                    <td class="border border-neutral-200 bg-primary-50/60 px-2 py-1 text-right font-mono font-semibold tabular-nums dark:border-neutral-700 dark:bg-primary-950/25" :class="numCls(r.total_ytd ?? 0)">{{ rpSigned(r.total_ytd ?? 0) }}</td>
                                    <td class="border border-neutral-200 bg-primary-50/60 px-2 py-1 text-right font-mono tabular-nums dark:border-neutral-700 dark:bg-primary-950/25" :class="numCls(r.total_multi ?? 0)">{{ rpSigned(r.total_multi ?? 0) }}</td>
                                </tr>
                            </template>
                        </template>
                    </template>

                    <!-- Saldo (mengikuti angka AKTUAL) -->
                    <tr v-for="row in ([
                            { key: 'beginning', label: 'Beginning Balance', input: null },
                            { key: 'ending', label: 'Ending Balance', input: null },
                            { key: 'blokir_angsuran', label: 'Blokir Angsuran Loan', input: 'blokir_angsuran' },
                            { key: 'effective', label: 'Effective Balance', input: null },
                            { key: 'buffer', label: 'Buffer', input: 'buffer' },
                            { key: 'nett_effective', label: 'Nett Effective Balance', input: null },
                        ] as Array<{key: string; label: string; input: any}>)" :key="row.key"
                        :class="row.key === 'nett_effective' ? 'bg-primary-50 font-bold dark:bg-primary-950/40' : 'bg-white dark:bg-neutral-900'">
                        <td class="sticky left-0 z-10 border border-neutral-200 px-3 py-1.5 font-semibold dark:border-neutral-700"
                            :class="row.key === 'nett_effective' ? 'bg-primary-50 dark:bg-primary-950/40' : 'bg-white dark:bg-neutral-900'">{{ row.label }}</td>
                        <template v-for="(s, si) in summary" :key="row.key+si">
                            <!-- Saldo hanya punya satu angka per bulan → dimerge melintasi 4 sub-kolom. -->
                            <td colspan="4" class="border border-neutral-200 px-2 py-1 text-right font-mono tabular-nums dark:border-neutral-700">
                                <input v-if="row.input" :value="setVal(s, row.input)" @input="setSet(s, row.input, ($event.target as HTMLInputElement).value)" type="number" step="any" :class="inCls" />
                                <span v-else :class="numCls((s as any)[row.key])">{{ rpSigned((s as any)[row.key]) }}</span>
                            </td>
                        </template>
                        <td colspan="2" class="border border-neutral-200 bg-primary-50/60 dark:border-neutral-700 dark:bg-primary-950/25"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-[11px] text-neutral-500">
            <b>Beginning Balance</b> bulan pertama diambil dari posisi kas/bank menurut jurnal sebelum 1 Januari {{ year }} (Rp {{ rp(opening) }}), bukan diinput ulang — supaya tidak pernah berbeda dengan pembukuan. Bulan berikutnya = Ending Balance bulan sebelumnya.
            Rincian kategori berada di dalam baris <b>Nett Cashplan Ops</b> (CASH IN OPS − CASH OUT) dan <b>Nett Cashplan Non Ops</b> (CASH IN NON OPS); <b>WPNB</b> &amp; <b>Proyeksi</b> disunting setelah rinciannya dibuka. Berkas <b>Unduh Excel</b> selalu memuat seluruh rincian, walaupun di layar sedang tertutup.
        </p>
    </div>
</template>
