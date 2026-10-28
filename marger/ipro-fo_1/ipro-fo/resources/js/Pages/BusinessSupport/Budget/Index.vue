<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, PieChart, Send, Check, X, Shuffle, Upload, FileSpreadsheet, Search, FilterX } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

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
}>();

const year = ref(props.year);
const statusFilter = ref(props.filterStatus ?? 'approved');
function reload() { router.get(route('bs.budget.index'), { year: year.value, status: statusFilter.value }, { preserveState: true, replace: true }); }
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

const statusBadge: Record<string, any> = { aman: 'success', warning: 'warning', over: 'danger', na: 'neutral' };
const barColor: Record<string, string> = { aman: 'bg-success-500', warning: 'bg-amber-500', over: 'bg-danger-500', na: 'bg-neutral-300' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// ── Filter per kolom (mengikuti pola Account Payable) ────────────────────────────
// Nilai tampilan per-kolom — dipakai untuk render, opsi filter, & pencocokan filter
// agar yang difilter selalu SAMA PERSIS dengan yang terbaca di layar.
const dNo = (b: any) => b.doc_no ?? '—';
const dAkun = (b: any) => b.account ?? '';
const dCc = (b: any) => b.cost_center ?? '';
const dStatus = (b: any) => props.statuses[b.wf_status] ?? b.wf_status ?? '';
const dAnggaran = (b: any) => fmtRp(b.amount);
const dRealisasi = (b: any) => fmtRp(b.realisasi);
const dSisa = (b: any) => fmtRp(b.sisa);
const dTerpakai = (b: any) => b.percent + '%';

function distinct(fn: (b: any) => string) {
    const s = new Set<string>();
    for (const b of props.budgets) s.add(fn(b));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optAkun = computed(() => distinct(dAkun));
const optCc = computed(() => distinct(dCc));
const optStatus = computed(() => distinct(dStatus));
const optAnggaran = computed(() => distinct(dAnggaran));
const optRealisasi = computed(() => distinct(dRealisasi));
const optSisa = computed(() => distinct(dSisa));
const optTerpakai = computed(() => distinct(dTerpakai));

// Filter global (ketik) + checklist per-kolom (kosong = tampilkan semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], akun: [], cc: [], status: [], anggaran: [], realisasi: [], sisa: [], terpakai: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; Object.keys(col).forEach((k) => (col[k] = [])); }

const filtered = computed<any[]>(() => props.budgets.filter((b) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(b), dAkun(b), dCc(b), dStatus(b), dAnggaran(b), dRealisasi(b), dSisa(b), dTerpakai(b)].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.no.length && !col.no.includes(dNo(b))) return false;
    if (col.akun.length && !col.akun.includes(dAkun(b))) return false;
    if (col.cc.length && !col.cc.includes(dCc(b))) return false;
    if (col.status.length && !col.status.includes(dStatus(b))) return false;
    if (col.anggaran.length && !col.anggaran.includes(dAnggaran(b))) return false;
    if (col.realisasi.length && !col.realisasi.includes(dRealisasi(b))) return false;
    if (col.sisa.length && !col.sisa.includes(dSisa(b))) return false;
    if (col.terpakai.length && !col.terpakai.includes(dTerpakai(b))) return false;
    return true;
}));

// Kartu ringkasan mengikuti hasil filter agar angka atas cocok dgn baris yang tampil.
// Tanpa filter hasilnya identik dgn props.summary (sumber & rumusnya sama).
const view = computed(() => {
    const amount = filtered.value.reduce((s, b) => s + Number(b.amount || 0), 0);
    const realisasi = filtered.value.reduce((s, b) => s + Number(b.realisasi || 0), 0);
    return {
        amount, realisasi, sisa: amount - realisasi,
        approved: filtered.value.filter((b) => b.wf_status === 'approved').reduce((s, b) => s + Number(b.amount || 0), 0),
    };
});

// Excel = baris yang sedang tampil: tahun & status yang sudah dimuat server, plus id baris
// hasil cari/saringan kolom (hanya bila ada saringan — tanpa saringan semua baris status itu).
const excelUrl = computed(() => route('bs.budget.export', {
    year: props.year,
    status: props.filterStatus ?? 'approved',
    ids: hasFilter.value ? (filtered.value.map((b) => b.id).join(',') || '0') : undefined,
}));

