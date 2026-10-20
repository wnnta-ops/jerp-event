<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Landmark, RotateCcw, Undo2, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import ScrollTopButton from '@/Components/UI/ScrollTopButton.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';
import Pagination from '@/Components/UI/Pagination.vue';

type Acc = { id: number; code: string; name: string; type?: string };
type Row = {
    id: number; tts_number: string; amount: number; received_date: string | null; payment_method: string | null; reference_no: string | null; customer: string | null; booking: string | null;
    unit_code: string | null; unit_info: string | null; bank_account_label: string | null;
    journaled: boolean; journal_id: number | null; journal_number: string | null; journal_date: string | null;
    debit_account_id: number | null; debit_account: string | null; credit_account_id: number | null; credit_account: string | null;
    default_debit_account_id: number | null; default_credit_account_id: number | null;
    reject_reason: string | null; rejected_at: string | null;
};
const props = defineProps<{
    /** Hasil saringan server, 50 baris per halaman. */
    rows: { data: Row[]; links?: any[]; from?: number | null; to?: number | null; total?: number };
    jumlahSemua: number;
    opsi: { customer: string[]; tts_number: string[]; amount: number[]; payment_method: string[]; journal_date: string[] };
    filters: Record<string, string>;
    accounts: Acc[]; tab: string; counts: { pending: number; tanpa_unit: number; rejected: number };
    // Halaman ini dipakai DUA submodul: Approval UTJ & Approval DP. Judul, penjelasan, dan
    // seluruh endpoint mengikuti prop di bawah supaya tidak ada aksi yang salah alamat.
    variant?: 'utj' | 'dp'; title?: string; subtitle?: string; routeBase?: string; indexRoute?: string;
}>();
const rb = computed(() => props.routeBase ?? 'bs.cashbook.approval-utjdp');
const judul = computed(() => props.title ?? 'Approval UTJ');

const isRejected = computed(() => props.tab === 'rejected');
function switchTab(tab: string) { if (tab !== props.tab) router.get(route(props.indexRoute ?? 'bs.cashbook.approval-utjdp'), { tab }, { preserveScroll: true }); }

function todayStr() { return new Date().toISOString().slice(0, 10); }
const forms = reactive<Record<number, { journal_date: string; debit_account_id: any; credit_account_id: any; processing: boolean }>>({});
// #164 — default tgl jurnal = tgl terima TTS; akun debit = bank TTS; akun kredit = Hutang Usaha (semua editable).
if (!isRejected.value) for (const r of (props.rows.data ?? [])) if (!r.journaled) forms[r.id] = { journal_date: r.received_date ?? todayStr(), debit_account_id: r.default_debit_account_id ?? '', credit_account_id: r.default_credit_account_id ?? '', processing: false };

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Daftar akun lokal (semua akun aktif & postable) — sinkron dgn prop, bisa di-refresh setelah quick-add.
const accountList = ref<Acc[]>([...props.accounts]);
watch(() => props.accounts, (v) => { accountList.value = [...v]; });

// Quick-add akun GL baru langsung dari pemilih debit/kredit (mirror Approval Retensi).
type AddTarget = { rowId: number; field: 'debit_account_id' | 'credit_account_id' };
const addTarget = ref<AddTarget | null>(null);
const addForm = reactive({ code: '', name: '', type: 'asset', processing: false });
const accTypes: Array<{ id: string; label: string }> = [
    { id: 'asset', label: 'Aktiva' }, { id: 'liability', label: 'Kewajiban' }, { id: 'equity', label: 'Ekuitas' },
    { id: 'income', label: 'Pendapatan' }, { id: 'expense', label: 'Beban' },
];
function normalBalanceOf(type: string) { return (type === 'asset' || type === 'expense') ? 'D' : 'K'; }
function openAddAccount(rowId: number, field: AddTarget['field']) {
    addTarget.value = { rowId, field };
    addForm.code = ''; addForm.name = ''; addForm.type = 'asset'; addForm.processing = false;
}
function closeAddAccount() { addTarget.value = null; }
function submitAddAccount() {
    const t = addTarget.value;
    if (!t) return;
    const code = addForm.code.trim();
    const name = addForm.name.trim();
    if (!code || !name) { alert('Isi kode & nama akun.'); return; }
    addForm.processing = true;
    router.post(route('bs.accounts.store'), {
        code, name, type: addForm.type, normal_balance: normalBalanceOf(addForm.type), is_postable: true, is_active: true,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload({
                only: ['accounts'],
                onSuccess: (page: any) => {
                    const list: Acc[] = (page?.props?.accounts ?? props.accounts) as Acc[];
                    accountList.value = [...list];
                    const created = list.find((a) => String(a.code) === code);
                    if (created && forms[t.rowId]) { (forms[t.rowId] as any)[t.field] = created.id; }
                    closeAddAccount();
                },
                onFinish: () => { addForm.processing = false; },
            });
        },
        onError: () => { addForm.processing = false; },
    });
}

