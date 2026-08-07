<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Trash2, Save, TriangleAlert, ShoppingCart } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

type PoLine = { item_id: number; item: string; qty: number; qty_received: number; outstanding: number; unit_price: number };
type Po = { id: number; po_number: string; supplier_id: number | null; supplier: string | null; lines: PoLine[] };

// Daftar item & supplier tidak lagi dikirim: keduanya sudah ditentukan oleh PO.
const props = defineProps<{
    warehouses: Array<any>;
    purchaseOrders: Po[];
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    warehouse_id: '', purchase_order_id: '', trx_date: today, reference: '', notes: '',
    lines: [],
});

const po = computed<Po | null>(() => props.purchaseOrders.find(p => String(p.id) === String(form.purchase_order_id)) ?? null);
// Baris penerimaan SELALU berasal dari PO — item & harganya tidak diketik ulang.
watch(() => form.purchase_order_id, () => {
    form.lines = (po.value?.lines ?? []).map(l => ({ item_id: l.item_id, qty: l.outstanding }));
});

const poLineOf = (itemId: number) => po.value?.lines.find(l => l.item_id === itemId) ?? null;
const hargaOf = (itemId: number) => poLineOf(itemId)?.unit_price ?? 0;
const sisaOf = (itemId: number) => poLineOf(itemId)?.outstanding ?? 0;
const subtotalOf = (l: any) => (Number(l.qty) || 0) * hargaOf(l.item_id);
const total = computed(() => form.lines.reduce((a: number, l: any) => a + subtotalOf(l), 0));
const lebih = (l: any) => (Number(l.qty) || 0) > sisaOf(l.item_id) + 1e-7;
const adaLebih = computed(() => form.lines.some((l: any) => lebih(l)));
const adaIsi = computed(() => form.lines.some((l: any) => (Number(l.qty) || 0) > 0));

function removeLine(i: number) { form.lines.splice(i, 1); }
function submit() {
    if (!form.purchase_order_id || !adaIsi.value || adaLebih.value) return;
    // Baris dengan qty 0 tidak dikirim — dianggap belum diterima pada penerimaan ini.
    form.transform((d: any) => ({ ...d, lines: d.lines.filter((l: any) => (Number(l.qty) || 0) > 0) }))
        .post(route('bs.receipt.store'));
}
const rp = (v: number) => (Number(v) || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Penerimaan Barang</h1>
            <Link :href="route('bs.receipt.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>

        <!-- Tanpa PO yang masih punya sisa, penerimaan tidak bisa dibuat sama sekali. -->
        <div v-if="!purchaseOrders.length" class="card flex items-start gap-3 p-5">
            <TriangleAlert class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="space-y-2">
                <p class="text-sm font-semibold">Belum ada Purchase Order yang bisa diterima</p>
                <p class="text-sm text-neutral-500">Penerimaan barang harus berasal dari PO supaya nominalnya mengikuti nominal PO. Daftar ini hanya memuat PO berstatus <b>disetujui</b> yang barangnya <b>belum diterima seluruhnya</b>.</p>
                <Link :href="route('bs.po.index')" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700"><ShoppingCart class="h-4 w-4" /> Buka Purchase Order</Link>
            </div>
        </div>

        <form v-else @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Dari PO *</label>
                    <select v-model="form.purchase_order_id" required :class="inputCls">
                        <option value="">— pilih PO —</option>
                        <option v-for="p in purchaseOrders" :key="p.id" :value="p.id">{{ p.po_number }} ({{ p.supplier }})</option>
                    </select>
                    <p v-if="form.errors.purchase_order_id" class="mt-1 text-xs text-danger-500">{{ form.errors.purchase_order_id }}</p>
                    <p v-else class="mt-1 text-[11px] text-neutral-500">Item &amp; harga satuan mengikuti PO.</p>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Gudang *</label><select v-model="form.warehouse_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} - {{ w.name }}</option></select><p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-danger-500">{{ form.errors.warehouse_id }}</p></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier</label>
                    <div class="flex h-9 items-center rounded-lg border border-neutral-200 bg-neutral-50 px-3 text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-neutral-300">{{ po?.supplier ?? '—' }}</div>
                    <p class="mt-1 text-[11px] text-neutral-500">Mengikuti PO.</p>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Terima *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Referensi / No. Surat Jalan</label><input v-model="form.reference" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Barang Diterima</h2>
                    <span v-if="po" class="text-xs text-neutral-500">dari {{ po.po_number }}</span>
                </div>

                <p v-if="!form.purchase_order_id" class="py-6 text-center text-sm text-neutral-500">Pilih PO terlebih dahulu — barisnya akan terisi otomatis dari PO.</p>

                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr>
                        <th class="py-2">Item</th>
                        <th class="w-24 py-2 text-right">Sisa PO</th>
                        <th class="w-28 py-2 text-right">Qty Terima</th>
                        <th class="w-32 py-2 text-right">Harga Satuan</th>
                        <th class="w-36 py-2 text-right">Subtotal</th>
                        <th class="w-10"></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="l.item_id" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5 pr-2">{{ poLineOf(l.item_id)?.item }}</td>
                            <td class="py-1.5 pr-2 text-right font-mono tabular-nums text-neutral-500">{{ rp(sisaOf(l.item_id)) }}</td>
                            <td class="py-1.5 pr-2">
                                <input v-model.number="l.qty" type="number" step="0.01" min="0" :max="sisaOf(l.item_id)" required
                                    :class="[inputCls, 'text-right', lebih(l) ? 'border-danger-500' : '']" />
                            </td>
                            <!-- Harga tidak bisa diketik: selalu harga PO. -->
                            <td class="py-1.5 pr-2 text-right font-mono tabular-nums text-neutral-600 dark:text-neutral-300">{{ rp(hargaOf(l.item_id)) }}</td>
                            <td class="py-1.5 pr-2 text-right font-mono tabular-nums">{{ rp(subtotalOf(l)) }}</td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" class="text-danger-500 hover:text-danger-600" title="Belum diterima pada penerimaan ini"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                        <tr v-if="!form.lines.length"><td colspan="6" class="py-6 text-center text-sm text-neutral-500">Semua barang PO ini sudah diterima.</td></tr>
                    </tbody>
                    <tfoot v-if="form.lines.length">
                        <tr class="border-t border-neutral-200 font-semibold dark:border-neutral-700">
                            <td colspan="4" class="py-2 text-right">Total penerimaan</td>
                            <td class="py-2 pr-2 text-right font-mono tabular-nums">{{ rp(total) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <p v-if="adaLebih" class="mt-2 text-xs text-danger-500">Ada qty yang melebihi sisa PO — turunkan dulu sebelum menyimpan.</p>
                <p v-else-if="form.purchase_order_id && form.lines.length && !adaIsi" class="mt-2 text-xs text-danger-500">Isi minimal satu qty penerimaan.</p>
                <p v-if="form.errors.lines" class="mt-2 text-xs text-danger-500">{{ form.errors.lines }}</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || !form.purchase_order_id || !adaIsi || adaLebih"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Tambah Stok</Button>
                <Link :href="route('bs.receipt.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
