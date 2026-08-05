<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save, ClipboardCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    suppliers: Array<{ id: number; code: string; name: string }>;
    items: Array<{ id: number; code: string; name: string }>;
    projects: Array<{ id: number; name: string }>;
    ppn_default: number;
    /** FPB yang disetujui, bila PO ini dibuat dari permintaan tersebut. */
    fpb?: { id: number; number: string; notes: string | null; lines: Array<{ item_id: number; qty: number; spec: string | null }> } | null;
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    supplier_id: '', project_id: '',
    // Ditautkan ke FPB asalnya supaya permintaan itu tidak dijadikan PO dua kali.
    material_request_id: props.fpb?.id ?? '',
    po_date: today, due_date: '', po_type: 'barang', term_payment: '',
    ppn_pct: props.ppn_default, pph_pct: 0,
    notes: props.fpb ? `Dari FPB ${props.fpb.number}${props.fpb.notes ? ' — ' + props.fpb.notes : ''}` : '',
    // Baris diisi awal dari FPB (item & qty); harga satuan tetap diisi operator saat negosiasi.
    lines: props.fpb?.lines?.length
        ? props.fpb.lines.map(l => ({ item_id: l.item_id, qty: l.qty, unit_price: 0 }))
        : [{ item_id: '', qty: 1, unit_price: 0 }],
});
function addLine() { form.lines.push({ item_id: '', qty: 1, unit_price: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
const subtotal = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0));
const ppnAmount = computed(() => Math.round(subtotal.value * (Number(form.ppn_pct) || 0) / 100));
const total = computed(() => subtotal.value + ppnAmount.value);
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.po.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Buat Purchase Order</h1>
            <Link :href="route('bs.po.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>

        <!-- Berasal dari FPB yang disetujui: barisnya sudah terisi, tinggal supplier & harga. -->
        <div v-if="fpb" class="flex flex-wrap items-center gap-2 rounded-xl bg-primary-50/70 px-4 py-3 text-sm ring-1 ring-primary-200 dark:bg-primary-950/25 dark:ring-primary-800/60">
            <ClipboardCheck class="h-4 w-4 shrink-0 text-primary-600" />
            <span>Dilanjutkan dari FPB <b class="font-mono">{{ fpb.number }}</b> — {{ fpb.lines.length }} baris permintaan sudah diisikan.</span>
            <span class="text-xs text-neutral-500">Lengkapi supplier &amp; harga satuannya.</span>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label><select v-model="form.supplier_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} - {{ s.name }}</option></select><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl PO *</label><input v-model="form.po_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo</label><input v-model="form.due_date" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe</label><select v-model="form.po_type" :class="inputCls"><option value="barang">Barang</option><option value="jasa">Jasa</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Term Pembayaran</label><input v-model="form.term_payment" placeholder="mis. 30 hari" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project</label><select v-model="form.project_id" :class="inputCls"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN (%)</label><input v-model.number="form.ppn_pct" type="number" step="0.01" min="0" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPh (%)</label><input v-model.number="form.pph_pct" type="number" step="0.01" min="0" :class="inputCls" /></div>
                <div class="md:col-span-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="font-semibold">Item Pembelian</h2>
                    <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">Item</th><th class="w-24 py-2 text-right">Qty</th><th class="w-36 py-2 text-right">Harga Satuan</th><th class="w-36 py-2 text-right">Jumlah</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                            <td class="py-1.5 pr-2"><select v-model="l.item_id" required :class="inputCls"><option value="">— pilih item —</option><option v-for="it in items" :key="it.id" :value="it.id">{{ it.code }} - {{ it.name }}</option></select></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0.01" required :class="[inputCls,'text-right']" /></td>
                            <td class="py-1.5 pr-2"><input v-model.number="l.unit_price" type="number" step="1" min="0" required :class="[inputCls,'text-right']" /></td>
                            <td class="py-1.5 pr-2 text-right font-mono">{{ fmtRp((Number(l.qty)||0)*(Number(l.unit_price)||0)) }}</td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-3 flex justify-end">
                    <div class="w-64 space-y-1 text-sm">
                        <div class="flex justify-between"><span class="text-neutral-500">Subtotal</span><span class="font-mono">{{ fmtRp(subtotal) }}</span></div>
                        <div class="flex justify-between"><span class="text-neutral-500">PPN {{ form.ppn_pct }}%</span><span class="font-mono">{{ fmtRp(ppnAmount) }}</span></div>
                        <div class="flex justify-between border-t border-neutral-200 pt-1 font-bold dark:border-neutral-700"><span>Total</span><span class="font-mono text-primary-600">{{ fmtRp(total) }}</span></div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan PO</Button>
                <Link :href="route('bs.po.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