// Data berubah (mis. ganti tahun/status) → buang nilai filter yang sudah tak ada,
// supaya tabel tidak diam-diam kosong karena filter lama yang tak lagi relevan.
watch([optNo, optAkun, optCc, optStatus, optAnggaran, optRealisasi, optSisa, optTerpakai], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.akun = prune(col.akun, optAkun.value); col.cc = prune(col.cc, optCc.value);
    col.status = prune(col.status, optStatus.value); col.anggaran = prune(col.anggaran, optAnggaran.value);
    col.realisasi = prune(col.realisasi, optRealisasi.value); col.sisa = prune(col.sisa, optSisa.value); col.terpakai = prune(col.terpakai, optTerpakai.value);
});
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
                <a :href="excelUrl"class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-700/50 dark:bg-emerald-900/30 dark:text-emerald-300"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <button @click="showMutate = true" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium dark:border-neutral-700"><Shuffle class="h-4 w-4" /> Mutasi</button>
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Anggaran</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Anggaran {{ year }}<span v-if="hasFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-lg font-bold">{{ fmtRp(view.amount) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Disetujui<span v-if="hasFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-lg font-bold text-success-700">{{ fmtRp(view.approved) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Realisasi<span v-if="hasFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-lg font-bold text-primary-600">{{ fmtRp(view.realisasi) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Anggaran<span v-if="hasFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-lg font-bold" :class="view.sisa >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(view.sisa) }}</div></div>
        </div>

        <!-- Gate approval: yang belum di-approve tidak tampil di daftar (default "Disetujui"). -->
        <Link v-if="pending && statusFilter === 'approved'" :href="route('bs.budget.approvals')" class="flex items-center justify-between gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300">
            <span><b>{{ pending }}</b> anggaran menunggu approval — belum tampil di daftar ini.</span>
            <span class="font-semibold underline">Buka Approval Budget →</span>
        </Link>

        <!-- Pencarian ketik (semua kolom) + reset filter kolom -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-[240px] flex-1 sm:max-w-xs">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" type="text" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <p class="text-xs text-neutral-500">{{ filtered.length }} dari {{ budgets.length }} anggaran</p>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="ml-auto inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 pb-1.5 pt-3">No. Budget</th><th class="px-4 pb-1.5 pt-3">Akun</th><th class="px-4 pb-1.5 pt-3">Cost Center</th><th class="px-4 pb-1.5 pt-3 text-center">Status</th><th class="px-4 pb-1.5 pt-3 text-right">Anggaran</th><th class="px-4 pb-1.5 pt-3 text-right">Realisasi</th><th class="px-4 pb-1.5 pt-3 text-right">Sisa</th><th class="w-40 px-4 pb-1.5 pt-3">Terpakai</th><th class="px-4 pb-1.5 pt-3 text-right">Aksi</th>
                    </tr>
                    <tr>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.akun" :options="optAkun" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cc" :options="optCc" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" :options="optStatus" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.anggaran" :options="optAnggaran" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.realisasi" :options="optRealisasi" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.sisa" :options="optSisa" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.terpakai" :options="optTerpakai" align="right" /></th>
                        <th class="px-3 pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="b in filtered" :key="b.id">
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ b.doc_no ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ b.account }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ b.cost_center }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="wfBadge[b.wf_status]" size="sm" :title="b.approval_note">{{ statuses[b.wf_status] ?? b.wf_status }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.amount) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(b.realisasi) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="b.sisa < 0 ? 'text-danger-600' : ''">{{ fmtRp(b.sisa) }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-700"><div :class="barColor[b.health]" class="h-full" :style="{ width: Math.min(100, b.percent) + '%' }"></div></div>
                                <Badge :variant="statusBadge[b.health]" size="sm">{{ b.percent }}%</Badge>
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex justify-end gap-1.5">
                                <button v-if="b.wf_status === 'draft' || b.wf_status === 'rejected'" @click="submitBudget(b)" title="Ajukan" class="text-amber-600 hover:text-amber-800"><Send class="h-4 w-4" /></button>
                                <button v-if="b.wf_status === 'submitted'" @click="decideBudget(b, 'approve')" title="Setujui" class="text-success-600 hover:text-success-800"><Check class="h-4 w-4" /></button>
                                <button v-if="b.wf_status === 'submitted'" @click="decideBudget(b, 'reject')" title="Tolak" class="text-danger-500 hover:text-danger-700"><X class="h-4 w-4" /></button>
                                <button @click="openEdit(b)" title="Edit" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button>
                                <button @click="destroy(b)" title="Hapus" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!budgets?.length" title="Belum ada anggaran" description="Klik Tambah Anggaran atau Upload Budget untuk menetapkan anggaran bulanan per akun & cost center." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada anggaran yang cocok dengan filter/pencarian. Klik Reset filter untuk menampilkan semua." />
        </div>

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
