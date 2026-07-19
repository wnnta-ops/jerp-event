<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Save, HandCoins, Plus, Trash2, Check, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    suppliers: Array<any>;
    categories: Array<any>;
    pphAccounts: Array<any>;
    pphDefaults: Record<string, number>;
    ppnAccount: any;
    pphBorneExpense: any;
    accounts: Array<any>;
    costCenters: Array<any>;
    departments: Array<any>;
    ledgers: Array<any>;
    locations: Array<any>;
    units: Array<any>;
    trxTypes: Record<string, string>;
}>();

const steps = ['Pilih Supplier', 'Input Transaksi', 'Detil Tagihan', 'Struktur', 'Selesai'];
const step = ref(1);
const today = new Date().toISOString().slice(0, 10);
const supplierSearch = ref('');

const form = useForm<any>({
    supplier_id: '', category_id: '', trx_type: 'normal', supplier_invoice_no: '', manual_no: '',
    invoice_date: today, receive_date: today, term_days: '', due_date: '',
    description: '', dpp: 0,
    ppn_mode: 'none', ppn_pct: 11, ppn_base: 0, ppn_incl: false, // #140 · ppn_incl: false=Exclude PPN, true=Include PPN
    pph_mode: 'none', pph_type: '', pph_pct: 0, pph_base: 0, pph_account_id: '', // #92/#94 — default tarif PPh 0% (dipungut & ditanggung)
    lines: [], schedules: [], journal_lines: [],
});

// Pembulatan ke RUPIAH PENUH (round) untuk semua perhitungan; PPh Pasal 4(2) → rounddown (floor).
const r0 = (v: any) => Math.round(Number(v) || 0);
const fl0 = (v: any) => Math.floor(Number(v) || 0);
const dppR = computed(() => r0(form.dpp)); // Nilai Transaksi dibulatkan ke rupiah penuh

// #94: tarif PPh default per jenis (PPh 21 = 2,5% · PPh 23 = 2% · PPh Pasal 4(2) = 1,75%).
// Auto-isi saat jenis dipilih; field tarif (pph_pct) tetap bisa diubah manual oleh user.
const PPH_RATES: Record<string, number> = { pph21: 2.5, pph23: 2.0, pph4_2: 1.75 };
watch(() => form.pph_type, (t) => {
    // #94: jenis PPh → auto-isi tarif default (tetap bisa diubah manual di input tarif).
    if (t && PPH_RATES[t] !== undefined) form.pph_pct = PPH_RATES[t];
    // #113: jenis PPh → otomatis pilih akun PPh default (tetap bisa diubah manual lewat dropdown).
    if (t && props.pphDefaults?.[t]) form.pph_account_id = props.pphDefaults[t];
});
// #92/#PPh-acuan: saat masuk mode dipungut/ditanggung, default nilai objek PPh = ACUAN (Objek PPN bila "Dengan PPN", selain itu DPP).
watch(() => form.pph_mode, (m) => { if ((m === 'withheld' || m === 'borne') && !Number(form.pph_base)) form.pph_base = pphRefBase.value; });
// #140: saat masuk mode "Dengan PPN", default nilai objek PPN = DPP (bisa diubah).
watch(() => form.ppn_mode, (m) => { if (m === 'with') form.ppn_base = Number(form.dpp) || 0; });
// #140: saat DPP berubah & objek PPN masih = DPP lama (atau 0), ikutkan default ke DPP baru — kecuali user sudah override manual.
watch(() => form.dpp, (val, old) => {
    if (form.ppn_mode === 'with' && (!Number(form.ppn_base) || Number(form.ppn_base) === Number(old))) form.ppn_base = Number(val) || 0;
});
// #incl-ppn: saat toggle Include/Exclude, konversi nominal DPP & objek PPN LANGSUNG di field.
// Include = ekstrak nilai NET (gross ÷ (1+tarif)); Exclude = kembalikan ke gross (× (1+tarif)).
watch(() => form.ppn_incl, (incl: boolean, prev: boolean) => {
    if (form.ppn_mode !== 'with' || incl === prev) return;
    const div = 1 + (Number(form.ppn_pct) || 0) / 100;
    if (div <= 0) return;
    const conv = (v: number) => r0(incl ? v / div : v * div);
    form.dpp = conv(Number(form.dpp) || 0);
    form.ppn_base = conv(Number(form.ppn_base) || 0);
});

