<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, Lock, FileEdit, Banknote, Percent, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    order: any; items: any[]; cashAccounts: any[]; advanceAccounts: any[];
    defaultCash: number | null; defaultAdvance: number | null;
}>();
const page = usePage();
const can = computed(() => (page.props.auth.user?.permissions ?? []) as string[]);
const today = new Date().toISOString().slice(0, 10);
const panel = ref<'' | 'revise' | 'termin' | 'ppn'>('');

const reviseForm = useForm<any>({
    po_date: props.order.po_date?.slice(0, 10) ?? today,
    due_date: props.order.due_date?.slice(0, 10) ?? '',
    term_payment: props.order.term_payment ?? '',
    ppn_pct: Number(props.order.ppn_pct) || 0,
    pph_pct: Number(props.order.pph_pct) || 0,
    notes: props.order.notes ?? '',
    lines: (props.order.lines ?? []).map((l: any) => ({ item_id: l.item_id, qty: Number(l.qty), unit_price: Number(l.unit_price) })),
});
const terminForm = useForm<any>({ termin_date: today, amount: 0, advance_account_id: props.defaultAdvance ?? '', cash_account_id: props.defaultCash ?? '', note: '' });
const ppnForm = useForm<any>({ ppn_pct: Number(props.order.ppn_pct) || 0 });

