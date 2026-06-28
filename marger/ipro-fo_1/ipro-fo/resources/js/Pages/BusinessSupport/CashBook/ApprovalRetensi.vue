<script setup lang="ts">
import { reactive, ref, computed, watch, onMounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Landmark, RotateCcw, Plus, Undo2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import ScrollTopButton from '@/Components/UI/ScrollTopButton.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';

type Acc = { id: number; code: string; name: string; type?: string };
type Row = {
    id: number; amount: number; status: string; released_at: string | null; retention_date: string | null; akad_date: string | null; kpr_number: string | null; unit_code: string | null; unit_info: string | null; customer: string | null; note: string | null;
    rejected: boolean; reject_reason: string | null; rejected_at: string | null;
    journaled: boolean; journal_id: number | null; journal_number: string | null; journal_date: string | null;
    debit_account_id: number | null; debit_account: string | null; credit_account_id: number | null; credit_account: string | null;
    // #147 — default per-baris (editable):
    default_debit_account_id: number | null; default_debit_account: string | null; bank_account_label: string | null;
    default_ppn: number; default_uang_muka: number;
};
type AccountDefaults = {
    uang_muka_account_id: number | null; uang_muka_account: string | null;
    ppn_keluaran_account_id: number | null; ppn_keluaran_account: string | null;
    ppn_rate_num: number; ppn_rate_den: number;
};
const props = defineProps<{ rows: Row[]; accounts: Acc[]; accountDefaults: AccountDefaults; initialCustomer?: string | null }>();

// #162 — daftar akun lokal yang bisa di-refresh setelah quick-add akun GL baru.
// Tetap sinkron dengan prop (Inertia reuse component) lewat watcher.
const accountList = ref<Acc[]>([...props.accounts]);
watch(() => props.accounts, (v) => { accountList.value = [...v]; });

function todayStr() { return new Date().toISOString().slice(0, 10); }

// #147 — Form per-baris: akun debit (bank) + split kredit (Uang Muka + PPN), semua editable.
type RetForm = {
    journal_date: string;
    debit_account_id: any;
    uang_muka_account_id: any; uang_muka_amount: number;
    ppn_account_id: any; ppn_amount: number;
    processing: boolean;
};
const forms = reactive<Record<number, RetForm>>({});
for (const r of props.rows) {
    if (!r.journaled) {
        forms[r.id] = {
            // #162 — default tgl jurnal = tgl akad, fallback tgl retensi/dilepas, fallback hari ini.
            journal_date: r.akad_date ?? r.retention_date ?? r.released_at ?? todayStr(),
            debit_account_id: r.default_debit_account_id ?? '',
            uang_muka_account_id: props.accountDefaults.uang_muka_account_id ?? '',
            uang_muka_amount: r.default_uang_muka ?? 0,
            ppn_account_id: props.accountDefaults.ppn_keluaran_account_id ?? '',
            ppn_amount: r.default_ppn ?? 0,
            processing: false,
        };
    }
}

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Total kredit (Uang Muka + PPN) per baris; harus == nilai retensi agar balance.
function creditTotal(id: number) { const f = forms[id]; return Math.round(((Number(f.uang_muka_amount) || 0) + (Number(f.ppn_amount) || 0)) * 100) / 100; }
function balanced(r: Row) { return Math.round((Number(r.amount) || 0) * 100) / 100 === creditTotal(r.id); }

// Hitung ulang split PPN-inklusif 11% dari nilai retensi (tombol bantu, tetap bisa override manual).
function recalcSplit(r: Row) {
    const f = forms[r.id];
    const amt = Math.round((Number(r.amount) || 0) * 100) / 100;
    const ppn = Math.round((amt * props.accountDefaults.ppn_rate_num / props.accountDefaults.ppn_rate_den) * 100) / 100;
    f.ppn_amount = ppn;
    f.uang_muka_amount = Math.round((amt - ppn) * 100) / 100;
}

// #162 — SELISIH: |debit (nilai retensi) − total kredit|. Ditampilkan merah bila ≠ 0.
function selisih(r: Row) { return Math.round(Math.abs((Number(r.amount) || 0) - creditTotal(r.id)) * 100) / 100; }

// #162 — Quick-add akun GL baru langsung dari SearchSelect debit/kredit.
// POST bs.accounts.store (code+name+type+normal_balance) → refresh daftar akun → pre-select.
type AddTarget = { rowId: number; field: 'debit_account_id' | 'uang_muka_account_id' | 'ppn_account_id' };
const addTarget = ref<AddTarget | null>(null);
const addForm = reactive({ code: '', name: '', type: 'asset', processing: false });
const accTypes: Array<{ id: string; label: string }> = [
    { id: 'asset', label: 'Aktiva' }, { id: 'liability', label: 'Kewajiban' }, { id: 'equity', label: 'Ekuitas' },
    { id: 'income', label: 'Pendapatan' }, { id: 'expense', label: 'Beban' },
];
// Saldo normal turunan dari tipe: Aktiva/Beban = D, lainnya = K.
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
        code, name, type: addForm.type, normal_balance: normalBalanceOf(addForm.type),
        is_postable: true, is_active: true,
    }, {
        preserveScroll: true,
        // Refresh hanya prop accounts; watcher menyalin ke accountList.
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

// ── Filter ──
const fCustomer = ref<any>('');
// Auto-redirect "KPR Lunas" mengirim ?customer_id → server resolusi jadi nama → pra-filter ke customer itu.
onMounted(() => { if (props.initialCustomer) fCustomer.value = props.initialCustomer; });
// #163 — filter berdasarkan UNIT (unit_code), bukan nomor KPR.
const fUnit = ref<any>('');
const fStatus = ref('');
const fJournaled = ref('');
const fDateFrom = ref('');
const fDateTo = ref('');
const fNilai = ref<any>('');

function uniq(a: any[]) { return Array.from(new Set(a.filter((v) => v !== null && v !== undefined && v !== ''))); }
const customerOptions = computed(() => (uniq(props.rows.map((r) => r.customer)) as string[]).sort().map((c) => ({ id: c, label: c })));
const unitOptions = computed(() => (uniq(props.rows.map((r) => r.unit_code)) as string[]).sort().map((u) => ({ id: u, label: u })));
const nilaiOptions = computed(() => (uniq(props.rows.map((r) => r.amount)) as number[]).sort((a, b) => b - a).map((n) => ({ id: n, label: fmtRp(n) })));

function resetFilter() { fCustomer.value = ''; fUnit.value = ''; fStatus.value = ''; fJournaled.value = ''; fDateFrom.value = ''; fDateTo.value = ''; fNilai.value = ''; }

const filtered = computed(() => props.rows.filter((r) => {
    if (fCustomer.value && r.customer !== fCustomer.value) return false;
    if (fUnit.value && r.unit_code !== fUnit.value) return false;
    if (fStatus.value && r.status !== fStatus.value) return false;
    if (fJournaled.value === 'belum' && (r.journaled || r.rejected)) return false;
    if (fJournaled.value === 'sudah' && !r.journaled) return false;
    if (fJournaled.value === 'ditolak' && !r.rejected) return false;
    if (fDateFrom.value && (!r.journal_date || r.journal_date < fDateFrom.value)) return false;
    if (fDateTo.value && (!r.journal_date || r.journal_date > fDateTo.value)) return false;
    if (fNilai.value !== '' && fNilai.value !== null && Number(r.amount) !== Number(fNilai.value)) return false;
    return true;
}));

function approve(r: Row) {
    const f = forms[r.id];
    if (!f.debit_account_id) { alert('Pilih akun Debit (Bank) terlebih dahulu.'); return; }
    if (!f.uang_muka_account_id && !f.ppn_account_id) { alert('Pilih akun kredit (Uang Muka Penjualan / PPN Keluaran).'); return; }
    if (String(f.debit_account_id) === String(f.uang_muka_account_id) || String(f.debit_account_id) === String(f.ppn_account_id)) {
        alert('Akun Debit tidak boleh sama dengan akun Kredit.'); return;
    }
    if (!balanced(r)) {
        alert(`Tidak balance: total kredit (${fmtRp(creditTotal(r.id))}) harus sama dengan nilai retensi (${fmtRp(r.amount)}).`); return;
    }
    f.processing = true;
    router.post(route('bs.cashbook.approval-retensi.post', r.id), {
        journal_date: f.journal_date,
        debit_account_id: f.debit_account_id,
        uang_muka_account_id: f.uang_muka_account_id || null,
        uang_muka_amount: Number(f.uang_muka_amount) || 0,
        ppn_account_id: f.ppn_account_id || null,
        ppn_amount: Number(f.ppn_amount) || 0,
    }, { onSuccess: () => window.location.reload(), onFinish: () => { f.processing = false; } });
}

// Tolak retensi (belum dijurnal) → status ditolak + alasan (mirror Approval UTJ & DP).
function reject(r: Row) {
    if (!confirm('Tolak retensi ini? Retensi akan ditandai ditolak (tidak dijurnal).')) return;
    const reason = window.prompt('Alasan penolakan (opsional):', '') ?? '';
    const f = forms[r.id]; if (f) f.processing = true;
    router.post(route('bs.cashbook.approval-retensi.reject', r.id), { reason },
        { onSuccess: () => window.location.reload(), onFinish: () => { if (f) f.processing = false; } });
}
// Pulihkan retensi yang ditolak → kembali menunggu approval.
const restoring = ref<number | null>(null);
function restore(id: number) {
    if (!confirm('Pulihkan retensi ini ke daftar menunggu approval?')) return;
    restoring.value = id;
    router.post(route('bs.cashbook.approval-retensi.restore', id), {},
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
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-primary-600" /> Approval Retensi</h1>
            <p class="text-sm text-neutral-500">Retensi dari pencairan Proses KPR. Untuk yang belum dijurnal: akun Debit (Bank akad) &amp; split Kredit (Uang Muka Penjualan + PPN Keluaran 11%) sudah terisi otomatis — periksa/ubah lalu setujui. Jurnal GL otomatis terposting.</p>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-neutral-500">{{ filtered.length }} dari {{ rows.length }} retensi</p>
            <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
        </div>

        <TableTopScroll>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3">Customer / Unit</th>
                        <th class="px-3 py-3">Status</th>
                        <th class="px-3 py-3 text-right">Nilai Retensi</th>
                        <th class="px-3 py-3 w-36">Tgl Jurnal</th>
                        <th class="px-3 py-3 w-56">Akun Debit (Bank)</th>
                        <th class="px-3 py-3 w-80">Akun Kredit (split)</th>
                        <th class="px-3 py-3 w-44 text-center">Aksi / Status</th>
                    </tr>
                    <!-- Filter melekat per-kolom -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"><div class="flex min-w-[12rem] flex-col gap-1"><SearchSelect v-model="fCustomer" :options="customerOptions" placeholder="Semua customer" /><SearchSelect v-model="fUnit" :options="unitOptions" placeholder="Semua unit" /></div></th>
                        <th class="px-2 py-2"><select v-model="fStatus" :class="[hSelCls, 'min-w-[10rem]']"><option value="">Semua</option><option value="held">Held</option><option value="partial">Partial</option><option value="released">Released</option></select></th>
                        <th class="px-2 py-2"><div class="min-w-[10rem]"><SearchSelect v-model="fNilai" :options="nilaiOptions" placeholder="Semua nilai" /></div></th>
                        <th class="px-2 py-2"><div class="flex min-w-[9.5rem] flex-col gap-1"><input v-model="fDateFrom" type="date" :class="hInputCls" title="Tgl jurnal dari" /><input v-model="fDateTo" type="date" :class="hInputCls" title="Tgl jurnal sampai" /></div></th>
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><select v-model="fJournaled" :class="[hSelCls, 'min-w-[10rem]']"><option value="">Semua</option><option value="belum">Belum dijurnal</option><option value="sudah">Sudah dijurnal</option><option value="ditolak">Ditolak</option></select></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800 align-top">
                    <tr v-if="!filtered.length"><td colspan="7" class="px-3 py-10"><EmptyState title="Tidak ada retensi sesuai filter" description="Ubah kriteria filter di atas." /></td></tr>
                    <tr v-for="r in filtered" :key="r.id">
                        <td class="px-3 py-3">
                            <div class="font-semibold">{{ r.customer ?? '—' }}</div>
                            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ r.unit_info ?? r.unit_code ?? '—' }}</div>
                            <div class="text-[11px] text-neutral-400">{{ r.kpr_number ?? '—' }}<span v-if="r.released_at"> · dilepas {{ fmtDate(r.released_at) }}</span></div>
                        </td>
                        <td class="px-3 py-3"><Badge size="sm" :variant="r.status === 'released' ? 'success' : 'warning'">{{ r.status }}</Badge></td>
                        <td class="px-3 py-3 text-right font-mono font-bold">{{ fmtRp(r.amount) }}</td>
                        <!-- Ditolak -->
                        <template v-if="r.rejected">
                            <td class="px-3 py-3 text-xs">{{ fmtDate(r.rejected_at) }}</td>
                            <td class="px-3 py-3 text-xs" colspan="2"><Badge size="sm" variant="danger">Ditolak</Badge><span v-if="r.reject_reason" class="ml-1 text-neutral-500">— {{ r.reject_reason }}</span></td>
                            <td class="px-3 py-3 text-center"><Button size="sm" variant="secondary" :loading="restoring === r.id" @click="restore(r.id)"><template #icon-left><Undo2 class="h-4 w-4" /></template>Pulihkan</Button></td>
                        </template>
                        <!-- Belum dijurnal → form approve / tolak -->
                        <template v-else-if="!r.journaled">
                            <td class="px-3 py-3"><input v-model="forms[r.id].journal_date" type="date" :class="inputCls" /></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex-1"><SearchSelect v-model="forms[r.id].debit_account_id" :options="accountList" placeholder="Cari akun debit…" /></div>
                                    <button type="button" @click="openAddAccount(r.id, 'debit_account_id')" title="Tambah akun GL" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-neutral-300 text-neutral-500 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800"><Plus class="h-4 w-4" /></button>
                                </div>
                                <p v-if="r.bank_account_label" class="mt-1 text-[11px] text-neutral-400">Bank akad: {{ r.bank_account_label }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="space-y-2">
                                    <div class="grid grid-cols-[1fr,2rem,7rem] gap-1.5 items-center">
                                        <SearchSelect v-model="forms[r.id].uang_muka_account_id" :options="accountList" placeholder="Uang Muka Penjualan…" />
                                        <button type="button" @click="openAddAccount(r.id, 'uang_muka_account_id')" title="Tambah akun GL" class="flex h-9 w-8 items-center justify-center rounded-lg border border-neutral-300 text-neutral-500 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800"><Plus class="h-4 w-4" /></button>
                                        <MoneyInput v-model="forms[r.id].uang_muka_amount" />
                                    </div>
                                    <div class="grid grid-cols-[1fr,2rem,7rem] gap-1.5 items-center">
                                        <SearchSelect v-model="forms[r.id].ppn_account_id" :options="accountList" placeholder="PPN Keluaran…" />
                                        <button type="button" @click="openAddAccount(r.id, 'ppn_account_id')" title="Tambah akun GL" class="flex h-9 w-8 items-center justify-center rounded-lg border border-neutral-300 text-neutral-500 hover:bg-neutral-50 hover:text-primary-600 dark:border-neutral-700 dark:hover:bg-neutral-800"><Plus class="h-4 w-4" /></button>
                                        <MoneyInput v-model="forms[r.id].ppn_amount" />
                                    </div>
                                    <div class="flex items-center justify-between text-[11px]">
                                        <button type="button" @click="recalcSplit(r)" class="text-primary-600 hover:underline">Hitung ulang 11%</button>
                                        <span :class="balanced(r) ? 'text-success-600' : 'text-danger-600 font-semibold'">
                                            Kredit: {{ fmtRp(creditTotal(r.id)) }}<span v-if="!balanced(r)"> ≠ {{ fmtRp(r.amount) }}</span>
                                        </span>
                                    </div>
                                    <div v-if="selisih(r) > 0" class="text-[11px] font-semibold text-danger-600">Selisih: {{ fmtRp(selisih(r)) }}</div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <div class="flex flex-col gap-1.5">
                                    <Button size="sm" variant="success" :disabled="!balanced(r)" :loading="forms[r.id].processing" @click="approve(r)">Setujui &amp; Posting</Button>
                                    <Button size="sm" variant="danger" :loading="forms[r.id].processing" @click="reject(r)">Tolak</Button>
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-3">{{ fmtDate(r.journal_date) }}</td>
                            <td class="px-3 py-3 text-xs">{{ r.debit_account ?? '—' }}</td>
                            <td class="px-3 py-3 text-xs">{{ r.credit_account ?? '—' }}</td>
                            <td class="px-3 py-3 text-center">
                                <Link v-if="r.journal_id" :href="route('bs.gl.journal.show', r.journal_id)" class="inline-flex items-center gap-1 text-xs font-medium text-success-600 hover:underline"><Badge size="sm" variant="success">✓ Dijurnal</Badge></Link>
                                <div class="mt-0.5 font-mono text-[11px] text-neutral-400">{{ r.journal_number }}</div>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <!-- #162 — Modal quick-add akun GL baru -->
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
