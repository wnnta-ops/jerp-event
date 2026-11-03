<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, ReceiptText, Lock, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    suppliers: Array<any>;
    openInvoices: Array<any>;
    cashAccounts: Array<any>;   // tiap item punya { id, code, name, is_kas }
    alat: Record<string, string>;
    kasir: string;
    /** Memo Urgent yang bisa dibayar tanpa invoice (sudah disetujui Direktur, belum dibayar, belum ada invoice). */
    memoBayar: Array<{ id: number; number: string; subject: string; date: string | null; department: string | null; supplier: string | null; amount: number; status: string; url: string | null }>;
    akunUangMuka: Array<{ id: number; code: string; name: string }>;
    mode: 'invoice' | 'memo';
}>();

/* Dua mode: bayar invoice hutang (lewat approval) atau bayar Memo Urgent tanpa invoice (langsung jadi). */
const mode = ref<'invoice' | 'memo'>(props.mode);

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    // Kas/Bank SENGAJA dibiarkan kosong — tidak ada bawaan.
    //
    // Sebelumnya terisi otomatis dari config gl_accounts.cash, yang menunjuk satu rekening bank
    // tertentu (10101020204 — BCA REK. 6155428914). Akibatnya uang keluar dari rekening yang
    // tidak pernah dipilih siapa pun: kuitansi yang disimpan tergesa mengkredit rekening bawaan
    // itu, jurnalnya tetap balance, dan tidak ada satu pun galat yang memberi tahu — yang
    // ketahuan belakangan hanya saldo bank yang tidak cocok saat rekonsiliasi.
    //
    // Keadaan "kosong" sendiri bukan hal baru: memilih alat transaksi Tunai sudah mengosongkan
    // kolom ini lewat watch di bawah, karena rekening bawaannya bukan akun kas. Yang berubah di
    // sini hanya keadaan AWAL, dan tombol Simpan ikut dikunci sampai rekeningnya dipilih.
    kuitansi_date: today, supplier_id: '', cash_account_id: '',
    alat_transaksi: 'transfer', kasir: props.kasir, manual_no: '', bg_number: '', description: '', lines: [],
    // Bayar Memo Urgent — harus ada sejak awal: useForm hanya mengirim kunci yang ada di nilai awalnya.
    memo_internal_id: '', advance_account_id: '', memo_amount: null,
});

// #50: opsi kas/bank dikunci sesuai alat transaksi.
//   Tunai           → hanya Kas Operasional / Kas Kecil.
//   Transfer / Giro → semua rekening bank (selain kas tunai). Giro + kolom No. BG/Cek.
const filteredCash = computed(() =>
    form.alat_transaksi === 'tunai'
        ? props.cashAccounts.filter((a: any) => a.is_kas)
        : props.cashAccounts.filter((a: any) => !a.is_kas));

watch(() => form.alat_transaksi, () => {
    if (!filteredCash.value.some((a: any) => a.id === form.cash_account_id)) form.cash_account_id = '';
    if (form.alat_transaksi !== 'giro') form.bg_number = '';
});

// Invoice open milik supplier terpilih, dengan kolom 'pay' (checkbox) + amount.
const supplierInvoices = ref<any[]>([]);
watch(() => form.supplier_id, (sid) => {
    supplierInvoices.value = props.openInvoices.filter((i: any) => i.supplier_id === sid)
        .map((i: any) => ({ ...i, pay: false, amount: i.outstanding }));
});
// Uang muka Memo Urgent yang sudah dibayar WAJIB dipotong (server menegakkan juga); yang keluar dari kas = pelunasan − uang muka.
const umInv = (i: any) => Number(i.um?.nilai) || 0;
const kurangDariUm = (i: any) => i.pay && umInv(i) > 0 && (Number(i.amount) || 0) < umInv(i);
const totalPelunasan = computed(() => supplierInvoices.value.filter(i => i.pay).reduce((s, i) => s + (Number(i.amount) || 0), 0));
const totalUm = computed(() => supplierInvoices.value.filter(i => i.pay).reduce((s, i) => s + umInv(i), 0));
const total = computed(() => totalPelunasan.value - totalUm.value);
const adaKurangUm = computed(() => supplierInvoices.value.some(kurangDariUm));