const payMethods = computed(() => (props.opsi?.payment_method ?? []) as string[]);

// ── Filter — DIKERJAKAN SERVER (50 baris per halaman) ──
const F = props.filters ?? {};
const fCustomer = ref<any>(F.customer ?? '');
const fTts = ref<any>(F.tts ?? '');
const fPay = ref(F.pay ?? '');
const fJournaled = ref(F.jurnal ?? '');
const fDateFrom = ref(F.dari ?? '');
const fDateTo = ref(F.sampai ?? '');
const fNilai = ref<any>(F.nilai !== undefined && F.nilai !== '' ? Number(F.nilai) : '');

const customerOptions = computed(() => (props.opsi?.customer ?? []).map((c) => ({ id: c, label: c })));
const ttsOptions = computed(() => (props.opsi?.tts_number ?? []).map((t) => ({ id: t, label: t })));
const nilaiOptions = computed(() => (props.opsi?.amount ?? []).map((n) => ({ id: Number(n), label: fmtRp(Number(n)) })));
const journalDateOptions = computed(() => (props.opsi?.journal_date ?? []).map((d) => ({ id: d, label: fmtDate(d) })));

function resetFilter() { fCustomer.value = ''; fTts.value = ''; fPay.value = ''; fJournaled.value = ''; fDateFrom.value = ''; fDateTo.value = ''; fNilai.value = ''; }

let jedaSaring: ReturnType<typeof setTimeout> | null = null;
watch([fCustomer, fTts, fPay, fJournaled, fDateFrom, fDateTo, fNilai], () => {
    if (jedaSaring) clearTimeout(jedaSaring);
    jedaSaring = setTimeout(() => router.get(window.location.pathname, {
        tab: props.tab, customer: fCustomer.value || undefined, tts: fTts.value || undefined, pay: fPay.value || undefined,
        jurnal: fJournaled.value || undefined, dari: fDateFrom.value || undefined, sampai: fDateTo.value || undefined,
        nilai: fNilai.value === '' || fNilai.value === null ? undefined : fNilai.value,
    }, { preserveScroll: true, preserveState: false, replace: true }), 350);
});

// Baris halaman ini — sudah disaring server.
const filtered = computed<Row[]>(() => props.rows.data ?? []);

function cekBaris(id: number): string | null {
    const f = forms[id];
    if (!f) return 'Baris ini tidak bisa diposting.';
    if (!f.debit_account_id || !f.credit_account_id) return 'Pilih akun Debit & Kredit terlebih dahulu.';
    if (String(f.debit_account_id) === String(f.credit_account_id)) return 'Akun Debit & Kredit tidak boleh sama.';
    return null;
}
function approve(id: number) {
    const f = forms[id];
    const e = cekBaris(id);
    if (e) { alert(e); return; }
    f.processing = true;
    router.post(route(`${rb.value}.post`, id), {
        journal_date: f.journal_date, debit_account_id: f.debit_account_id, credit_account_id: f.credit_account_id,
    }, { onSuccess: () => window.location.reload(), onFinish: () => { f.processing = false; } });
}

/* ── CENTANG & INPUT MASSAL ── isi tanggal & akun sekali, salin ke baris tercentang, lalu
   setujui / tolak sekaligus. Server menjalankan aksi satuan per baris (aturan sama persis). */
