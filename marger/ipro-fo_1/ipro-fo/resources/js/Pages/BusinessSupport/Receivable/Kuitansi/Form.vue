<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, ReceiptText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    customers: Array<any>;
    openInvoices: Array<any>;
    cashAccounts: Array<any>;
    defaultCash: number | null;
    alat: Record<string, string>;
    kasir: string;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    kuitansi_date: today, customer_id: '', cash_account_id: props.defaultCash ?? '',
    alat_transaksi: 'transfer', kasir: props.kasir, manual_no: '', description: '', lines: [],
});

// Invoice open milik customer terpilih, dengan kolom 'pay' (checkbox) + amount.
const customerInvoices = ref<any[]>([]);
watch(() => form.customer_id, (cid) => {
    customerInvoices.value = props.openInvoices.filter((i: any) => i.customer_id === cid)
        .map((i: any) => ({ ...i, pay: false, amount: i.outstanding }));
});
const total = computed(() => customerInvoices.value.filter(i => i.pay).reduce((s, i) => s + (Number(i.amount) || 0), 0));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
function submit() {
    form.lines = customerInvoices.value.filter(i => i.pay && Number(i.amount) > 0).map(i => ({ ar_invoice_id: i.id, amount: i.amount }));
    form.post(route('bs.ar.kuitansi.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ReceiptText class="h-6 w-6 text-success-600" /> Kuitansi Piutang</h1>
            <Link :href="route('bs.ar.kuitansi.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Customer *</label><select v-model="form.customer_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="s in customers" :key="s.id" :value="s.id">{{ s.full_name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Kuitansi *</label><input v-model="form.kuitansi_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Alat Transaksi *</label><select v-model="form.alat_transaksi" :class="inputCls"><option v-for="(label,val) in alat" :key="val" :value="val">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Ke Kas/Bank *</label><select v-model="form.cash_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kasir</label><input v-model="form.kasir" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="inputCls" /></div>
                <div class="md:col-span-3"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.description" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <h2 class="mb-2 font-semibold">Invoice Piutang Open</h2>
                <table v-if="form.customer_id" class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="w-8 py-1"></th><th class="py-1">No. Invoice</th><th class="py-1">Keterangan</th><th class="py-1">Jatuh Tempo</th><th class="py-1 text-right">Sisa</th><th class="w-40 py-1 text-right">Diterima</th></tr></thead>
                    <tbody>
                        <tr v-for="inv in customerInvoices" :key="inv.id" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5"><input v-model="inv.pay" type="checkbox" class="rounded" /></td>
                            <td class="py-1.5 font-mono text-xs">{{ inv.invoice_number }}</td>
                            <td class="py-1.5 text-neutral-500">{{ inv.description }}</td>
                            <td class="py-1.5">{{ fmtDate(inv.due_date) }}</td>
                            <td class="py-1.5 text-right font-mono">{{ fmtRp(inv.outstanding) }}</td>
                            <td class="py-1.5 pr-1"><input v-model.number="inv.amount" :disabled="!inv.pay" type="number" step="1" min="0" :max="inv.outstanding" :class="[inputCls,'text-right']" /></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.customer_id && !customerInvoices.length" class="py-4 text-center text-sm text-neutral-400">Tidak ada invoice open untuk customer ini.</p>
                <p v-if="!form.customer_id" class="py-4 text-center text-sm text-neutral-400">Pilih customer untuk menampilkan invoice open.</p>
                <div class="mt-3 flex items-center justify-between rounded-lg bg-success-50 px-4 py-2 dark:bg-success-900/20"><span class="font-semibold">Total Diterima</span><span class="font-mono text-lg font-bold text-success-700 dark:text-success-300">{{ fmtRp(total) }}</span></div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || total <= 0"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.ar.kuitansi.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