// #158: auto-isi Keterangan dari deskripsi invoice yang dipilih (pay=true).
//   Pakai deskripsi invoice terpilih pertama; bila >1 digabung dengan "; ".
//   Hanya menimpa bila kolom masih kosong atau masih sama dengan nilai auto sebelumnya
//   (manual edit pengguna dipertahankan via flag).
const lastAutoKeterangan = ref('');
const selectedKeterangan = computed(() =>
    supplierInvoices.value.filter((i: any) => i.pay)
        .map((i: any) => (i.description ?? '').trim())
        .filter((d: string) => d)
        .join('; '));
watch(selectedKeterangan, (val) => {
    if (form.description === '' || form.description === lastAutoKeterangan.value) {
        form.description = val;
        lastAutoKeterangan.value = val;
    }
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
function submit() {
    if (mode.value === 'memo') {
        form.transform((d: any) => ({
            memo_internal_id: d.memo_internal_id, kuitansi_date: d.kuitansi_date, cash_account_id: d.cash_account_id,
            advance_account_id: d.advance_account_id, amount: d.memo_amount, alat_transaksi: d.alat_transaksi,
            manual_no: d.manual_no, bg_number: d.bg_number, description: d.description,
        })).post(route('bs.ap.kuitansi.store-memo'));
        return;
    }
    form.lines = supplierInvoices.value.filter(i => i.pay && Number(i.amount) > 0).map(i => ({ ap_invoice_id: i.id, amount: i.amount }));
    form.transform((d: any) => d).post(route('bs.ap.kuitansi.store'));
}

/* BAYAR MEMO URGENT — cari no. memo; supplier & nominal ikut memo, nominal boleh ≤ nominal memo (sekali bayar). */
const opsiMemo = computed(() => props.memoBayar.map((m) => ({ id: m.id, label: `${m.number} — ${m.subject} · ${m.supplier ?? ''} · ${fmtRp(m.amount)}` })));
const memoDipilih = computed(() => props.memoBayar.find((m) => m.id === form.memo_internal_id) ?? null);
watch(memoDipilih, (m) => {
    form.memo_amount = m ? m.amount : null;
    if (m && (form.description === '' || form.description === lastAutoKeterangan.value)) {
        form.description = `Memo Urgent ${m.number} — ${m.subject}`;
        lastAutoKeterangan.value = form.description;
    }
});
const memoLebih = computed(() => !!memoDipilih.value && (Number(form.memo_amount) || 0) > memoDipilih.value.amount + 0.001);
const bisaBayarMemo = computed(() => !!memoDipilih.value && !!form.advance_account_id && !!form.cash_account_id && (Number(form.memo_amount) || 0) > 0 && !memoLebih.value && !form.processing);
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// Pop-up rincian invoice (klik No. Invoice): Struktur Jurnal + ringkasan + riwayat pelunasan.
// Diambil saat diklik saja, supaya form tetap ringan. Hanya baca — isian form tidak berubah.
const rincian = ref<any>(null);
const rincianMuat = ref(false);
const rincianGalat = ref('');
async function bukaRincian(id: number) {
    rincian.value = { memuat: true };
    rincianMuat.value = true; rincianGalat.value = '';
    try {
        const res = await (window as any).axios.get(route('bs.ap.kuitansi.rincian-invoice', id));
        rincian.value = res.data;
    } catch (e: any) {
        rincianGalat.value = e?.response?.status === 403 ? 'Anda tidak punya akses melihat rincian invoice.' : 'Rincian gagal dimuat — coba lagi.';
    } finally { rincianMuat.value = false; }
}
const STATUS_KUI: Record<string, [string, string]> = {
    pending: ['Menunggu approval', 'text-amber-600'], active: ['Approved', 'text-success-600'],
    rejected: ['Ditolak', 'text-neutral-400 line-through'], void: ['Void', 'text-neutral-400 line-through'],
};
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-danger-600" /> Kuitansi Hutang</h1>
            <Link :href="route('bs.ap.kuitansi.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <div class="inline-flex rounded-lg border border-neutral-200 bg-white p-1 text-sm dark:border-neutral-800 dark:bg-neutral-900">
            <button type="button" class="rounded-md px-3 py-1.5" :class="mode === 'invoice' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'" @click="mode = 'invoice'">Bayar Invoice Hutang</button>
            <button type="button" class="rounded-md px-3 py-1.5" :class="mode === 'memo' ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'" @click="mode = 'memo'">Bayar Memo Urgent <span class="text-xs opacity-80">({{ memoBayar.length }})</span></button>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <!-- Bayar Memo Urgent tanpa invoice -->
            <div v-if="mode === 'memo'" class="card space-y-3 p-5">
                <div class="flex items-center gap-2 font-semibold"><FileText class="h-4 w-4 text-primary-600" /> Memo Urgent</div>
                <SearchSelect v-model="form.memo_internal_id" :options="opsiMemo" :placeholder="opsiMemo.length ? '— cari no. memo / perihal / supplier —' : '— belum ada memo yang bisa dibayar —'" :no-truncate="true" />
                <p v-if="form.errors.memo_internal_id" class="text-xs text-danger-500">{{ form.errors.memo_internal_id }}</p>
                <div v-if="memoDipilih" class="grid gap-2 rounded-lg bg-neutral-50 p-3 text-xs dark:bg-neutral-800/60 sm:grid-cols-4">
                    <div>Supplier<div class="font-medium">{{ memoDipilih.supplier }}</div></div>
                    <div>Tanggal / Dept<div class="font-medium">{{ memoDipilih.date }} · {{ memoDipilih.department || '—' }}</div></div>
                    <div>Nominal Memo<div class="font-mono font-semibold">{{ fmtRp(memoDipilih.amount) }}</div></div>
                    <div>Status<div class="text-amber-600">{{ memoDipilih.status }} <a v-if="memoDipilih.url" :href="memoDipilih.url" target="_blank" class="ml-1 text-primary-600 hover:underline">lihat</a></div></div>
                </div>
                <p class="text-[11px] text-neutral-500">Hanya memo yang sudah disetujui Direktur, belum dibayar, dan belum punya invoice. Dibayar sekali, paling banyak sebesar nominal memo; kuitansi langsung jadi (tanpa approval) — TTD MW / BA muncul otomatis di cetakan setelah MW / BA menyetujui memo. <b class="text-red-600">Invoice hutang wajib menyusul.</b></p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Akun Uang Muka (Debit) *</label>
                        <SearchSelect v-model="form.advance_account_id" :options="akunUangMuka" placeholder="— pilih akun uang muka —" :no-truncate="true" />
                        <p v-if="form.errors.advance_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.advance_account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Nominal Dibayar *</label>
                        <MoneyInput v-model="form.memo_amount" :class="memoLebih ? '!border-danger-400' : ''" />
                        <p v-if="memoLebih" class="mt-1 text-xs text-danger-500">Melebihi nominal memo.</p>
                        <p v-else-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p>
                    </div>
                </div>
            </div>

            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div v-if="mode === 'invoice'"><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label><SearchSelect v-model="form.supplier_id" :options="suppliers" placeholder="— pilih supplier —" /><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Kuitansi *</label><input v-model="form.kuitansi_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Alat Transaksi *</label><select v-model="form.alat_transaksi" :class="inputCls"><option v-for="(label,val) in alat" :key="val" :value="val">{{ label }}</option></select></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Kas/Bank *</label>
                    <SearchSelect v-model="form.cash_account_id" :options="filteredCash" placeholder="— pilih kas / bank —" :no-truncate="true" />
                    <p class="mt-1 text-[11px] text-neutral-400">{{ form.alat_transaksi === 'tunai' ? 'Tunai: hanya Kas Operasional / Kas Kecil.' : 'Transfer / Cek-Giro: rekening bank.' }}</p>
                    <p v-if="form.errors.cash_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cash_account_id }}</p>
                </div>
                <div v-if="form.alat_transaksi === 'giro'">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">No. BG / Cek *</label>
                    <input v-model="form.bg_number" placeholder="mis. BG-00123" :class="inputCls" />
                    <p v-if="form.errors.bg_number" class="mt-1 text-xs text-danger-500">{{ form.errors.bg_number }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Kasir <Lock class="ml-0.5 inline h-3 w-3 text-neutral-400" /></label>
                    <input :value="form.kasir" readonly title="Otomatis dari user yang login" :class="[inputCls,'cursor-not-allowed bg-neutral-100 text-neutral-600 dark:bg-neutral-800']" />
                    <p class="mt-1 text-[11px] text-neutral-400">Otomatis sesuai user login.</p>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="inputCls" /></div>
                <div class="md:col-span-3">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.description" maxlength="2000" :class="inputCls" />
                    <p v-if="form.errors.description" class="mt-1 text-xs text-danger-500">{{ form.errors.description }}</p>
                </div>
            </div>

            <div v-if="mode === 'invoice'" class="card p-5">
                <h2 class="mb-2 font-semibold">Invoice Hutang Open</h2>
                <table v-if="form.supplier_id" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="w-8 py-1"></th><th class="py-1">No. Invoice</th><th class="py-1">Keterangan</th><th class="py-1">Jatuh Tempo</th><th class="py-1 text-right">Sisa</th><th class="w-40 py-1 text-right">Dibayar</th></tr></thead>
                    <tbody>
                        <tr v-for="inv in supplierInvoices" :key="inv.id" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5"><input v-model="inv.pay" type="checkbox" class="rounded" /></td>
                            <td class="py-1.5 font-mono text-xs"><button type="button" @click="bukaRincian(inv.id)" class="text-left text-primary-600 hover:underline" title="Lihat struktur jurnal & riwayat pelunasan">{{ inv.invoice_number }} 🔍</button></td>
                            <td class="max-w-xs py-1.5 text-neutral-500"><div class="truncate" :title="inv.description">{{ inv.description }}</div>
                                <div v-if="inv.um" class="text-[11px] font-medium text-red-600">Potong uang muka Memo {{ inv.um.memo_number }} ({{ inv.um.kuitansi_number }}): −{{ fmtRp(inv.um.nilai) }} · wajib</div></td>
                            <td class="py-1.5">{{ fmtDate(inv.due_date) }}</td>
                            <td class="py-1.5 text-right font-mono">{{ fmtRp(inv.outstanding) }}</td>
                            <td class="py-1.5 pr-1"><input v-model.number="inv.amount" :disabled="!inv.pay" type="number" step="1" :min="umInv(inv)" :max="inv.outstanding" :class="[inputCls,'text-right', kurangDariUm(inv) ? '!border-danger-400' : '']" /></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.supplier_id && !supplierInvoices.length" class="py-4 text-center text-sm text-neutral-400">Tidak ada invoice open untuk supplier ini.</p>
                <p v-if="!form.supplier_id" class="py-4 text-center text-sm text-neutral-400">Pilih supplier untuk menampilkan invoice open.</p>
                <div v-if="totalUm > 0" class="mt-3 space-y-0.5 px-4 text-sm">
                    <div class="flex justify-between"><span class="text-neutral-500">Total pelunasan hutang</span><span class="font-mono">{{ fmtRp(totalPelunasan) }}</span></div>
                    <div class="flex justify-between text-red-600"><span>Potong uang muka Memo Urgent</span><span class="font-mono">−{{ fmtRp(totalUm) }}</span></div>
                </div>
                <p v-if="adaKurangUm" class="mt-2 text-xs text-danger-500">Nilai pelunasan minimal sebesar uang muka memo yang wajib dipotong.</p>
                <div class="mt-3 flex items-center justify-between rounded-lg bg-primary-50 px-4 py-2 dark:bg-primary-900/20"><span class="font-semibold">Total Dibayar (Kas/Bank)</span><span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(total) }}</span></div>
            </div>

            <div class="flex gap-2">
                <!-- Kas/Bank ikut mengunci tombol. Servernya memang sudah menolak (required),
                     tetapi penolakan itu baru terjadi SESUDAH tombol ditekan dan seluruh isian
                     dikirim — dan alasan matinya tombol disebutkan di sebelahnya, bukan
                     dibiarkan ditebak. -->
                <Button v-if="mode === 'memo'" type="submit" :disabled="!bisaBayarMemo"><template #icon-left><Save class="h-4 w-4" /></template>Bayar Memo &amp; Buat Jurnal</Button>
                <Button v-else type="submit" :disabled="form.processing || (total <= 0 && totalUm <= 0) || adaKurangUm || !form.cash_account_id"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.ap.kuitansi.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
                <!-- Galat validasi server yang tidak punya tempat di kolomnya — jangan sampai tombol terlihat "tidak terjadi apa-apa". -->
                <span v-if="Object.keys(form.errors).length" class="self-center text-xs text-danger-500">{{ Object.values(form.errors)[0] }}</span>
                <span v-else-if="!form.cash_account_id" class="self-center text-xs text-danger-500">Pilih Kas/Bank dulu.</span>
                <span v-else-if="mode === 'memo' && !memoDipilih" class="self-center text-xs text-neutral-400">Pilih Memo Urgent dulu.</span>
                <span v-else-if="mode === 'memo' && !form.advance_account_id" class="self-center text-xs text-neutral-400">Pilih akun uang muka.</span>
                <span v-else-if="mode === 'invoice' && total <= 0 && totalUm <= 0" class="self-center text-xs text-neutral-400">Centang minimal satu invoice.</span>
            </div>
        </form>

        <!-- Pop-up rincian invoice -->
        <div v-if="rincian" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-16" @click.self="rincian = null">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                <div v-if="rincianMuat" class="p-8 text-center text-sm text-neutral-500">Memuat rincian…</div>
                <div v-else-if="rincianGalat" class="p-6 text-center text-sm text-danger-600">{{ rincianGalat }}
                    <div class="mt-3"><button type="button" class="rounded-lg border px-3 py-1.5 text-sm" @click="rincian = null">Tutup</button></div></div>
                <template v-else>
                    <div class="flex items-start justify-between gap-3 border-b border-neutral-200 p-4 dark:border-neutral-800">
                        <div class="min-w-0">
                            <div class="font-mono text-sm font-bold">{{ rincian.invoice_number }} <span class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 font-sans text-xs font-medium dark:bg-neutral-800">{{ rincian.status === 'paid' ? 'Lunas' : 'Open' }}</span></div>
                            <div class="mt-0.5 text-xs text-neutral-500">Tgl {{ fmtDate(rincian.invoice_date) }} · Jatuh tempo {{ fmtDate(rincian.due_date) }}<span v-if="rincian.kategori"> · {{ rincian.kategori }}</span></div>
                            <div class="mt-1 text-sm">{{ rincian.description }}</div>
                        </div>
                        <button type="button" class="text-xl leading-none text-neutral-400 hover:text-neutral-700" @click="rincian = null">✕</button>
                    </div>
                    <div class="p-4">
                        <div class="mb-1 flex justify-between text-xs font-semibold uppercase text-neutral-500"><span>Struktur Jurnal</span><span v-if="rincian.status_jurnal">Status jurnal: {{ rincian.status_jurnal }}</span></div>
                        <table class="w-full text-xs">
                            <thead class="text-left text-neutral-500"><tr><th class="py-1">Akun</th><th class="py-1">Cost Center</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Kredit</th></tr></thead>
                            <tbody>
                                <tr v-for="(l, i) in rincian.jurnal" :key="i" class="border-t border-neutral-100 dark:border-neutral-800" :class="l.hutang ? 'bg-primary-50/60 dark:bg-primary-900/10' : ''">
                                    <td class="py-1">{{ l.akun }} <span v-if="l.hutang" class="ml-1 text-[10px] font-semibold text-primary-600">◄ hutang</span></td>
                                    <td class="py-1 text-neutral-500">{{ l.cc ?? '—' }}</td>
                                    <td class="py-1 text-right font-mono">{{ l.debit > 0 ? fmtRp(l.debit) : '' }}</td>
                                    <td class="py-1 text-right font-mono">{{ l.credit > 0 ? fmtRp(l.credit) : '' }}</td>
                                </tr>
                                <tr v-if="!rincian.jurnal.length"><td colspan="4" class="py-2 text-center text-neutral-400">Invoice tanpa jurnal (mis. saldo awal).</td></tr>
                            </tbody>
                        </table>
                        <div class="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-neutral-50 p-3 text-xs dark:bg-neutral-800/60">
                            <div>Total invoice<div class="font-mono font-semibold">{{ fmtRp(rincian.total) }}</div></div>
                            <div>PPh dipotong<div class="font-mono font-semibold">{{ fmtRp(rincian.pph) }}</div></div>
                            <div>Nett dibayar<div class="font-mono font-semibold">{{ fmtRp(rincian.nett) }}</div></div>
                            <div>Sudah dibayar<div class="font-mono font-semibold">{{ fmtRp(rincian.dibayar) }}</div></div>
                            <div class="col-span-2">SISA<div class="font-mono text-base font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(rincian.sisa) }}</div></div>
                        </div>
                        <div class="mt-4 mb-1 text-xs font-semibold uppercase text-neutral-500">Riwayat Pelunasan</div>
                        <table v-if="rincian.riwayat.length" class="w-full text-xs">
                            <tbody>
                                <tr v-for="r in rincian.riwayat" :key="r.nomor" class="border-t border-neutral-100 dark:border-neutral-800">
                                    <td class="py-1 font-mono"><a :href="r.url" target="_blank" class="text-primary-600 hover:underline">{{ r.nomor }}</a></td>
                                    <td class="py-1">{{ fmtDate(r.tanggal) }}</td>
                                    <td class="py-1 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                                    <td class="py-1 pl-3" :class="(STATUS_KUI[r.status] ?? ['', ''])[1]">{{ (STATUS_KUI[r.status] ?? [r.status])[0] }}<span v-if="r.status === 'pending'"> ⚠</span></td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-xs text-neutral-400">Belum pernah ada pelunasan.</p>
                        <p v-if="rincian.riwayat.some((r: any) => r.status === 'pending')" class="mt-2 text-xs text-amber-600">⚠ Ada pelunasan yang masih menunggu approval — sisa di atas belum dikurangi pengajuan itu.</p>
                    </div>
                    <div class="flex justify-between border-t border-neutral-200 p-4 dark:border-neutral-800">
                        <a :href="rincian.url" target="_blank" class="text-sm text-primary-600 hover:underline">Buka detail lengkap ↗</a>
                        <button type="button" class="rounded-lg border border-neutral-300 px-4 py-1.5 text-sm hover:bg-neutral-50 dark:border-neutral-700" @click="rincian = null">Tutup</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