const reviseSubtotal = computed(() => reviseForm.lines.reduce((s: number, l: any) => s + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0));
const reviseTotal = computed(() => reviseSubtotal.value + Math.round(reviseSubtotal.value * (Number(reviseForm.ppn_pct) || 0)) / 100);
const dpRemaining = computed(() => Number(props.order.total) - Number(props.order.dp_amount || 0));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
function approve() { if (!confirm(`Setujui PO ${props.order.po_number}?`)) return; router.post(route('bs.po.approve', props.order.id), {}, { preserveScroll: true }); }
function close() { if (!confirm(`Tutup PO ${props.order.po_number}?`)) return; router.post(route('bs.po.close', props.order.id), {}, { preserveScroll: true }); }
function addLine() { reviseForm.lines.push({ item_id: '', qty: 1, unit_price: 0 }); }
function removeLine(i: number) { reviseForm.lines.splice(i, 1); }
function submitRevise() { reviseForm.post(route('bs.po.revise', props.order.id), { onSuccess: () => { panel.value = ''; } }); }
function submitTermin() { terminForm.post(route('bs.po.termin', props.order.id), { onSuccess: () => { panel.value = ''; terminForm.reset(); terminForm.termin_date = today; terminForm.advance_account_id = props.defaultAdvance ?? ''; terminForm.cash_account_id = props.defaultCash ?? ''; } }); }
function submitPpn() { ppnForm.post(route('bs.po.adjust-ppn', props.order.id), { onSuccess: () => { panel.value = ''; } }); }
const statusColor: Record<string,any> = { draft:'warning', approved:'success', closed:'neutral' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.po.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Daftar PO</Link>
        <div class="flex items-center justify-between">
            <h1 class="font-mono text-2xl font-bold">{{ order.po_number }}<span v-if="order.revision_count > 0" class="ml-2 align-middle text-xs font-normal text-neutral-400">rev. {{ order.revision_count }}</span></h1>
            <div class="flex items-center gap-3">
                <Badge :variant="statusColor[order.status]" dot>{{ order.status }}</Badge>
                <Button v-if="order.status==='draft' && can.includes('businesssupport.approval.act')" @click="approve"><template #icon-left><Check class="h-4 w-4" /></template>Approve</Button>
                <Button v-if="order.status==='approved' && can.includes('businesssupport.approval.act')" variant="secondary" @click="close"><template #icon-left><Lock class="h-4 w-4" /></template>Close</Button>
            </div>
        </div>
        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Supplier</div><div class="font-medium">{{ order.supplier?.name }}</div></div>
            <div><div class="text-neutral-500">Tanggal PO</div><div class="font-medium">{{ fmtDate(order.po_date) }}</div></div>
            <div><div class="text-neutral-500">Jatuh Tempo</div><div class="font-medium">{{ fmtDate(order.due_date) }}</div></div>
            <div><div class="text-neutral-500">Tipe</div><div class="font-medium capitalize">{{ order.po_type }}</div></div>
            <div><div class="text-neutral-500">Term</div><div class="font-medium">{{ order.term_payment ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ order.notes ?? '—' }}</div></div>
            <div v-if="Number(order.dp_amount) > 0" class="md:col-span-3 flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2 dark:bg-primary-900/20"><span class="text-neutral-600 dark:text-neutral-300">Uang Muka (DP) dibayar<span v-if="order.dp_journal_id" class="ml-1 text-xs text-neutral-400">· jurnal #{{ order.dp_journal_id }}</span></span><span class="font-mono font-semibold text-primary-700 dark:text-primary-300">{{ fmtRp(order.dp_amount) }}</span></div>
        </div>

        <!-- Aksi PO -->
        <div v-if="order.status !== 'closed' && can.includes('businesssupport.transaction.create')" class="card p-5">
            <div v-if="panel === ''" class="flex flex-wrap gap-2">
                <button @click="panel = 'revise'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><FileEdit class="h-4 w-4" /> Revisi PO</button>
                <button @click="panel = 'termin'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><Banknote class="h-4 w-4" /> Posting Termin</button>
                <button @click="panel = 'ppn'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><Percent class="h-4 w-4" /> Adjust PPN</button>
            </div>

            <!-- Revisi -->
            <form v-else-if="panel === 'revise'" @submit.prevent="submitRevise" class="space-y-3">
                <h2 class="font-semibold">Revisi PO</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal PO *</label><input v-model="reviseForm.po_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo</label><input v-model="reviseForm.due_date" type="date" :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Term</label><input v-model="reviseForm.term_payment" :class="inputCls" /></div>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1">Item</th><th class="w-24 py-1 text-right">Qty</th><th class="w-36 py-1 text-right">Harga</th><th class="w-32 py-1 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in reviseForm.lines" :key="i">
                            <td class="py-1 pr-2"><SearchSelect v-model="l.item_id" :options="items" placeholder="— item —" /></td>
                            <td class="py-1 pr-2"><input v-model.number="l.qty" type="number" step="0.01" min="0.01" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="py-1 pr-2"><input v-model.number="l.unit_price" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="py-1 pr-2 text-right font-mono">{{ fmtRp((Number(l.qty)||0)*(Number(l.unit_price)||0)) }}</td>
                            <td class="py-1 text-center"><button type="button" @click="removeLine(i)" :disabled="reviseForm.lines.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN (%)</label><input v-model.number="reviseForm.ppn_pct" type="number" step="0.01" min="0" max="100" :class="[inputCls,'text-right font-mono']" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="reviseForm.notes" :class="inputCls" /></div>
                </div>
                <div class="space-y-1 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
                    <div class="flex justify-between"><span>Subtotal</span><span class="font-mono">{{ fmtRp(reviseSubtotal) }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold dark:border-neutral-700"><span>Total Baru</span><span class="font-mono text-primary-700">{{ fmtRp(reviseTotal) }}</span></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="reviseForm.processing">Simpan Revisi</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Posting Termin -->
            <form v-else-if="panel === 'termin'" @submit.prevent="submitTermin" class="space-y-3">
                <h2 class="font-semibold">Posting Termin (Uang Muka)</h2>
                <p class="text-sm text-neutral-500">Jurnal: Dr Uang Muka Pembelian · Cr Kas/Bank. Sisa nilai PO: {{ fmtRp(dpRemaining) }}.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="terminForm.termin_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Uang Muka *</label><input v-model.number="terminForm.amount" type="number" step="1" min="1" :max="dpRemaining" required :class="[inputCls,'text-right font-mono']" /><p v-if="terminForm.errors.amount" class="mt-1 text-xs text-danger-500">{{ terminForm.errors.amount }}</p></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Uang Muka (Debit) *</label><SearchSelect v-model="terminForm.advance_account_id" :options="advanceAccounts" placeholder="— uang muka —" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Kas/Bank (Kredit) *</label><SearchSelect v-model="terminForm.cash_account_id" :options="cashAccounts" placeholder="— kas/bank —" /></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="terminForm.note" :class="inputCls" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="terminForm.processing">Posting &amp; Jurnal</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Adjust PPN -->
            <form v-else @submit.prevent="submitPpn" class="space-y-3">
                <h2 class="font-semibold">Adjust PPN PO</h2>
                <p class="text-sm text-neutral-500">Set ulang % PPN; total dihitung ulang dari subtotal {{ fmtRp(order.subtotal) }}.</p>
                <div class="flex items-end gap-3">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN Baru (%) *</label><input v-model.number="ppnForm.ppn_pct" type="number" step="0.01" min="0" max="100" required :class="[inputCls,'w-32 text-right font-mono']" /></div>
                    <div class="text-sm">Total baru: <span class="font-mono font-semibold">{{ fmtRp(Number(order.subtotal) + Math.round(Number(order.subtotal) * (Number(ppnForm.ppn_pct)||0))/100) }}</span></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="ppnForm.processing">Simpan PPN</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Item</th><th class="px-4 py-3 text-right">Qty</th><th class="px-4 py-3 text-right">Diterima</th><th class="px-4 py-3 text-right">Harga</th><th class="px-4 py-3 text-right">Jumlah</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in order.lines" :key="l.id">
                        <td class="px-4 py-2.5">{{ l.item?.code }} - {{ l.item?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.qty).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.qty_received).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.unit_price) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 dark:bg-neutral-800 text-sm">
                    <tr><td colspan="4" class="px-4 py-2 text-right text-neutral-500">Subtotal</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(order.subtotal) }}</td></tr>
                    <tr><td colspan="4" class="px-4 py-2 text-right text-neutral-500">PPN {{ order.ppn_pct }}%</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(order.ppn_amount) }}</td></tr>
                    <tr class="font-bold"><td colspan="4" class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right font-mono text-primary-600">{{ fmtRp(order.total) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