const bisaDicentang = (r: Row) => !isRejected.value && !r.journaled && !!forms[r.id];
const dipilih = ref<number[]>([]);
const barisDipilih = computed(() => filtered.value.filter((r) => dipilih.value.includes(r.id)));
const totalDipilih = computed(() => barisDipilih.value.reduce((a, r) => a + Number(r.amount || 0), 0));
const semuaDicentang = computed({
    get: () => filtered.value.filter(bisaDicentang).length > 0 && filtered.value.filter(bisaDicentang).every((r) => dipilih.value.includes(r.id)),
    set: (v: boolean) => { dipilih.value = v ? filtered.value.filter(bisaDicentang).map((r) => r.id) : []; },
});
const massal = reactive<{ journal_date: string; debit_account_id: any; credit_account_id: any }>({ journal_date: '', debit_account_id: '', credit_account_id: '' });
function terapkanMassal() {
    for (const r of barisDipilih.value) {
        const f = forms[r.id];
        if (!f) continue;
        if (massal.journal_date) f.journal_date = massal.journal_date;
        if (massal.debit_account_id) f.debit_account_id = massal.debit_account_id;
        if (massal.credit_account_id) f.credit_account_id = massal.credit_account_id;
    }
}
const labelTts = (r: Row) => `${r.tts_number}${r.customer ? ' · ' + r.customer : ''}`;
const prosesMassal = ref(false);
function setujuiMassal() {
    const rows = barisDipilih.value;
    if (!rows.length || prosesMassal.value) return;
    const salah = rows.map((r) => ({ r, e: cekBaris(r.id) })).filter((x) => x.e);
    if (salah.length) { alert(`${salah.length} TTS belum bisa diposting:\n\n` + salah.slice(0, 10).map((x) => `• ${labelTts(x.r)}: ${x.e}`).join('\n')); return; }
    if (!confirm(`Setujui & Posting ${rows.length} TTS (total ${fmtRp(totalDipilih.value)})?\n\nSetiap TTS dijurnal sendiri-sendiri dengan isiannya masing-masing.`)) return;
    prosesMassal.value = true;
    const items = rows.map((r) => ({ id: r.id, label: labelTts(r), journal_date: forms[r.id].journal_date, debit_account_id: forms[r.id].debit_account_id, credit_account_id: forms[r.id].credit_account_id }));
    router.post(route(`${rb.value}.bulk-post`), { items }, { preserveScroll: true, onFinish: () => { prosesMassal.value = false; } });
}
function tolakMassal() {
    const rows = barisDipilih.value;
    if (!rows.length || prosesMassal.value) return;
    const reason = window.prompt(`Tolak ${rows.length} TTS terpilih? Tidak dijurnal, pindah ke tab Ditolak (bisa dipulihkan).\n\nAlasan penolakan (opsional):`, '');
    if (reason === null) return;
    prosesMassal.value = true;
    router.post(route(`${rb.value}.bulk-reject`), { ids: rows.map((r) => r.id), labels: rows.map(labelTts), reason },
        { preserveScroll: true, onFinish: () => { prosesMassal.value = false; } });
}

// #164 — Tolak approval UTJ/DP (TTS belum dijurnal).
function reject(id: number) {
    if (!confirm('Tolak TTS UTJ/DP ini? TTS akan ditandai rejected.')) return;
    const reason = window.prompt('Alasan penolakan (opsional):', '') ?? '';
    const f = forms[id]; f.processing = true;
    router.post(route(`${rb.value}.reject`, id), { reason },
        { onSuccess: () => window.location.reload(), onFinish: () => { f.processing = false; } });
}