const filteredSuppliers = computed(() => {
    const q = supplierSearch.value.toLowerCase();
    return q ? props.suppliers.filter((s: any) => (s.name + s.code).toLowerCase().includes(q)) : props.suppliers;
});
const supplier = computed(() => props.suppliers.find((s: any) => s.id === form.supplier_id));
const category = computed(() => props.categories.find((c: any) => c.id === form.category_id));
// #140: PPN dihitung dari NILAI OBJEK PPN (default = DPP, clamp ≤ DPP), bukan dari seluruh DPP.
const ppnOn = computed(() => form.ppn_mode === 'with');
const ppnBase = computed(() => ppnOn.value ? Math.min(r0(form.ppn_base) || dppR.value, dppR.value) : 0);
const ppnNonObject = computed(() => ppnOn.value ? Math.max(0, dppR.value - ppnBase.value) : 0);
// #incl-ppn: pembagian ÷(1+tarif) dilakukan LANGSUNG ke field DPP & objek PPN saat toggle Include (lihat watch),
// jadi kalkulasi di sini selalu pakai nilai field apa adanya (PPN = objek PPN × tarif). Dibulatkan ke rupiah penuh.
const ppn = computed(() => ppnOn.value ? r0(ppnBase.value * (Number(form.ppn_pct) || 0) / 100) : 0);
// #PPh-acuan: acuan objek PPh = OBJEK PPN (bila "Dengan PPN"), selain itu DPP.
const pphRefBase = computed(() => ppnOn.value ? ppnBase.value : dppR.value);
// Objek PPh default mengikuti acuan (Objek PPN/DPP) selama belum di-override manual.
watch(pphRefBase, (val, old) => {
    if ((form.pph_mode === 'withheld' || form.pph_mode === 'borne') && (!Number(form.pph_base) || Number(form.pph_base) === Number(old))) {
        form.pph_base = Number(val) || 0;
    }
});
// #92: PPh dihitung dari NILAI OBJEK PPh (default = Objek PPN, clamp ≤ DPP), bukan dari total transaksi.
// PPh aktif untuk mode Dipungut (withheld) maupun Ditanggung (borne).
const pphOn = computed(() => form.pph_mode === 'withheld' || form.pph_mode === 'borne');
const pphBase = computed(() => pphOn.value ? Math.min(r0(form.pph_base) || pphRefBase.value, dppR.value) : 0);
const pphNonObject = computed(() => Math.max(0, dppR.value - pphBase.value));
// Nilai PPh terhitung (utk JURNAL). PPh Pasal 4(2) → rounddown (floor); jenis lain → round. Selaras backend.
const pphJournal = computed(() => {
    if (!pphOn.value) return 0;
    const raw = pphBase.value * (Number(form.pph_pct) || 0) / 100;
    return form.pph_type === 'pph4_2' ? fl0(raw) : r0(raw);
});
// TAMPILAN ringkasan: Dipotong = nilai PPh; Ditanggung (borne) → 0 (tak mengurangi pembayaran; supplier dibayar penuh).
// Jurnal Ditanggung tetap mencatat Dr Biaya PPh / Cr Hutang PPh (pakai pphJournal).
const pph = computed(() => (form.pph_mode === 'borne' ? 0 : pphJournal.value));
const PPH_LABELS: Record<string, string> = { pph21: 'PPh 21', pph23: 'PPh 23', pph4_2: 'PPh Pasal 4(2)' };
const pphLabel = computed(() => form.pph_mode === 'borne' ? 'PPh ditanggung' : 'PPh dipotong');
const total = computed(() => dppR.value + ppn.value);
// Dipotong → PPh dipotong dari pembayaran supplier (net = total − PPh).
// Ditanggung → supplier dibayar PENUH (net = total); PPh jadi beban perusahaan.
const net = computed(() => form.pph_mode === 'borne' ? total.value : total.value - pph.value);
// Tgl. Jatuh Tempo = Tgl. Terima (receive_date) + Jatuh Tempo (hari); fallback ke Tgl. Input bila Tgl. Terima kosong.
const dueDate = computed(() => {
    const days = Number(form.term_days) || 0;
    const base = form.receive_date || form.invoice_date;
    if (!base || days <= 0) return '';
    return new Date(new Date(base).getTime() + days * 86400000).toISOString().slice(0, 10);
});
// Sinkron ke form.due_date agar nilai yang tersimpan = tampilan kotak Tgl. Jatuh Tempo.
watch(dueDate, (v) => { form.due_date = v; }, { immediate: true });
const linesSum = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
const schedSum = computed(() => form.schedules.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
const jurnalDebit = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.debit) || 0), 0));
const jurnalKredit = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.credit) || 0), 0));
const jurnalNilai = computed(() => form.journal_lines.reduce((s: number, l: any) => s + (Number(l.nilai) || 0), 0));
const jurnalBalanced = computed(() => Math.abs(jurnalDebit.value - jurnalKredit.value) < 1 && jurnalDebit.value > 0);
const activeJournalLines = computed(() => form.journal_lines.filter((l: any) => l.account_id && ((Number(l.debit) || 0) > 0 || (Number(l.credit) || 0) > 0)));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtNum(v: number) { return Number(v ?? 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function pickSupplier(s: any) { form.supplier_id = s.id; step.value = 2; }
function addLine() { form.lines.push({ description: form.description || '', qty: 1, unit_price: 0, amount: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function addSchedule() { form.schedules.push({ due_date: dueDate.value, amount: 0 }); }
function removeSchedule(i: number) { form.schedules.splice(i, 1); }

function accountLabel(id: number) { const a = props.accounts.find((x: any) => x.id === id); return a ? `${a.code} — ${a.name}` : '—'; }
function accountName(id: number | null) { const a = props.accounts.find((x: any) => x.id === id); return a ? a.name : ''; }
function costCenterLabel(id: number | null) { if (!id) return ''; const c = props.costCenters.find((x: any) => x.id === id); return c ? c.code : ''; }
function dimSummary(l: any) {
    const parts = [costCenterLabel(l.cost_center_id)].filter(Boolean);
    if (l.currency && l.currency !== 'IDR') parts.push(`${l.currency}@${l.kurs}`);
    return parts.join(' · ');
}
function dimDefaults() { return { cost_center_id: null, currency: 'IDR', kurs: 1 }; }
// Satu baris jurnal ala eProperty: input satu "Nilai Transaksi" + pilih sisi (Debit/Kredit) lewat radio.
// debit/credit tetap dijaga sinkron untuk submit ke backend & perhitungan balance.
function mkJournalLine(account_id: number | null, description: string, amount: number, side: 'debit' | 'credit') {
    const v = Number(amount) || 0;
    return { account_id: account_id ?? null, ...dimDefaults(), description: description || '', nilai: v, side, debit: side === 'debit' ? v : 0, credit: side === 'credit' ? v : 0 };
}
function applyAmount(l: any) {
    const v = Number(l.nilai) || 0;
    if (l.side === 'credit') { l.credit = v; l.debit = 0; }
    else { l.side = 'debit'; l.debit = v; l.credit = 0; }
}
function setSide(l: any, side: 'debit' | 'credit') { l.side = side; applyAmount(l); }
function defaultJournal() {
    const c = category.value;
    const out: any[] = [];
    if (c?.debit_id) out.push(mkJournalLine(c.debit_id, form.description || '', dppR.value, 'debit'));
    if (form.ppn_mode === 'with' && ppn.value > 0 && props.ppnAccount?.id) out.push(mkJournalLine(props.ppnAccount.id, 'PPN Masukan', ppn.value, 'debit'));
    // PPh DITANGGUNG: Dr Biaya PPh <jenis> (beban perusahaan) — akun default bisa diubah; supplier tetap dibayar penuh.
    if (form.pph_mode === 'borne' && pphJournal.value > 0 && props.pphBorneExpense?.id) out.push(mkJournalLine(props.pphBorneExpense.id, 'Biaya ' + (PPH_LABELS[form.pph_type] || 'PPh') + ' ditanggung', pphJournal.value, 'debit'));
    // Hutang ke supplier: Dipungut → net (DPP+PPN−PPh) ; Ditanggung → penuh (DPP+PPN).
    if (c?.payable_id) out.push(mkJournalLine(c.payable_id, 'Hutang ' + (supplier.value?.name || 'supplier'), form.pph_mode === 'borne' ? total.value : net.value, 'credit'));
    // Cr Hutang PPh <jenis>: Dipotong (dari net supplier) & Ditanggung (lawan Biaya PPh) — pakai pphJournal.
    if (pphOn.value && pphJournal.value > 0 && form.pph_account_id) out.push(mkJournalLine(Number(form.pph_account_id), form.pph_mode === 'borne' ? 'Hutang ' + (PPH_LABELS[form.pph_type] || 'PPh') + ' ditanggung' : 'PPh dipotong', pphJournal.value, 'credit'));
    return out;
}
function addJournalLine() { form.journal_lines.push(mkJournalLine(null, '', 0, 'debit')); }
function addJournalLines(n: number) { for (let k = 0; k < n; k++) addJournalLine(); }
function removeJournalLine(i: number) { form.journal_lines.splice(i, 1); }
function recalcJournal() { form.journal_lines = defaultJournal(); }

// Saat masuk Detil Tagihan, default 1 baris (Uraian = Keterangan, Jumlah = DPP) jika kosong.
// Saat masuk Struktur, default 1 termin = net jika kosong.
watch(step, (s) => {
    if (s === 3 && form.lines.length === 0) form.lines.push({ description: form.description || '', qty: 1, unit_price: dppR.value, amount: dppR.value });
    if (s === 4) {
        if (form.journal_lines.length === 0) form.journal_lines = defaultJournal();
        // Jadwal pembayaran dihilangkan dari registrasi — backend membuat default 1 termin = net otomatis.
    }
});

function canNext() {
    if (step.value === 1) return !!form.supplier_id;
    if (step.value === 2) return !!form.category_id && !!form.supplier_invoice_no && Number(form.dpp) > 0 && !!form.description && Number(form.term_days) > 0;
    if (step.value === 4) return jurnalBalanced.value;
    return true;
}
function next() { if (canNext() && step.value < 5) step.value++; }
function prev() { if (step.value > 1) step.value--; }
function submit() { form.post(route('bs.ap.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const dimCls = 'h-8 w-full rounded-md border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';
const cellArea = 'w-full resize-none overflow-hidden rounded-md border border-neutral-300 bg-white px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-900';
// Textarea auto-tinggi: tinggi mengikuti jumlah karakter/baris isi — Keterangan tak terpotong.
const vAutosize = {
    mounted(el: HTMLTextAreaElement) {
        const fit = () => { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; };
        (el as any).__fit = fit;
        el.addEventListener('input', fit);
        nextTick(fit);
    },
    updated(el: HTMLTextAreaElement) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; },
    unmounted(el: HTMLTextAreaElement) { el.removeEventListener('input', (el as any).__fit); },
};
</script>
<template>
    <div :class="['space-y-4', step === 4 ? 'max-w-none' : 'max-w-4xl']">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> Registrasi Invoice Hutang</h1>
            <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>

        <!-- Stepper -->
        <div class="card flex flex-wrap items-center gap-1 p-3 text-sm">
            <template v-for="(s, i) in steps" :key="i">
                <div class="flex items-center gap-2 rounded-lg px-2.5 py-1" :class="step === i+1 ? 'bg-primary-600 text-white' : (step > i+1 ? 'text-success-600' : 'text-neutral-400')">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs" :class="step > i+1 ? 'bg-success-100 dark:bg-success-900/40' : (step === i+1 ? 'bg-white/20' : 'bg-neutral-100 dark:bg-neutral-800')"><Check v-if="step > i+1" class="h-3 w-3" /><span v-else>{{ i+1 }}</span></span>
                    {{ s }}
                </div>
                <ArrowRight v-if="i < steps.length-1" class="h-3.5 w-3.5 text-neutral-300" />
            </template>
        </div>

        <!-- Step 1: Pilih Supplier -->
        <div v-show="step === 1" class="card p-5">
            <div class="relative mb-3"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="supplierSearch" placeholder="Cari supplier / kontraktor…" :class="[inputCls,'pl-9']" /></div>
            <div class="max-h-96 divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                <button v-for="s in filteredSuppliers" :key="s.id" type="button" @click="pickSupplier(s)" class="flex w-full items-center justify-between px-2 py-2.5 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="form.supplier_id === s.id ? 'bg-primary-50 dark:bg-primary-900/20' : ''">
                    <span><span class="font-mono text-xs text-neutral-400">{{ s.code }}</span> <span class="font-medium">{{ s.name }}</span><span v-if="s.category" class="ml-2 rounded bg-neutral-100 px-1.5 text-[10px] text-neutral-500 dark:bg-neutral-800">{{ s.category }}</span></span>
                    <ArrowRight class="h-4 w-4 text-neutral-300" />
                </button>
            </div>
        </div>

        <!-- Step 2: Input Transaksi -->
        <div v-show="step === 2" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div class="md:col-span-3 rounded-lg bg-neutral-50 px-4 py-2 text-sm dark:bg-neutral-800">Supplier: <span class="font-semibold">{{ supplier?.name }}</span></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Invoice *</label><select v-model="form.category_id" required :class="inputCls"><option value="">— pilih kategori —</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option></select>
                    <p v-if="category" class="mt-1 text-[11px] text-neutral-400">Dr {{ category.debit }} · Cr {{ category.payable }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe Transaksi *</label><select v-model="form.trx_type" :class="inputCls"><option v-for="(label,val) in trxTypes" :key="val" :value="val">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Invoice Supplier *</label><input v-model="form.supplier_invoice_no" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Input *</label><input v-model="form.invoice_date" type="date" disabled title="Otomatis tanggal input, tidak bisa diubah" class="h-9 w-full cursor-not-allowed rounded-lg border border-neutral-300 bg-neutral-100 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800" /><p class="mt-1 text-[10px] text-neutral-400">Otomatis tanggal input — tidak bisa diubah.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Terima</label><input v-model="form.receive_date" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo (hari) <span class="text-danger-500">*</span></label><input v-model.number="form.term_days" type="number" min="1" required placeholder="mis. 30" :class="[inputCls,'text-right']" /><p v-if="!(Number(form.term_days) > 0)" class="mt-1 text-[11px] text-danger-500">Wajib diisi — tentukan jangka waktu jatuh tempo.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Jatuh Tempo</label><input :value="dueDate" type="date" disabled title="Otomatis: Tgl. Terima + Jatuh Tempo (hari)" class="h-9 w-full cursor-not-allowed rounded-lg border border-neutral-300 bg-neutral-100 px-3 text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-800" /><p class="mt-1 text-[10px] text-neutral-400">Tgl. Terima + Jatuh Tempo (hari) — tidak bisa diubah.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="inputCls" /></div>
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /></div>
            </div>
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Transaksi *</label><MoneyInput v-model="form.dpp" placeholder="0" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPN</label><div class="flex gap-2"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="none" class="sr-only" />Tanpa PPN</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='with' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="with" class="sr-only" />Dengan PPN</label></div>
                    <div v-if="form.ppn_mode==='with'" class="mt-2 space-y-1.5">
                        <!-- #incl-ppn: Exclude = PPN di atas objek · Include = objek sudah termasuk PPN (÷ 1+tarif) -->
                        <div class="flex gap-1.5">
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="!form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="false" class="sr-only" />Exclude PPN</label>
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="true" class="sr-only" />Include PPN</label>
                        </div>
                        <input v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" title="Tarif PPN %" class="h-8 w-full rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                        <!-- #140/#incl-ppn: objek PPN (Include → field DPP & objek PPN sudah di-ekstrak ke nilai NET via watch) -->
                        <MoneyInput v-model="form.ppn_base" placeholder="Nilai objek PPN" />
                        <p class="text-[10px] text-neutral-400">Objek PPN: {{ fmtRp(ppnBase) }} · Non-objek: {{ fmtRp(ppnNonObject) }}</p>
                    </div></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPh</label><div class="flex gap-1.5"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="none" class="sr-only" />Tanpa PPh</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='withheld' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="withheld" class="sr-only" />Dipotong</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='borne' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="borne" class="sr-only" />Ditanggung</label></div>
                    <div v-if="form.pph_mode==='withheld' || form.pph_mode==='borne'" class="mt-2 space-y-1.5">
                        <!-- #94: jenis PPh → tarif default (bisa diubah manual) -->
                        <select v-model="form.pph_type" class="h-8 w-full rounded-lg border border-neutral-300 px-1 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">— jenis PPh —</option>
                            <option value="pph21">PPh 21 (2,5%)</option>
                            <option value="pph23">PPh 23 (2%)</option>
                            <option value="pph4_2">PPh Pasal 4 Ayat 2 (1,75%)</option>
                        </select>
                        <div class="flex gap-1">
                            <input v-model.number="form.pph_pct" type="number" step="0.25" min="0" max="100" title="Tarif % (bisa diubah manual)" class="h-8 w-16 rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                            <select v-model="form.pph_account_id" class="h-8 flex-1 rounded-lg border border-neutral-300 px-1 text-xs dark:border-neutral-700 dark:bg-neutral-900"><option value="">Akun PPh…</option><option v-for="a in pphAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                        </div>
                        <!-- #92: nilai objek PPh (default = Objek PPN bila Dengan PPN, selain itu DPP); PPh dihitung dari nilai ini saja -->
                        <MoneyInput v-model="form.pph_base" placeholder="Nilai objek PPh" />
                        <p class="text-[10px] text-neutral-400">Objek PPh: {{ fmtRp(pphBase) }} · Non-objek: {{ fmtRp(pphNonObject) }}</p>
                    </div></div>
                <div class="md:col-span-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800 sm:grid-cols-4">
                    <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(dppR) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                    <div><div class="text-xs text-neutral-400">{{ pphLabel }}</div><div class="font-mono" :class="pph > 0 ? 'text-danger-600' : 'text-neutral-500'">{{ fmtRp(pph) }}</div></div>
                    <div><div class="text-xs text-neutral-400">Dibayar (net)</div><div class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(net) }}</div></div>
                </div>
            </div>
        </div>

        <!-- Step 3: Detil Tagihan -->
        <div v-show="step === 3" class="card p-5">
            <div class="mb-2 flex items-center justify-between"><h2 class="font-semibold">Detil Tagihan</h2><button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button></div>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1">Uraian</th><th class="w-20 py-1 text-right">Qty</th><th class="w-32 py-1 text-right">Harga</th><th class="w-36 py-1 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                <tbody>
                    <tr v-for="(l,i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1 pr-2"><input v-model="l.description" :class="inputCls" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0" :class="[inputCls,'text-right']" @input="l.amount = (Number(l.qty)||0)*(Number(l.unit_price)||0)" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.unit_price" type="number" step="1" min="0" :class="[inputCls,'text-right']" @input="l.amount = (Number(l.qty)||0)*(Number(l.unit_price)||0)" /></td>
                        <td class="py-1 pr-2"><input v-model.number="l.amount" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                        <td class="py-1 text-center"><button type="button" @click="removeLine(i)" class="text-danger-500"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!form.lines.length" class="py-4 text-center text-sm text-neutral-400">Opsional — rincian tagihan. Total DPP {{ fmtRp(form.dpp) }} bisa dipecah ke baris.</p>
            <div v-else class="mt-2 flex justify-end gap-6 text-sm"><span class="text-neutral-500">Total baris: <b class="font-mono">{{ fmtRp(linesSum) }}</b></span><span :class="Math.abs(linesSum - form.dpp) < 1 ? 'text-success-600' : 'text-amber-600'">DPP: {{ fmtRp(form.dpp) }}</span></div>
        </div>

        <!-- Step 4: Struktur (Jurnal Dr/Cr) -->
        <div v-show="step === 4" class="space-y-4">
            <!-- Struktur Jurnal — tabel padat (mirip layout eProperty BO) -->
            <div class="card p-5">
                <div class="mb-1 flex items-center justify-between">
                    <h2 class="font-semibold">Struktur Jurnal (Dr / Cr)</h2>
                    <button type="button" @click="recalcJournal" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700">Hitung ulang</button>
                </div>
                <p class="mb-2 text-[11px] text-neutral-400">Tips: Kosongkan kolom Account &amp; Nilai Transaksi untuk mengabaikan baris tersebut saat proses. Total Debit harus sama dengan Total Kredit.</p>
                <div class="rounded-lg border border-neutral-200 dark:border-neutral-800">
                    <table class="w-full table-fixed border-collapse text-sm">
                        <colgroup>
                            <col style="width:4%" /><col style="width:27%" /><col style="width:14%" /><col style="width:14%" /><col style="width:9%" /><col style="width:11%" /><col style="width:9%" /><col style="width:9%" /><col style="width:3%" />
                        </colgroup>
                        <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800">
                            <tr>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">No</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Account</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Cost Center</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Keterangan</th>
                                <th class="border-b border-neutral-200 px-2 py-2 dark:border-neutral-700">Mata Uang / Kurs</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Nilai Transaksi</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Debit</th>
                                <th class="border-b border-neutral-200 px-2 py-2 text-right dark:border-neutral-700">Kredit</th>
                                <th class="border-b border-neutral-200 px-1 py-2 dark:border-neutral-700"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l,i) in form.journal_lines" :key="i" class="border-b border-neutral-100 align-top dark:border-neutral-800">
                                <td class="px-2 py-2 font-mono text-neutral-400">{{ i+1 }}</td>
                                <td class="px-2 py-2">
                                    <SearchSelect v-model="l.account_id" :options="accounts" placeholder="— pilih akun —" :no-truncate="true" />
                                    <div class="mt-0.5 break-words text-[11px] text-neutral-400" :title="accountName(l.account_id)">{{ accountName(l.account_id) || '—' }}</div>
                                </td>
                                <td class="px-2 py-2"><SearchSelect v-model="l.cost_center_id" :options="costCenters" placeholder="— cost center —" :no-truncate="true" /></td>
                                <td class="px-2 py-2"><textarea v-model="l.description" v-autosize rows="1" :class="[cellArea,'min-h-8']" placeholder="Keterangan baris…"></textarea></td>
                                <td class="px-2 py-2">
                                    <select v-model="l.currency" :class="dimCls"><option value="IDR">IDR</option><option value="USD">USD</option><option value="SGD">SGD</option><option value="EUR">EUR</option></select>
                                    <input v-model.number="l.kurs" type="number" step="0.0001" min="0" :class="[dimCls,'mt-1 text-right font-mono']" />
                                </td>
                                <td class="px-2 py-2"><input v-model.number="l.nilai" @input="applyAmount(l)" type="number" step="1" min="0" :class="[dimCls,'text-right font-mono']" /></td>
                                <td class="px-2 py-2 text-right">
                                    <input type="radio" :name="'side-row-' + i" :checked="l.side==='debit'" @change="setSide(l,'debit')" class="h-4 w-4 accent-primary-600" />
                                    <div class="mt-1 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ l.side==='debit' && Number(l.debit) ? fmtNum(l.debit) : '' }}</div>
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <input type="radio" :name="'side-row-' + i" :checked="l.side==='credit'" @change="setSide(l,'credit')" class="h-4 w-4 accent-primary-600" />
                                    <div class="mt-1 font-mono text-[11px] text-neutral-600 dark:text-neutral-300">{{ l.side==='credit' && Number(l.credit) ? fmtNum(l.credit) : '' }}</div>
                                </td>
                                <td class="px-1 py-2 text-center"><button type="button" @click="removeJournalLine(i)" class="text-danger-500"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                            <tr v-if="!form.journal_lines.length"><td colspan="9" class="px-2 py-6 text-center text-sm text-neutral-400">Belum ada baris. Klik "Hitung ulang" untuk membentuk jurnal otomatis dari transaksi, atau "Tambah 1 Baris".</td></tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                                <td colspan="5" class="px-2 py-2 text-right text-neutral-500">Grand Total</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalNilai) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalDebit) }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtNum(jurnalKredit) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex gap-4 text-xs font-medium">
                        <button type="button" @click="addJournalLine" class="inline-flex items-center gap-1 text-primary-600 hover:underline"><Plus class="h-3.5 w-3.5" /> Tambah 1 Baris</button>
                        <button type="button" @click="addJournalLines(10)" class="inline-flex items-center gap-1 text-primary-600 hover:underline"><Plus class="h-3.5 w-3.5" /> Tambah 10 Baris</button>
                    </div>
                    <span v-if="jurnalBalanced" class="inline-flex items-center gap-1 rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-700 dark:bg-success-900/30 dark:text-success-300"><Check class="h-3.5 w-3.5" /> Balance</span>
                    <span v-else class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">Selisih {{ fmtRp(Math.abs(jurnalDebit - jurnalKredit)) }} — Debit ≠ Kredit</span>
                </div>
            </div>
        </div>

        <!-- Step 5: Selesai -->
        <div v-show="step === 5" class="card p-5 text-sm">
            <h2 class="mb-3 font-semibold">Ringkasan</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <div><span class="text-neutral-500">Supplier:</span> {{ supplier?.name }}</div>
                <div><span class="text-neutral-500">Kategori:</span> {{ category?.name }}</div>
                <div><span class="text-neutral-500">Tipe:</span> {{ trxTypes[form.trx_type] }}</div>
                <div><span class="text-neutral-500">Jatuh tempo:</span> {{ dueDate }}</div>
                <div class="sm:col-span-2"><span class="text-neutral-500">Keterangan:</span> {{ form.description }}</div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800 sm:grid-cols-4">
                <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(form.dpp) }}</div></div>
                <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                <div><div class="text-xs text-neutral-400">{{ pphLabel }}</div><div class="font-mono" :class="pph > 0 ? 'text-danger-600' : 'text-neutral-500'">{{ fmtRp(pph) }}</div></div>
                <div><div class="text-xs text-neutral-400">Net Dibayar</div><div class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(net) }}</div></div>
            </div>
            <div class="mt-3">
                <div class="mb-1 flex items-center justify-between"><span class="text-xs font-medium text-neutral-500">Struktur Jurnal</span><span v-if="jurnalBalanced" class="text-[11px] font-medium text-success-600">● Balance</span><span v-else class="text-[11px] font-medium text-amber-600">● Selisih {{ fmtRp(Math.abs(jurnalDebit - jurnalKredit)) }}</span></div>
                <table class="w-full text-xs">
                    <thead class="text-left uppercase text-neutral-400"><tr><th class="py-1">Account</th><th class="py-1">Dimensi</th><th class="py-1">Keterangan</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Kredit</th></tr></thead>
                    <tbody>
                        <tr v-for="(l,i) in activeJournalLines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1 font-mono">{{ accountLabel(l.account_id) }}</td>
                            <td class="py-1 font-mono text-[11px] text-neutral-500">{{ dimSummary(l) || '—' }}</td>
                            <td class="py-1">{{ l.description }}</td>
                            <td class="py-1 text-right font-mono">{{ (Number(l.debit) || 0) > 0 ? fmtRp(l.debit) : '' }}</td>
                            <td class="py-1 text-right font-mono">{{ (Number(l.credit) || 0) > 0 ? fmtRp(l.credit) : '' }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700"><td colspan="3" class="py-1 text-right text-neutral-500">Grand Total</td><td class="py-1 text-right font-mono">{{ fmtRp(jurnalDebit) }}</td><td class="py-1 text-right font-mono">{{ fmtRp(jurnalKredit) }}</td></tr></tfoot>
                </table>
            </div>
        </div>

        <!-- Nav -->
        <div class="flex items-center justify-between">
            <button v-if="step > 1" type="button" @click="prev" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700"><ArrowLeft class="h-4 w-4" /> Sebelumnya</button>
            <span v-else></span>
            <Button v-if="step < 5" :disabled="!canNext()" @click="next"><template #icon-left><ArrowRight class="h-4 w-4" /></template>Berikutnya</Button>
            <Button v-else :disabled="form.processing" @click="submit"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
        </div>
    </div>
</template>
