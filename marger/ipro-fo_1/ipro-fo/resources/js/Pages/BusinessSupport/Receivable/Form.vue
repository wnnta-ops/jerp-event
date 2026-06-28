<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Save, Coins, Plus, Trash2, Check, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    customers: Array<any>;
    categories: Array<any>;
    pphAccounts: Array<any>;
    trxTypes: Record<string, string>;
}>();

const steps = ['Pilih Customer', 'Input Transaksi', 'Detil Tagihan', 'Struktur', 'Selesai'];
const step = ref(1);
const today = new Date().toISOString().slice(0, 10);
const customerSearch = ref('');

const form = useForm<any>({
    customer_id: '', category_id: '', trx_type: 'normal', customer_ref_no: '', manual_no: '',
    invoice_date: today, receive_date: today, term_days: 30, due_date: '',
    description: '', transfer_detail: '', kurs: 1, dpp: 0,
    ppn_mode: 'none', ppn_pct: 11, pph_mode: 'none', pph_pct: 2, pph_account_id: '',
    lines: [], schedules: [],
});

const filteredCustomers = computed(() => {
    const q = customerSearch.value.toLowerCase();
    return q ? props.customers.filter((s: any) => (s.full_name + s.code).toLowerCase().includes(q)) : props.customers;
});
const customer = computed(() => props.customers.find((s: any) => s.id === form.customer_id));
const category = computed(() => props.categories.find((c: any) => c.id === form.category_id));