// Pulihkan TTS yang ditolak → kembali ke daftar Menunggu Approval.
const restoring = ref<number | null>(null);
function restore(id: number) {
    if (!confirm('Pulihkan TTS ini ke daftar Menunggu Approval? Status kembali menjadi posted.')) return;
    restoring.value = id;
    router.post(route(`${rb.value}.restore`, id), {},
        { onSuccess: () => window.location.reload(), onFinish: () => { restoring.value = null; } });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const hInputCls = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
// Select header: lebar TETAP & lega agar teks + chevron tidak terpotong (w-auto kerap menyusut ke "Semua").
const hSelCls = 'h-7 w-52 rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> {{ judul }}</h1>
            <p class="text-sm text-neutral-500">{{ subtitle ?? 'TTS yang sudah diposting ke Kuitansi. Untuk yang belum dijurnal: pilih akun Debit & Kredit lalu setujui — jurnal GL otomatis terposting.' }}</p>
        </div>

        <!-- Tab: Menunggu Approval / Ditolak -->
        <div class="flex w-fit gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
            <button @click="switchTab('pending')" class="rounded px-3 py-1.5 font-medium transition" :class="tab === 'pending' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Menunggu Approval <span class="opacity-70">({{ counts.pending }})</span></button>
            <button @click="switchTab('tanpa_unit')" class="rounded px-3 py-1.5 font-medium transition" :class="tab === 'tanpa_unit' ? 'bg-amber-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Tanpa Unit <span class="opacity-70">({{ counts.tanpa_unit }})</span></button>
            <button @click="switchTab('rejected')" class="rounded px-3 py-1.5 font-medium transition" :class="tab === 'rejected' ? 'bg-danger-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Ditolak <span class="opacity-70">({{ counts.rejected }})</span></button>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-neutral-500">{{ rows.total ?? 0 }} dari {{ jumlahSemua }} TTS</p>
            <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
        </div>

        <!-- TAB DITOLAK -->
        <TableTopScroll v-if="isRejected">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">Customer / TTS</th>
                        <th class="px-3 py-3">Cara Bayar</th>
                        <th class="px-3 py-3 text-right">Nilai</th>
                        <th class="px-3 py-3 w-36">Tgl Ditolak</th>
                        <th class="px-3 py-3">Alasan Penolakan</th>
                        <th class="px-3 py-3 w-32 text-center">Aksi</th>
                    </tr>
                    <!-- Filter melekat per-kolom -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"><div class="flex min-w-[12rem] flex-col gap-1"><SearchSelect v-model="fCustomer" :options="customerOptions" placeholder="Semua customer" /><SearchSelect v-model="fTts" :options="ttsOptions" placeholder="Semua TTS" /></div></th>
                        <th class="px-2 py-2"><select v-model="fPay" :class="[hSelCls, 'min-w-[10rem]']"><option value="">Semua</option><option v-for="p in payMethods" :key="p" :value="p">{{ p }}</option></select></th>
                        <th class="px-2 py-2"><div class="min-w-[10rem]"><SearchSelect v-model="fNilai" :options="nilaiOptions" placeholder="Semua nilai" /></div></th>
                        <th class="px-2 py-2"></th><th class="px-2 py-2"></th><th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800 align-top">
                    <tr v-if="!filtered.length"><td colspan="6" class="px-3 py-10"><EmptyState title="Belum ada TTS yang ditolak" description="TTS UTJ/DP yang Anda tolak akan muncul di sini beserta alasannya." /></td></tr>
                    <tr v-for="r in filtered" :key="r.id">
                        <td class="px-3 py-3">
                            <div class="font-semibold">{{ r.customer ?? '—' }}</div>
                            <div v-if="r.unit_info || r.unit_code" class="text-xs font-medium text-neutral-600 dark:text-neutral-300">🏠 {{ r.unit_info ?? r.unit_code }}</div>
                            <div class="text-xs text-neutral-500 font-mono">{{ r.tts_number }}<span v-if="r.booking"> · {{ r.booking }}</span><span v-if="r.received_date"> · {{ fmtDate(r.received_date) }}</span></div>
                        </td>
                        <td class="px-3 py-3"><Badge size="sm" variant="info">{{ r.payment_method }}</Badge></td>
                        <td class="px-3 py-3 text-right font-mono font-bold">{{ fmtRp(r.amount) }}</td>
                        <td class="px-3 py-3">{{ fmtDate(r.rejected_at) }}</td>
                        <td class="px-3 py-3"><span v-if="r.reject_reason" class="text-neutral-700 dark:text-neutral-200">{{ r.reject_reason }}</span><span v-else class="text-xs italic text-neutral-400">— tanpa alasan —</span></td>
                        <td class="px-3 py-3 text-center">
                            <Button size="sm" variant="secondary" :loading="restoring === r.id" @click="restore(r.id)"><template #icon-left><Undo2 class="h-4 w-4" /></template>Pulihkan</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <!-- Centang & input massal (tab Menunggu / Tanpa Unit — baris yang belum dijurnal). -->
        <div v-if="!isRejected && filtered.some(bisaDicentang)" class="card flex flex-wrap items-end gap-3 p-3 text-sm">
            <label class="flex items-center gap-2 font-medium"><input v-model="semuaDicentang" type="checkbox" class="h-4 w-4 rounded border-neutral-300" /> Pilih semua di halaman ini</label>
            <span class="text-neutral-500">{{ barisDipilih.length }} terpilih<span v-if="barisDipilih.length"> · {{ fmtRp(totalDipilih) }}</span></span>
            <template v-if="barisDipilih.length">
                <div><div class="mb-1 text-[11px] text-neutral-500">Tgl Jurnal</div><input v-model="massal.journal_date" type="date" :class="inputCls" /></div>
                <div class="w-64"><div class="mb-1 text-[11px] text-neutral-500">Akun Debit</div><SearchSelect v-model="massal.debit_account_id" :options="accountList" placeholder="Cari akun debit…" /></div>
                <div class="w-64"><div class="mb-1 text-[11px] text-neutral-500">Akun Kredit</div><SearchSelect v-model="massal.credit_account_id" :options="accountList" placeholder="Cari akun kredit…" /></div>
                <Button size="sm" variant="secondary" @click="terapkanMassal">Terapkan ke terpilih</Button>
                <div class="ml-auto flex gap-2">
                    <Button size="sm" variant="danger" :loading="prosesMassal" @click="tolakMassal">Tolak Terpilih ({{ barisDipilih.length }})</Button>
                    <Button size="sm" variant="success" :loading="prosesMassal" @click="setujuiMassal">Setujui &amp; Posting ({{ barisDipilih.length }})</Button>
                </div>
            </template>
        </div>

        <!-- TAB MENUNGGU APPROVAL -->
        <TableTopScroll v-if="!isRejected">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">Customer / TTS</th>
                        <th class="px-3 py-3">Cara Bayar</th>
                        <th class="px-3 py-3 text-right">Nilai</th>
                        <th class="px-3 py-3 w-36">Tgl Jurnal</th>
                        <th class="px-3 py-3 w-56">Akun Debit</th>
                        <th class="px-3 py-3 w-56">Akun Kredit</th>
                        <th class="px-3 py-3 w-44 text-center">Aksi / Status</th>
                    </tr>
                    <!-- Filter melekat per-kolom -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"><div class="flex min-w-[12rem] flex-col gap-1"><SearchSelect v-model="fCustomer" :options="customerOptions" placeholder="Semua customer" /><SearchSelect v-model="fTts" :options="ttsOptions" placeholder="Semua TTS" /></div></th>
                        <th class="px-2 py-2"><select v-model="fPay" :class="[hSelCls, 'min-w-[10rem]']"><option value="">Semua</option><option v-for="p in payMethods" :key="p" :value="p">{{ p }}</option></select></th>
                        <th class="px-2 py-2"><div class="min-w-[10rem]"><SearchSelect v-model="fNilai" :options="nilaiOptions" placeholder="Semua nilai" /></div></th>
                        <th class="px-2 py-2"><div class="flex min-w-[10rem] flex-col gap-1"><SearchSelect v-model="fDateFrom" :options="journalDateOptions" placeholder="Tgl dari" /><SearchSelect v-model="fDateTo" :options="journalDateOptions" placeholder="Tgl sampai" /></div></th>
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><select v-model="fJournaled" :class="[hSelCls, 'min-w-[10rem]']"><option value="">Semua</option><option value="belum">Belum dijurnal</option><option value="sudah">Sudah dijurnal</option></select></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800 align-top">
                    <tr v-if="!filtered.length"><td colspan="7" class="px-3 py-10"><EmptyState title="Tidak ada TTS sesuai filter" description="Ubah kriteria filter di atas." /></td></tr>
                    <tr v-for="r in filtered" :key="r.id" :class="dipilih.includes(r.id) ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
                        <td class="px-3 py-3">
                            <label v-if="bisaDicentang(r)" class="mb-1 inline-flex items-center gap-1.5 text-[11px] text-neutral-500"><input v-model="dipilih" type="checkbox" :value="r.id" class="h-4 w-4 rounded border-neutral-300" /> pilih</label>
                            <div class="font-semibold">{{ r.customer ?? '—' }}</div>
                            <div v-if="r.unit_info || r.unit_code" class="text-xs font-medium text-neutral-600 dark:text-neutral-300">🏠 {{ r.unit_info ?? r.unit_code }}</div>
                            <div class="text-xs text-neutral-500 font-mono">{{ r.tts_number }}<span v-if="r.booking"> · {{ r.booking }}</span><span v-if="r.received_date"> · {{ fmtDate(r.received_date) }}</span></div>
                        </td>
                        <td class="px-3 py-3"><Badge size="sm" variant="info">{{ r.payment_method }}</Badge></td>
                        <td class="px-3 py-3 text-right font-mono font-bold">{{ fmtRp(r.amount) }}</td>
                        <template v-if="!r.journaled">
                            <td class="px-3 py-3"><input v-model="forms[r.id].journal_date" type="date" :class="inputCls" /></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex-1"><SearchSelect v-model="forms[r.id].debit_account_id" :options="accountList" placeholder="Cari akun debit…" /></div>
                                    <button type="button" @click="openAddAccount(r.id, 'debit_account_id')" title="Tambah akun GL" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-neutral-300 text-neutral-500 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800"><Plus class="h-4 w-4" /></button>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex-1"><SearchSelect v-model="forms[r.id].credit_account_id" :options="accountList" placeholder="Cari akun kredit…" /></div>
                                    <button type="button" @click="openAddAccount(r.id, 'credit_account_id')" title="Tambah akun GL" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-neutral-300 text-neutral-500 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800"><Plus class="h-4 w-4" /></button>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <div class="flex flex-col gap-1.5">
                                    <Button size="sm" variant="success" :loading="forms[r.id].processing" @click="approve(r.id)">Setujui &amp; Posting</Button>
                                    <Button size="sm" variant="danger" :loading="forms[r.id].processing" @click="reject(r.id)">Tolak</Button>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-3">{{ fmtDate(r.journal_date) }}</td>
                            <td class="px-3 py-3 text-xs">{{ r.debit_account ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ r.credit_account ?? '—' }}</td>
                            <td class="px-3 py-3 text-center">
                                <Link v-if="r.journal_id" :href="route('bs.gl.journal.show', r.journal_id)" class="inline-flex items-center gap-1"><Badge size="sm" variant="success">✓ Dijurnal</Badge></Link>
                                <div class="mt-0.5 font-mono text-[11px] text-neutral-400">{{ r.journal_number }}</div>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>
        <Pagination :data="rows" satuan="TTS" />

        <!-- Modal quick-add akun GL baru (debit / kredit) -->
        <Teleport to="body">
            <div v-if="addTarget" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4" @click.self="closeAddAccount">
                <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <h3 class="mb-3 text-base font-semibold">Tambah Akun GL</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Kode Akun</label>
                            <input v-model="addForm.code" type="text" :class="inputCls" placeholder="mis. 110101" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nama Akun</label>
                            <input v-model="addForm.name" type="text" :class="inputCls" placeholder="mis. Bank BCA" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Tipe</label>
                            <SearchSelect v-model="addForm.type" :options="accTypes" :clearable="false" placeholder="Pilih tipe" />
                            <p class="mt-1 text-[11px] text-neutral-400">Saldo normal: {{ normalBalanceOf(addForm.type) === 'D' ? 'Debit (D)' : 'Kredit (K)' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button size="sm" variant="secondary" :disabled="addForm.processing" @click="closeAddAccount">Batal</Button>
                        <Button size="sm" variant="primary" :loading="addForm.processing" @click="submitAddAccount">Simpan &amp; Pilih</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <ScrollTopButton />
    </div>
</template>
