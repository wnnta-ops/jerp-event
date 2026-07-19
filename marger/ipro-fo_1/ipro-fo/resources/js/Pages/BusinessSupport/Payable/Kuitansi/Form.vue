<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, ReceiptText, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    suppliers: Array<any>;
    openInvoices: Array<any>;
    cashAccounts: Array<any>;   // tiap item punya { id, code, name, is_kas }
    defaultCash: number | null;
    alat: Record<string, string>;
    kasir: string;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    kuitansi_date: today, supplier_id: '', cash_account_id: props.defaultCash ?? '',
    alat_transaksi: 'transfer', kasir: props.kasir, manual_no: '', bg_number: '', description: '', lines: [],
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
const total = computed(() => supplierInvoices.value.filter(i => i.pay).reduce((s, i) => s + (Number(i.amount) || 0), 0));

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
    form.lines = supplierInvoices.value.filter(i => i.pay && Number(i.amount) > 0).map(i => ({ ap_invoice_id: i.id, amount: i.amount }));
    form.post(route('bs.ap.kuitansi.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-danger-600" /> Kuitansi Hutang</h1>
            <Link :href="route('bs.ap.kuitansi.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label><SearchSelect v-model="form.supplier_id" :options="suppliers" placeholder="— pilih supplier —" /><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
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
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.description" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <h2 class="mb-2 font-semibold">Invoice Hutang Open</h2>
                <table v-if="form.supplier_id" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="w-8 py-1"></th><th class="py-1">No. Invoice</th><th class="py-1">Keterangan</th><th class="py-1">Jatuh Tempo</th><th class="py-1 text-right">Sisa</th><th class="w-40 py-1 text-right">Dibayar</th></tr></thead>
                    <tbody>
                        <tr v-for="inv in supplierInvoices" :key="inv.id" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5"><input v-model="inv.pay" type="checkbox" class="rounded" /></td>
                            <td class="py-1.5 font-mono text-xs">{{ inv.invoice_number }}</td>
                            <td class="py-1.5 text-neutral-500">{{ inv.description }}</td>
                            <td class="py-1.5">{{ fmtDate(inv.due_date) }}</td>
                            <td class="py-1.5 text-right font-mono">{{ fmtRp(inv.outstanding) }}</td>
                            <td class="py-1.5 pr-1"><input v-model.number="inv.amount" :disabled="!inv.pay" type="number" step="1" min="0" :max="inv.outstanding" :class="[inputCls,'text-right']" /></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.supplier_id && !supplierInvoices.length" class="py-4 text-center text-sm text-neutral-400">Tidak ada invoice open untuk supplier ini.</p>
                <p v-if="!form.supplier_id" class="py-4 text-center text-sm text-neutral-400">Pilih supplier untuk menampilkan invoice open.</p>
                <div class="mt-3 flex items-center justify-between rounded-lg bg-primary-50 px-4 py-2 dark:bg-primary-900/20"><span class="font-semibold">Total Dibayar</span><span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(total) }}</span></div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || total <= 0"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.ap.kuitansi.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
