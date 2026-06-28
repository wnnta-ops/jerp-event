<script setup lang="ts">
import { watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    warehouses: Array<any>;
    suppliers: Array<any>;
    items: Array<any>;
    purchaseOrders: Array<any>;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    warehouse_id: '', supplier_id: '', purchase_order_id: '', trx_date: today, reference: '', notes: '',
    lines: [{ item_id: '', qty: 1, unit_price: 0 }],
});

// Saat PO dipilih → prefill baris dari outstanding PO.
watch(() => form.purchase_order_id, (poId) => {
    const po = props.purchaseOrders.find((p: any) => String(p.id) === String(poId));
    if (po) {
        form.supplier_id = po.supplier_id ?? '';
        form.lines = po.lines.filter((l: any) => l.outstanding > 0).map((l: any) => ({ item_id: l.item_id, qty: l.outstanding, unit_price: l.unit_price }));
        if (!form.lines.length) form.lines = [{ item_id: '', qty: 1, unit_price: 0 }];
    }
});
function addLine() { form.lines.push({ item_id: '', qty: 1, unit_price: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function submit() { form.post(route('bs.receipt.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Penerimaan Barang</h1>
            <Link :href="route('bs.receipt.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari PO (opsional)</label><select v-model="form.purchase_order_id" :class="inputCls"><option value="">— manual / non-PO —</option><option v-for="po in purchaseOrders" :key="po.id" :value="po.id">{{ po.po_number }} ({{ po.supplier }})</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Gudang *</label><select v-model="form.warehouse_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} - {{ w.name }}</option></select><p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-danger-500">{{ form.errors.warehouse_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier</label><select v-model="form.supplier_id" :class="inputCls"><option value="">—</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Terima *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Referensi / No. Surat Jalan</label><input v-model="form.reference" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Barang Diterima</h2>
                    <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">Item</th><th class="w-28 py-2 text-right">Qty</th><th class="w-36 py-2 text-right">Harga Satuan</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5 pr-2"><select v-model="l.item_id" required :class="inputCls"><option value="">— pilih item —</option><option v-for="it in items" :key="it.id" :value="it.id">{{ it.code }} - {{ it.name }}</option></select></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0.01" required :class="[inputCls,'text-right']" /></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.unit_price" type="number" step="1" min="0" :class="[inputCls,'text-right']" /></td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Tambah Stok</Button>
                <Link :href="route('bs.receipt.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
