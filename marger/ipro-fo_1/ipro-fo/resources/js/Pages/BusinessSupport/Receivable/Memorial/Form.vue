<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    customers: Array<any>;
    accounts: Array<any>;
    openInvoices: Array<any>;
    types: Record<string, string>;
    kasir: string;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    memorial_date: today, customer_id: '', ar_invoice_id: '', trx_type: 'penghapusan',
    counter_account_id: '', amount: 0, description: '', kasir: props.kasir, manual_no: '',
});
const customerInvoices = computed(() => props.openInvoices.filter((i: any) => i.customer_id === form.customer_id));
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.ar.memorial.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-success-600" /> Memorial Piutang</h1>
            <Link :href="route('bs.ar.memorial.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe Transaksi *</label>
                    <div class="flex gap-2">
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-3 py-1.5 text-sm" :class="form.trx_type==='penghapusan' ? 'border-success-500 bg-success-50 text-success-700 dark:bg-success-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.trx_type" type="radio" value="penghapusan" class="sr-only" />Penghapusan Nilai</label>
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-3 py-1.5 text-sm" :class="form.trx_type==='penambahan' ? 'border-warning-500 bg-amber-50 text-amber-700 dark:bg-amber-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.trx_type" type="radio" value="penambahan" class="sr-only" />Penambahan Nilai</label>
                    </div></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Memorial *</label><input v-model="form.memorial_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Customer *</label><select v-model="form.customer_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="s in customers" :key="s.id" :value="s.id">{{ s.full_name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Invoice Terkait (opsional)</label><select v-model="form.ar_invoice_id" :class="inputCls"><option value="">— tanpa invoice —</option><option v-for="i in customerInvoices" :key="i.id" :value="i.id">{{ i.invoice_number }} (sisa {{ fmtRp(i.outstanding) }})</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Lawan *</label><select v-model="form.counter_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai *</label><input v-model.number="form.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kasir</label><input v-model="form.kasir" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="inputCls" /></div>
                <div class="md:col-span-2 rounded-lg bg-neutral-50 px-4 py-2 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">Jurnal: <template v-if="form.trx_type==='penambahan'">Dr Piutang Customer · Cr Akun Lawan</template><template v-else>Dr Akun Lawan · Cr Piutang Customer</template> — {{ fmtRp(form.amount) }}</div>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.ar.memorial.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
