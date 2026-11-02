<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, PieChart, Send, Check, X, Shuffle, Upload, FileSpreadsheet, Search, ChevronRight, RefreshCw, ExternalLink } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    year: number;
    budgets: Array<any>;
    summary: { amount: number; realisasi: number; sisa: number; approved: number };
    statuses: Record<string, string>;
    monthLabels: string[];
    mutations: Array<any>;
    accounts: Array<any>;
    costCenters: Array<any>;
    filterStatus?: string;
    pending?: number;
    laporan: { bagian: any[]; laba: any; tanpa_anggaran: boolean };
    versi: string;
    sd: number;
    versiOptions: Record<string, string>;
}>();

const year = ref(props.year);
const statusFilter = ref(props.filterStatus ?? 'approved');
function reload() { router.get(route('bs.budget.index'), { year: year.value, status: statusFilter.value, versi: props.versi }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const wfBadge: Record<string, any> = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' };
function submitBudget(b: any) { if (confirm('Ajukan anggaran ini untuk persetujuan?')) router.post(route('bs.budget.submit', b.id), {}, { preserveScroll: true }); }
function decideBudget(b: any, decision: 'approve' | 'reject') {
    const note = decision === 'reject' ? (prompt('Alasan penolakan (opsional):') ?? '') : '';
    router.post(route('bs.budget.approve', b.id), { decision, note }, { preserveScroll: true });
}
const showMutate = ref(false);
const mutForm = useForm<any>({ kind: 'transfer', mutation_date: new Date().toISOString().slice(0, 10), from_budget_id: '', to_budget_id: '', amount: 0, note: '' });
function submitMutate() { mutForm.post(route('bs.budget.mutate'), { onSuccess: () => { showMutate.value = false; mutForm.reset(); mutForm.kind = 'transfer'; } }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const blankMonths = () => ({ m1: 0, m2: 0, m3: 0, m4: 0, m5: 0, m6: 0, m7: 0, m8: 0, m9: 0, m10: 0, m11: 0, m12: 0 });
const form = useForm<any>({ year: props.year, project: '', cost_center_id: '', account_id: '', notes: '', ...blankMonths() });
const monthSum = computed(() => { let s = 0; for (let m = 1; m <= 12; m++) s += Number(form['m' + m] || 0); return s; });
function openCreate() { editingId.value = null; form.reset(); form.year = props.year; showModal.value = true; }
function openEdit(b: any) {
    editingId.value = b.id;
    form.year = props.year; form.account_id = ''; form.cost_center_id = ''; form.project = b.project ?? ''; form.notes = b.notes ?? '';
    for (let m = 1; m <= 12; m++) form['m' + m] = Number(b.months?.[m - 1] ?? 0);
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.budget.update', editingId.value), opts) : form.post(route('bs.budget.store'), opts);
}
function destroy(b: any) { if (confirm('Hapus anggaran ini?')) router.delete(route('bs.budget.destroy', b.id), { preserveScroll: true }); }

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// ── Anggaran vs Realisasi Tahunan ────────────────────────────────────────────────
// Angka sudah dihitung server (BudgetAnnualReportService); layar hanya menyusun & menyaring.
type Angka = { anggaran: number; realisasi: number; selisih: number; persen: number | null; setahun: number };
type BarisCc = Angka & { cc: string; cc_nama: string; budgets: any[] };
type Akun = Angka & { id: number; kode: string; nama: string; baris: BarisCc[] };
type Grup = Angka & { kode: string; nama: string; akun: Akun[] };
type Bagian = Angka & { kode: string; judul: string; grup: Grup[] };

const versi = ref(props.versi);
const sd = ref(props.sd);
function muat() { router.get(route('bs.budget.index'), { year: year.value, status: statusFilter.value, versi: versi.value, sd: sd.value }, { preserveState: true, replace: true }); }
function fmtAngka(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
function fmtPersen(p: number | null) { return p === null ? '—' : p.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%'; }
const warnaSelisih = (v: number) => (v > 0.004 ? 'text-success-700' : v < -0.004 ? 'text-danger-600' : 'text-neutral-500');

// Buka/tutup kelompok & akun (default terbuka semua).
const tertutup = reactive(new Set<string>());
const toggle = (k: string) => (tertutup.has(k) ? tertutup.delete(k) : tertutup.add(k));
function tutupSemua() { for (const bg of props.laporan.bagian) for (const g of bg.grup) tertutup.add('g:' + bg.kode + g.kode); }
function bukaSemua() { tertutup.clear(); }

// Cari: cocokkan akun / kelompok / cost center / no. budget; total ikut baris yang tersisa.
const search = ref('');
function jumlah(anak: Angka[], pendapatan: boolean): Angka {
    const s = (f: keyof Angka) => anak.reduce((t, r) => t + Number(r[f] ?? 0), 0);
    const anggaran = s('anggaran'), realisasi = s('realisasi');
    return { anggaran, realisasi, setahun: s('setahun'), selisih: pendapatan ? realisasi - anggaran : anggaran - realisasi, persen: Math.abs(anggaran) >= 0.005 ? Math.round(realisasi / anggaran * 1000) / 10 : null };
}
const bagian = computed<Bagian[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.laporan.bagian;
    const out: Bagian[] = [];
    for (const bg of props.laporan.bagian as Bagian[]) {
        const pend = bg.kode === 'income';
        const grup: Grup[] = [];
        for (const g of bg.grup) {
            const gCocok = (g.kode + ' ' + g.nama).toLowerCase().includes(q);
            const akun: Akun[] = [];
            for (const a of g.akun) {
                const aCocok = gCocok || (a.kode + ' ' + a.nama).toLowerCase().includes(q);
                const baris = aCocok ? a.baris : a.baris.filter((r) => [r.cc_nama, ...r.budgets.map((d: any) => d.doc_no ?? '')].join(' ').toLowerCase().includes(q));
                if (baris.length) akun.push({ ...a, baris, ...jumlah(baris, pend) });
            }
            if (akun.length) grup.push({ ...g, akun, ...jumlah(akun, pend) });
        }
        if (grup.length) out.push({ ...bg, grup, ...jumlah(grup, pend) });
    }
    return out;
});
const laba = computed<Angka>(() => {
    if (!search.value.trim()) return props.laporan.laba;
    const n = (k: string) => bagian.value.find((b) => b.kode === k);
    const i = n('income'), e = n('expense');
    const anggaran = (i?.anggaran ?? 0) - (e?.anggaran ?? 0), realisasi = (i?.realisasi ?? 0) - (e?.realisasi ?? 0);
    return { anggaran, realisasi, setahun: (i?.setahun ?? 0) - (e?.setahun ?? 0), selisih: realisasi - anggaran, persen: Math.abs(anggaran) >= 0.005 ? Math.round(realisasi / anggaran * 1000) / 10 : null };
});

const excelUrl = computed(() => route('bs.budget.laporan-export', { year: props.year, status: props.filterStatus ?? 'approved', versi: props.versi, sd: props.sd }));
// Tombol Excel di header tetap: daftar dokumen anggaran + realisasi per bulan.
const excelDaftarUrl = computed(() => route('bs.budget.export', { year: props.year, status: props.filterStatus ?? 'approved' }));
const bulanSd = computed(() => props.monthLabels[props.sd - 1]);

// ── Klik angka → dasarnya (dokumen anggaran per bulan + Koreksi Budget, atau jurnal) ──
const rincian = ref<any>(null);
const tabRincian = ref<'anggaran' | 'realisasi'>('anggaran');
const memuatRincian = ref(false);
async function bukaRincian(akunId: number, cc: string, tab: 'anggaran' | 'realisasi') {
    tabRincian.value = tab;
    memuatRincian.value = true;
    rincian.value = { memuat: true };
    try {
        const { data } = await window.axios.get(route('bs.budget.rincian'), { params: { year: props.year, status: props.filterStatus ?? 'approved', versi: props.versi, sd: props.sd, account_id: akunId, cc } });
        rincian.value = data;
    } catch {
        rincian.value = { gagal: true };
    } finally {
        memuatRincian.value = false;
    }
}
const totalJurnal = computed(() => (rincian.value?.jurnal ?? []).reduce((t: number, j: any) => t + Number(j.nilai || 0), 0));
const korStatus: Record<string, any> = { baru: 'warning', approved: 'success', rejected: 'danger' };
const korLabel: Record<string, string> = { baru: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' };
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><PieChart class="h-6 w-6 text-primary-600" /> Master Budget</h1>
            <div class="flex items-center gap-2">
                <input v-model.number="year" @change="reload" type="number" class="h-9 w-28 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <select v-model="statusFilter" @change="reload" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" title="Filter status"><option value="approved">Disetujui</option><option value="submitted">Menunggu Approval</option><option value="rejected">Ditolak</option><option value="all">Semua Status</option></select>
                <Link :href="route('bs.budget.upload.index')" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium dark:border-neutral-700"><Upload class="h-4 w-4" /> Upload</Link>
                <a :href="excelDaftarUrl" title="Excel daftar anggaran + realisasi per bulan (Jan–Des)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <button @click="showMutate = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium dark:border-neutral-700"><Shuffle class="h-4 w-4" /> Mutasi</button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Anggaran</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Anggaran {{ year }}</div><div class="mt-1 text-lg font-bold">{{ fmtRp(summary.amount) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Disetujui</div><div class="mt-1 text-lg font-bold text-success-700">{{ fmtRp(summary.approved) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi</div><div class="mt-1 text-lg font-bold text-primary-600">{{ fmtRp(summary.realisasi) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Anggaran</div><div class="mt-1 text-lg font-bold" :class="summary.sisa >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(summary.sisa) }}</div></div>
        </div>

        <!-- Gate approval: yang belum di-approve tidak tampil di daftar (default "Disetujui"). -->
        <Link v-if="pending && statusFilter === 'approved'" :href="route('bs.budget.approvals')" class="flex items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
            <span><b>{{ pending }}</b> anggaran menunggu approval — belum tampil di daftar ini.</span>
            <span class="font-semibold underline">Buka Approval Budget →</span>
        </Link>

        <!-- Anggaran vs Realisasi Tahunan -->
        <div class="card space-y-3 p-4">
            <div>
                <h2 class="font-semibold">Anggaran vs Realisasi Tahunan</h2>
                <p class="mt-0.5 text-xs text-neutral-500">
                    <b>Anggaran</b> per bulan dari master budget (versi terpilih) · <b>Realisasi</b> dari jurnal — aturan yang sama dengan penjaga budget &amp; Koreksi Budget.
                    Selisih positif = lebih baik (pendapatan di atas anggaran, beban di bawah anggaran). <b>Klik angka</b> untuk melihat dasarnya.
                </p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Versi anggaran</label>
                    <select v-model="versi" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option v-for="(lbl, k) in versiOptions" :key="k" :value="k">{{ lbl }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Realisasi s.d.</label>
                    <select v-model.number="sd" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option v-for="(lbl, i) in monthLabels" :key="i" :value="i + 1">{{ lbl }}</option>
                    </select>
                </div>
                <Button @click="muat"><template #icon-left><RefreshCw class="h-4 w-4" /></template>Muat laporan</Button>
                <a :href="excelUrl" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Unduh Excel</a>
                <div class="relative ml-auto min-w-[220px] sm:max-w-xs">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="search" type="text" placeholder="Cari akun, cost center, no. budget…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500">
                <span>Anggaran: <b>{{ versiOptions[props.versi] }}</b> · status <b>{{ statusFilter === 'all' ? 'Semua' : (statuses[statusFilter] ?? statusFilter) }}</b> · realisasi s.d. akhir <b>{{ bulanSd }} {{ year }}</b>.</span>
                <span class="flex gap-3"><button type="button" class="hover:text-primary-600" @click="bukaSemua">Buka semua</button><button type="button" class="hover:text-primary-600" @click="tutupSemua">Tutup semua</button></span>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[920px] text-sm">
                <colgroup><col /><col class="w-36" /><col class="w-36" /><col class="w-36" /><col class="w-20" /><col class="w-36" /><col class="w-28" /></colgroup>
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Uraian</th>
                        <th class="px-3 py-3 text-right">Anggaran s.d. {{ bulanSd }}</th>
                        <th class="px-3 py-3 text-right">Realisasi s.d. {{ bulanSd }}</th>
                        <th class="px-3 py-3 text-right">Selisih</th>
                        <th class="px-3 py-3 text-right">%</th>
                        <th class="px-3 py-3 text-right">Anggaran setahun</th>
                        <th class="px-3 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <template v-for="bg in bagian" :key="bg.kode">
                        <tr class="bg-primary-50/70 dark:bg-primary-900/20"><td colspan="7" class="px-4 py-2 font-bold">{{ bg.judul }}</td></tr>
                        <template v-for="g in bg.grup" :key="g.kode">
                            <tr class="cursor-pointer bg-neutral-100/70 dark:bg-neutral-800/60" @click="toggle('g:' + bg.kode + g.kode)">
                                <td class="px-4 py-2 font-semibold"><ChevronRight class="mr-1 inline h-4 w-4 transition-transform" :class="tertutup.has('g:' + bg.kode + g.kode) ? '' : 'rotate-90'" />{{ g.kode }} {{ g.nama }}</td>
                                <template v-if="tertutup.has('g:' + bg.kode + g.kode)">
                                    <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtAngka(g.anggaran) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtAngka(g.realisasi) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold" :class="warnaSelisih(g.selisih)">{{ fmtAngka(g.selisih) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ fmtPersen(g.persen) }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtAngka(g.setahun) }}</td>
                                    <td></td>
                                </template>
                                <td v-else colspan="6"></td>
                            </tr>
                            <template v-if="!tertutup.has('g:' + bg.kode + g.kode)">
                                <template v-for="a in g.akun" :key="a.id">
                                    <tr class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/40" @click="toggle('a:' + bg.kode + a.id)">
                                        <td class="py-2 pl-8 pr-4"><ChevronRight class="mr-1 inline h-3.5 w-3.5 text-neutral-400 transition-transform" :class="tertutup.has('a:' + bg.kode + a.id) ? '' : 'rotate-90'" />{{ a.kode }} - {{ a.nama }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(a.anggaran) }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(a.realisasi) }}</td>
                                        <td class="px-3 py-2 text-right font-mono" :class="warnaSelisih(a.selisih)">{{ fmtAngka(a.selisih) }}</td>
                                        <td class="px-3 py-2 text-right">{{ fmtPersen(a.persen) }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(a.setahun) }}</td>
                                        <td></td>
                                    </tr>
                                    <template v-if="!tertutup.has('a:' + bg.kode + a.id)">
                                        <tr v-for="r in a.baris" :key="r.cc" class="text-neutral-600 dark:text-neutral-300">
                                            <td class="py-1.5 pl-14 pr-4 text-xs">
                                                <span>{{ r.cc_nama }}</span>
                                                <span v-for="d in r.budgets" :key="d.id" class="ml-2 inline-flex items-center gap-1">
                                                    <span class="font-mono text-[11px] text-neutral-400">{{ d.doc_no ?? '—' }}</span>
                                                    <Badge :variant="wfBadge[d.wf_status]" size="sm" :title="d.approval_note">{{ statuses[d.wf_status] ?? d.wf_status }}</Badge>
                                                </span>
                                                <span v-if="!r.budgets.length" class="ml-2 text-[11px] italic text-neutral-400">tanpa anggaran</span>
                                            </td>
                                            <td class="px-3 py-1.5 text-right font-mono text-xs"><button type="button" class="underline decoration-dotted underline-offset-2 hover:text-primary-600" @click="bukaRincian(a.id, r.cc, 'anggaran')">{{ fmtAngka(r.anggaran) }}</button></td>
                                            <td class="px-3 py-1.5 text-right font-mono text-xs"><button type="button" class="underline decoration-dotted underline-offset-2 hover:text-primary-600" @click="bukaRincian(a.id, r.cc, 'realisasi')">{{ fmtAngka(r.realisasi) }}</button></td>
                                            <td class="px-3 py-1.5 text-right font-mono text-xs" :class="warnaSelisih(r.selisih)">{{ fmtAngka(r.selisih) }}</td>
                                            <td class="px-3 py-1.5 text-right text-xs">{{ fmtPersen(r.persen) }}</td>
                                            <td class="px-3 py-1.5 text-right font-mono text-xs"><button type="button" class="underline decoration-dotted underline-offset-2 hover:text-primary-600" @click="bukaRincian(a.id, r.cc, 'anggaran')">{{ fmtAngka(r.setahun) }}</button></td>
                                            <td class="px-3 py-1.5">
                                                <div v-for="d in r.budgets" :key="d.id" class="flex justify-end gap-1.5">
                                                    <button v-if="d.wf_status === 'draft' || d.wf_status === 'rejected'" @click="submitBudget(d)" title="Ajukan" class="text-amber-600 hover:text-amber-800"><Send class="h-4 w-4" /></button>
                                                    <button v-if="d.wf_status === 'submitted'" @click="decideBudget(d, 'approve')" title="Setujui" class="text-success-600 hover:text-success-800"><Check class="h-4 w-4" /></button>
                                                    <button v-if="d.wf_status === 'submitted'" @click="decideBudget(d, 'reject')" title="Tolak" class="text-danger-500 hover:text-danger-700"><X class="h-4 w-4" /></button>
                                                    <button @click="openEdit(d)" :title="'Edit ' + (d.doc_no ?? '')" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                                                    <button @click="destroy(d)" :title="'Hapus ' + (d.doc_no ?? '')" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </template>
                                <tr class="font-semibold">
                                    <td class="py-2 pl-8 pr-4">Total {{ g.kode }} {{ g.nama }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(g.anggaran) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(g.realisasi) }}</td>
                                    <td class="px-3 py-2 text-right font-mono" :class="warnaSelisih(g.selisih)">{{ fmtAngka(g.selisih) }}</td>
                                    <td class="px-3 py-2 text-right">{{ fmtPersen(g.persen) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(g.setahun) }}</td>
                                    <td></td>
                                </tr>
                            </template>
                        </template>
                        <tr class="bg-primary-50/40 font-bold dark:bg-primary-900/10">
                            <td class="px-4 py-2 uppercase">Total {{ bg.judul }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(bg.anggaran) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(bg.realisasi) }}</td>
                            <td class="px-3 py-2 text-right font-mono" :class="warnaSelisih(bg.selisih)">{{ fmtAngka(bg.selisih) }}</td>
                            <td class="px-3 py-2 text-right">{{ fmtPersen(bg.persen) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtAngka(bg.setahun) }}</td>
                            <td></td>
                        </tr>
                    </template>
                    <tr v-if="bagian.length" class="bg-amber-50 font-bold dark:bg-amber-900/20">
                        <td class="px-4 py-2.5">LABA (pendapatan − beban)</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtAngka(laba.anggaran) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtAngka(laba.realisasi) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono" :class="warnaSelisih(laba.selisih)">{{ fmtAngka(laba.selisih) }}</td>
                        <td class="px-3 py-2.5 text-right">{{ fmtPersen(laba.persen) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtAngka(laba.setahun) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!laporan.bagian.length" title="Belum ada anggaran" description="Klik Tambah Anggaran atau Upload Budget untuk menetapkan anggaran bulanan per akun & cost center." />
            <EmptyState v-else-if="!bagian.length" title="Tidak ada hasil" description="Tidak ada akun / cost center / no. budget yang cocok dengan pencarian." />
        </div>

        <!-- Dasar angka (klik angka) -->
        <Teleport to="body">
            <div v-if="rincian" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="rincian = null">
                <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                        <div v-if="rincian.akun">
                            <h2 class="font-bold">{{ rincian.akun.code }} - {{ rincian.akun.name }}</h2>
                            <p class="text-xs text-neutral-500">{{ rincian.cost_center }} · {{ rincian.periode }}</p>
                        </div>
                        <h2 v-else class="font-bold">Dasar angka</h2>
                        <button type="button" class="text-neutral-400 hover:text-neutral-700" @click="rincian = null"><X class="h-5 w-5" /></button>
                    </div>
                    <div v-if="rincian.memuat" class="p-6 text-sm text-neutral-500">Memuat…</div>
                    <div v-else-if="rincian.gagal" class="p-6 text-sm text-danger-600">Gagal memuat dasar angka. Coba lagi.</div>
                    <template v-else>
                        <div class="flex gap-1 border-b border-neutral-200 px-5 dark:border-neutral-800">
                            <button v-for="t in (['anggaran', 'realisasi'] as const)" :key="t" type="button" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tabRincian === t ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500'" @click="tabRincian = t">
                                {{ t === 'anggaran' ? 'Dokumen anggaran' : 'Jurnal realisasi (' + rincian.jurnal.length + ')' }}
                            </button>
                        </div>
                        <div class="overflow-y-auto p-5 text-sm">
                            <template v-if="tabRincian === 'anggaran'">
                                <p v-if="!rincian.budgets.length" class="text-neutral-500">Tidak ada dokumen anggaran untuk akun &amp; cost center ini pada status terpilih.</p>
                                <div v-for="d in rincian.budgets" :key="d.id" class="mb-5 space-y-2">
                                    <div class="flex items-center gap-2"><span class="font-mono text-xs">{{ d.doc_no ?? '—' }}</span><Badge :variant="wfBadge[d.wf_status]" size="sm">{{ statuses[d.wf_status] ?? d.wf_status }}</Badge></div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead><tr class="text-neutral-500"><th class="py-1 text-left">Versi</th><th v-for="(m, i) in monthLabels" :key="i" class="px-1 py-1 text-right" :class="i + 1 <= props.sd ? '' : 'text-neutral-300'">{{ m }}</th><th class="px-1 py-1 text-right">Setahun</th></tr></thead>
                                            <tbody>
                                                <tr v-for="v in (['awal', 'berlaku'] as const)" :key="v" :class="v === props.versi ? 'font-semibold' : 'text-neutral-500'">
                                                    <td class="py-1 pr-2 capitalize">{{ v }}</td>
                                                    <td v-for="(n, i) in d[v]" :key="i" class="px-1 py-1 text-right font-mono">{{ fmtAngka(n) }}</td>
                                                    <td class="px-1 py-1 text-right font-mono">{{ fmtAngka(d[v].reduce((t: number, n: number) => t + n, 0)) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-if="d.koreksi.length">
                                        <div class="mb-1 text-xs font-medium text-neutral-500">Koreksi Budget</div>
                                        <a v-for="k in d.koreksi" :key="k.doc_no" :href="k.url" class="flex items-center justify-between gap-2 rounded-lg border border-neutral-200 px-3 py-1.5 text-xs hover:border-primary-300 dark:border-neutral-800">
                                            <span><span class="font-mono">{{ k.doc_no }}</span> · {{ k.tanggal }} · {{ k.periode }}<span v-if="k.note" class="text-neutral-400"> · {{ k.note }}</span></span>
                                            <span class="flex items-center gap-2"><span class="font-mono" :class="k.nilai < 0 ? 'text-danger-600' : 'text-success-700'">{{ k.nilai > 0 ? '+' : '' }}{{ fmtAngka(k.nilai) }}</span><Badge :variant="korStatus[k.status]" size="sm">{{ korLabel[k.status] ?? k.status }}</Badge><ExternalLink class="h-3 w-3 text-neutral-400" /></span>
                                        </a>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <p v-if="!rincian.jurnal.length" class="text-neutral-500">Belum ada jurnal pada periode ini.</p>
                                <table v-else class="w-full text-xs">
                                    <thead><tr class="text-left text-neutral-500"><th class="py-1">Tanggal</th><th class="py-1">No. Jurnal</th><th class="py-1">Keterangan</th><th class="py-1">Cost Center</th><th class="py-1 text-right">Nilai</th></tr></thead>
                                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        <tr v-for="(j, i) in rincian.jurnal" :key="i">
                                            <td class="py-1.5 pr-2">{{ j.tanggal }}</td>
                                            <td class="py-1.5 pr-2"><a :href="j.url" class="font-mono text-primary-600 hover:underline">{{ j.nomor }}</a></td>
                                            <td class="py-1.5 pr-2">{{ j.keterangan }}</td>
                                            <td class="py-1.5 pr-2 text-neutral-500">{{ j.cost_center }}</td>
                                            <td class="py-1.5 text-right font-mono">{{ fmtAngka(j.nilai) }}</td>
                                        </tr>
                                        <tr class="font-semibold"><td colspan="4" class="py-1.5">Total</td><td class="py-1.5 text-right font-mono">{{ fmtAngka(totalJurnal) }}</td></tr>
                                    </tbody>
                                </table>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Anggaran</h2>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun *</label><input v-model.number="form.year" type="number" required :class="inputCls" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project</label><input v-model="form.project" placeholder="JR" :class="inputCls" /></div>
                            <div class="col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label><select v-model="form.cost_center_id" :class="inputCls"><option value="">Semua cost center</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun *</label><select v-model="form.account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select><p v-if="form.errors.account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.account_id }}</p></div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Anggaran per Bulan</label>
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                                <div v-for="(lbl, i) in monthLabels" :key="i">
                                    <span class="block text-[10px] text-neutral-400">{{ lbl }}</span>
                                    <input v-model.number="form['m' + (i + 1)]" type="number" step="1" min="0" :class="[inputCls, 'text-right font-mono !px-2 !text-xs']" />
                                </div>
                            </div>
                            <div class="mt-2 text-right text-sm">Total setahun: <b class="font-mono">{{ fmtRp(monthSum) }}</b></div>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="form.notes" :class="inputCls" /></div>
                        <div class="flex justify-end gap-2 pt-2"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Simpan</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Riwayat Mutasi -->
        <div v-if="mutations?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Mutasi Cepat {{ year }}</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Jenis</th><th class="px-4 py-2.5">Dari → Ke</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5">Catatan</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in mutations" :key="m.id">
                        <td class="px-4 py-2">{{ m.date }}</td>
                        <td class="px-4 py-2"><Badge :variant="m.kind === 'transfer' ? 'info' : 'primary'" size="sm">{{ m.kind === 'transfer' ? 'Transfer' : 'Koreksi' }}</Badge></td>
                        <td class="px-4 py-2 font-mono text-xs">{{ m.from ?? '—' }} → {{ m.to ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                        <td class="px-4 py-2 text-neutral-500">{{ m.note ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Modal Mutasi -->
        <Teleport to="body">
            <div v-if="showMutate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showMutate = false">
                <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">Mutasi Cepat Anggaran {{ year }}</h2>
                    <form @submit.prevent="submitMutate" class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium" :class="mutForm.kind === 'transfer' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="mutForm.kind" type="radio" value="transfer" class="sr-only" /> Transfer</label>
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium" :class="mutForm.kind === 'koreksi' ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="mutForm.kind" type="radio" value="koreksi" class="sr-only" /> Koreksi (+)</label>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="mutForm.mutation_date" type="date" required :class="inputCls" /></div>
                        <div v-if="mutForm.kind === 'transfer'"><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Anggaran *</label><select v-model="mutForm.from_budget_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="b in budgets" :key="b.id" :value="b.id">{{ b.account }} ({{ fmtRp(b.amount) }})</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">{{ mutForm.kind === 'transfer' ? 'Ke Anggaran' : 'Anggaran Target' }} *</label><select v-model="mutForm.to_budget_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="b in budgets" :key="b.id" :value="b.id">{{ b.account }} ({{ fmtRp(b.amount) }})</option></select></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai *</label><input v-model.number="mutForm.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="mutForm.errors.amount" class="mt-1 text-xs text-danger-500">{{ mutForm.errors.amount }}</p></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="mutForm.note" :class="inputCls" /></div>
                        <div class="flex justify-end gap-2 pt-2"><button type="button" @click="showMutate = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="mutForm.processing">Simpan Mutasi</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