const ppn = computed(() => form.ppn_mode === 'with' ? Math.round((Number(form.dpp) || 0) * (Number(form.ppn_pct) || 0)) / 100 : 0);
const pph = computed(() => form.pph_mode === 'withheld' ? Math.round((Number(form.dpp) || 0) * (Number(form.pph_pct) || 0)) / 100 : 0);
const total = computed(() => (Number(form.dpp) || 0) + ppn.value);
const net = computed(() => total.value - pph.value);
const dueDate = computed(() => form.due_date || (form.term_days ? new Date(new Date(form.invoice_date).getTime() + form.term_days * 86400000).toISOString().slice(0, 10) : form.invoice_date));
const linesSum = computed(() => form.lines.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));
const schedSum = computed(() => form.schedules.reduce((s: number, l: any) => s + (Number(l.amount) || 0), 0));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function pickCustomer(s: any) { form.customer_id = s.id; step.value = 2; }
function addLine() { form.lines.push({ description: form.description || '', qty: 1, unit_price: 0, amount: 0 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function addSchedule() { form.schedules.push({ due_date: dueDate.value, amount: 0 }); }
function removeSchedule(i: number) { form.schedules.splice(i, 1); }

// Saat masuk Struktur, default 1 termin = net jika kosong.
watch(step, (s) => {
    if (s === 4 && form.schedules.length === 0) form.schedules.push({ due_date: dueDate.value, amount: net.value });
});

function canNext() {
    if (step.value === 1) return !!form.customer_id;
    if (step.value === 2) return !!form.category_id && Number(form.dpp) > 0 && !!form.description;
    return true;
}
function next() { if (canNext() && step.value < 5) step.value++; }
function prev() { if (step.value > 1) step.value--; }
function submit() { form.post(route('bs.ar.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Coins class="h-6 w-6 text-success-600" /> Registrasi Invoice Piutang</h1>
            <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
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

        <!-- Step 1: Pilih Customer -->
        <div v-show="step === 1" class="card p-5">
            <div class="relative mb-3"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="customerSearch" placeholder="Cari customer…" :class="[inputCls,'pl-9']" /></div>
            <div class="max-h-96 divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                <button v-for="s in filteredCustomers" :key="s.id" type="button" @click="pickCustomer(s)" class="flex w-full items-center justify-between px-2 py-2.5 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="form.customer_id === s.id ? 'bg-primary-50 dark:bg-primary-900/20' : ''">
                    <span><span class="font-mono text-xs text-neutral-400">{{ s.code }}</span> <span class="font-medium">{{ s.full_name }}</span></span>
                    <ArrowRight class="h-4 w-4 text-neutral-300" />
                </button>
            </div>
        </div>

        <!-- Step 2: Input Transaksi -->
        <div v-show="step === 2" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div class="md:col-span-2 rounded-lg bg-neutral-50 px-4 py-2 text-sm dark:bg-neutral-800">Customer: <span class="font-semibold">{{ customer?.full_name }}</span></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Invoice *</label><select v-model="form.category_id" required :class="inputCls"><option value="">— pilih kategori —</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option></select>
                    <p v-if="category" class="mt-1 text-[11px] text-neutral-400">Cr {{ category.income }} · Dr {{ category.receivable }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tipe Transaksi *</label><select v-model="form.trx_type" :class="inputCls"><option v-for="(label,val) in trxTypes" :key="val" :value="val">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Ref. Customer</label><input v-model="form.customer_ref_no" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Invoice *</label><input v-model="form.invoice_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl. Serah Tagih</label><input v-model="form.receive_date" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo (hari)</label><input v-model.number="form.term_days" type="number" min="0" :class="[inputCls,'text-right']" /><p class="mt-1 text-[11px] text-neutral-400">Jatuh tempo: {{ dueDate }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.manual_no" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Detil Transfer</label><input v-model="form.transfer_detail" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kurs</label><input v-model.number="form.kurs" type="number" step="0.0001" min="0" :class="[inputCls,'text-right']" /></div>
            </div>
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Transaksi (DPP) *</label><input v-model.number="form.dpp" type="number" step="1" min="0" required :class="[inputCls,'text-right font-mono']" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPN</label><div class="flex gap-2"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="none" class="sr-only" />Tanpa PPN</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='with' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="with" class="sr-only" />Dengan PPN</label></div><input v-if="form.ppn_mode==='with'" v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" class="mt-2 h-8 w-full rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPh</label><div class="flex gap-2"><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.pph_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="none" class="sr-only" />Tanpa PPh</label><label class="flex flex-1 cursor-pointer items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-xs" :class="form.pph_mode==='withheld' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="withheld" class="sr-only" />Dipungut Pelanggan</label></div>
                    <div v-if="form.pph_mode==='withheld'" class="mt-2 flex gap-1"><input v-model.number="form.pph_pct" type="number" step="0.5" min="0" max="100" class="h-8 w-16 rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" /><select v-model="form.pph_account_id" class="h-8 flex-1 rounded-lg border border-neutral-300 px-1 text-xs dark:border-neutral-700 dark:bg-neutral-900"><option value="">Akun PPh…</option><option v-for="a in pphAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></div></div>
                <div class="md:col-span-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800 sm:grid-cols-4">
                    <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(form.dpp) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPh dipungut</div><div class="font-mono text-danger-600">{{ fmtRp(pph) }}</div></div>
                    <div><div class="text-xs text-neutral-400">Ditagih (net)</div><div class="font-mono font-bold text-success-700 dark:text-success-300">{{ fmtRp(net) }}</div></div>
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

        <!-- Step 4: Struktur -->
        <div v-show="step === 4" class="card p-5">
            <div class="mb-2 flex items-center justify-between"><h2 class="font-semibold">Struktur Penerimaan</h2><button type="button" @click="addSchedule" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Termin</button></div>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1 w-16">Termin</th><th class="py-1">Jatuh Tempo</th><th class="w-40 py-1 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                <tbody>
                    <tr v-for="(s,i) in form.schedules" :key="i" class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1 font-mono">#{{ i+1 }}</td>
                        <td class="py-1 pr-2"><input v-model="s.due_date" type="date" :class="inputCls" /></td>
                        <td class="py-1 pr-2"><input v-model.number="s.amount" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                        <td class="py-1 text-center"><button type="button" @click="removeSchedule(i)" :disabled="form.schedules.length===1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
            </table>
            <div class="mt-2 flex justify-end gap-6 text-sm"><span class="text-neutral-500">Total termin: <b class="font-mono">{{ fmtRp(schedSum) }}</b></span><span :class="Math.abs(schedSum - net) < 1 ? 'text-success-600' : 'text-amber-600'">Net ditagih: {{ fmtRp(net) }}</span></div>
        </div>

        <!-- Step 5: Selesai -->
        <div v-show="step === 5" class="card p-5 text-sm">
            <h2 class="mb-3 font-semibold">Ringkasan</h2>
            <div class="grid gap-2 sm:grid-cols-2">
                <div><span class="text-neutral-500">Customer:</span> {{ customer?.full_name }}</div>
                <div><span class="text-neutral-500">Kategori:</span> {{ category?.name }}</div>
                <div><span class="text-neutral-500">Tipe:</span> {{ trxTypes[form.trx_type] }}</div>
                <div><span class="text-neutral-500">Jatuh tempo:</span> {{ dueDate }}</div>
                <div class="sm:col-span-2"><span class="text-neutral-500">Keterangan:</span> {{ form.description }}</div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800 sm:grid-cols-4">
                <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(form.dpp) }}</div></div>
                <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                <div><div class="text-xs text-neutral-400">PPh</div><div class="font-mono text-danger-600">{{ fmtRp(pph) }}</div></div>
                <div><div class="text-xs text-neutral-400">Net Ditagih</div><div class="font-mono font-bold text-success-700 dark:text-success-300">{{ fmtRp(net) }}</div></div>
            </div>
            <p class="mt-3 rounded-lg bg-success-50 px-3 py-2 text-xs text-success-700 dark:bg-success-900/20 dark:text-success-300">Jurnal: Dr {{ category?.receivable }} ({{ fmtRp(net) }})<template v-if="pph"> · Dr PPh Dibayar Dimuka ({{ fmtRp(pph) }})</template> / Cr {{ category?.income }} ({{ fmtRp(form.dpp) }})<template v-if="ppn"> · Cr PPN Keluaran ({{ fmtRp(ppn) }})</template></p>
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
