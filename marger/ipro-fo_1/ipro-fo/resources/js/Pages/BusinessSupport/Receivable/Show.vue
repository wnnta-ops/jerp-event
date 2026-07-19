<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Coins, Wallet, BookText, CalendarClock, Plus, Trash2, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    invoice: any;
    outstanding: number;
    cashAccounts: Array<any>;
    defaultCash: number | null;
}>();

// ── Edit metadata (nilai & jurnal TERKUNCI; hanya tanggal / jatuh tempo / keterangan / no. ref) ──
// Tanggal dari controller (toArray) berbentuk ISO berwaktu ("2026-07-19T00:00:00…Z"); <input type=date>
// hanya menerima "yyyy-mm-dd", jadi dipotong 10 char — kalau tidak, field tampil KOSONG dan tombol
// Simpan terblokir (invoice_date required) meski operator hanya ingin merapikan keterangan.
const ymd = (d: string | null | undefined) => (d ? String(d).slice(0, 10) : '');
const editing = ref(false);
const edit = useForm({
    invoice_date: ymd(props.invoice.invoice_date),
    due_date: ymd(props.invoice.due_date),
    description: props.invoice.description ?? '',
    customer_ref_no: props.invoice.customer_ref_no ?? '',
});
function simpanEdit() {
    edit.patch(route('bs.ar.update', props.invoice.id), {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
}
function batalEdit() { edit.reset(); edit.clearErrors(); editing.value = false; }

const showPay = ref(false);
const today = new Date().toISOString().slice(0, 10);
const pay = useForm<any>({
    payment_date: today, cash_account_id: props.defaultCash ?? '', amount: props.outstanding, reference: '',
});
function submitPay() { pay.post(route('bs.ar.pay', props.invoice.id), { onSuccess: () => { showPay.value = false; } }); }

const showResched = ref(false);
const resched = useForm<any>({ schedules: [{ due_date: today, amount: props.outstanding }], note: '' });
const reschedTotal = computed(() => resched.schedules.reduce((s: number, r: any) => s + (Number(r.amount) || 0), 0));
const reschedOk = computed(() => Math.abs(reschedTotal.value - props.outstanding) < 1);
function addResched() { resched.schedules.push({ due_date: today, amount: 0 }); }
function removeResched(i: number) { resched.schedules.splice(i, 1); }
function submitResched() { resched.post(route('bs.ar.reschedule', props.invoice.id), { onSuccess: () => { showResched.value = false; } }); }

const trxLabel: Record<string, string> = { normal: 'Normal', denda: 'Realisasi Denda', saldo_awal: 'Saldo Awal' };
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><Coins class="h-6 w-6 text-success-600" /> {{ invoice.invoice_number }}</h1>
            <div class="flex items-center gap-2">
                <Button v-if="!editing" variant="secondary" size="sm" @click="editing = true"><template #icon-left><Pencil class="h-4 w-4" /></template>Edit Rincian</Button>
                <Badge :variant="invoice.status === 'paid' ? 'success' : 'warning'">{{ invoice.status === 'paid' ? 'Lunas' : 'Open' }}</Badge>
            </div>
        </div>

        <!-- Rincian invoice. Nilai (DPP/PPN/PPh/total) & jurnal TERKUNCI; hanya metadata yang bisa diedit. -->
        <div v-if="!editing" class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Customer</div><div class="font-medium">{{ invoice.customer?.full_name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Kategori</div><div class="font-medium">{{ invoice.category?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Tipe Transaksi</div><div class="font-medium">{{ trxLabel[invoice.trx_type] ?? invoice.trx_type }}</div></div>
            <div><div class="text-neutral-500">Tgl Invoice</div><div class="font-medium">{{ fmtDate(invoice.invoice_date) }}</div></div>
            <div><div class="text-neutral-500">Tgl Serah Tagih</div><div class="font-medium">{{ fmtDate(invoice.receive_date) }}</div></div>
            <div><div class="text-neutral-500">Jatuh Tempo</div><div class="font-medium">{{ fmtDate(invoice.due_date) }}</div></div>
            <div v-if="invoice.customer_ref_no"><div class="text-neutral-500">No. Ref. Customer</div><div class="font-medium">{{ invoice.customer_ref_no }}</div></div>
            <div v-if="invoice.manual_no"><div class="text-neutral-500">No. Manual</div><div class="font-medium">{{ invoice.manual_no }}</div></div>
            <div class="md:col-span-3"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ invoice.description }}</div></div>
        </div>
        <form v-else @submit.prevent="simpanEdit" class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <p class="md:col-span-2 text-xs text-neutral-500">Nilai tagihan & jurnal terkunci — yang bisa diubah hanya tanggal, jatuh tempo, no. ref, dan keterangan.</p>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Invoice *</label><input v-model="edit.invoice_date" type="date" required :class="inputCls" /><p v-if="edit.errors.invoice_date" class="mt-1 text-xs text-danger-500">{{ edit.errors.invoice_date }}</p></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo</label><input v-model="edit.due_date" type="date" :class="inputCls" /><p v-if="edit.errors.due_date" class="mt-1 text-xs text-danger-500">{{ edit.errors.due_date }}</p></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Ref. Customer</label><input v-model="edit.customer_ref_no" maxlength="80" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="edit.description" maxlength="255" required :class="inputCls" /><p v-if="edit.errors.description" class="mt-1 text-xs text-danger-500">{{ edit.errors.description }}</p></div>
            <div class="md:col-span-2 flex gap-2"><Button type="submit" :disabled="edit.processing">Simpan</Button><button type="button" @click="batalEdit" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
        </form>

        <div class="card p-5">
            <div class="grid gap-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between"><span class="text-neutral-500">DPP</span><span class="font-mono">{{ fmtRp(invoice.dpp) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">PPN ({{ invoice.ppn_pct }}%)</span><span class="font-mono">{{ fmtRp(invoice.ppn_amount) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold dark:border-neutral-700"><span>Total Tagihan</span><span class="font-mono">{{ fmtRp(invoice.total) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 dark:border-neutral-700"><span class="text-neutral-500">PPh dipungut pelanggan ({{ invoice.pph_pct }}%)</span><span class="font-mono text-danger-600">{{ fmtRp(invoice.pph_amount) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold dark:border-neutral-700"><span>Net Ditagih</span><span class="font-mono">{{ fmtRp(invoice.receivable_amount ?? invoice.net_receivable) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">Terbayar</span><span class="font-mono text-success-600">{{ fmtRp(invoice.paid_amount) }}</span></div>
                <div class="flex justify-between sm:col-span-2 rounded-lg bg-neutral-50 px-3 py-2 font-bold dark:bg-neutral-800"><span>Sisa Piutang</span><span class="font-mono" :class="outstanding > 0 ? 'text-success-600' : 'text-neutral-400'">{{ fmtRp(outstanding) }}</span></div>
            </div>
            <div v-if="invoice.journal_id" class="mt-3 text-xs text-neutral-400">Jurnal pendaftaran: #{{ invoice.journal_id }} (Dr Piutang<template v-if="Number(invoice.pph_amount) > 0"> + PPh dibayar dimuka</template> / Cr Pendapatan<template v-if="Number(invoice.ppn_amount) > 0"> + PPN Keluaran</template>)</div>
            <div v-if="invoice.status !== 'paid'" class="mt-4">
                <Button v-if="!showPay" variant="primary" @click="showPay = true"><template #icon-left><Wallet class="h-4 w-4" /></template>Terima Pembayaran</Button>
                <form v-else @submit.prevent="submitPay" class="grid gap-3 rounded-lg border border-neutral-200 p-4 dark:border-neutral-700 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Terima *</label><input v-model="pay.payment_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Ke Kas/Bank *</label><select v-model="pay.cash_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label><input v-model.number="pay.amount" type="number" step="1" min="1" :max="outstanding" required :class="[inputCls,'text-right']" /><p v-if="pay.errors.amount" class="mt-1 text-xs text-danger-500">{{ pay.errors.amount }}</p></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Referensi</label><input v-model="pay.reference" placeholder="No. transfer / bukti" :class="inputCls" /></div>
                    <div class="sm:col-span-2 flex gap-2">
                        <Button type="submit" :disabled="pay.processing">Simpan Penerimaan</Button>
                        <button type="button" @click="showPay = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Detil Tagihan -->
        <div v-if="invoice.lines?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Detil Tagihan</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Qty</th><th class="px-4 py-2.5 text-right">Harga</th><th class="px-4 py-2.5 text-right">Jumlah</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in invoice.lines" :key="l.id">
                        <td class="px-4 py-2.5">{{ l.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.qty).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.unit_price) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Struktur Penerimaan + Reschedule jatuh tempo -->
        <div v-if="invoice.schedules?.length || outstanding > 0" class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <span class="font-semibold">Struktur Penerimaan</span>
                <button v-if="invoice.status !== 'paid' && outstanding > 0" @click="showResched = !showResched" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium" :class="showResched ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'"><CalendarClock class="h-3.5 w-3.5" /> Jadwal Ulang</button>
            </div>
            <table v-if="invoice.schedules?.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Termin</th><th class="px-4 py-2.5">Jatuh Tempo</th><th class="px-4 py-2.5 text-right">Jumlah</th><th class="px-4 py-2.5 text-right">Terbayar</th><th class="px-4 py-2.5 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="s in invoice.schedules" :key="s.id">
                        <td class="px-4 py-2.5 font-mono">#{{ s.installment_no }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(s.due_date) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(s.amount) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(s.paid_amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="s.status === 'paid' ? 'success' : (Number(s.paid_amount) > 0 ? 'warning' : 'neutral')" size="sm">{{ s.status === 'paid' ? 'Lunas' : (Number(s.paid_amount) > 0 ? 'Sebagian' : 'Belum') }}</Badge></td>
                    </tr>
                </tbody>
            </table>

            <!-- Editor jadwal ulang -->
            <form v-if="showResched" @submit.prevent="submitResched" class="space-y-3 border-t border-neutral-200 p-4 dark:border-neutral-800">
                <p class="text-xs text-neutral-500">Jadwal ulang <b>sisa piutang {{ fmtRp(outstanding) }}</b>. Angsuran yang sudah dibayar tetap terkunci. Tanpa jurnal — hanya mengubah jatuh tempo.</p>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-1">Jatuh Tempo</th><th class="w-44 py-1 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in resched.schedules" :key="i">
                            <td class="py-1 pr-2"><input v-model="r.due_date" type="date" required :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model.number="r.amount" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="py-1 text-center"><button type="button" @click="removeResched(i)" :disabled="resched.schedules.length === 1" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" @click="addResched" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Termin</button>
                <div class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 text-sm font-semibold dark:bg-neutral-800" :class="reschedOk ? 'text-success-700' : 'text-danger-600'">
                    <span>Total Jadwal Baru vs Sisa Piutang</span><span class="font-mono">{{ fmtRp(reschedTotal) }} / {{ fmtRp(outstanding) }}</span>
                </div>
                <p v-if="resched.errors['schedules']" class="text-xs text-danger-500">{{ resched.errors['schedules'] }}</p>
                <div class="flex gap-2"><Button type="submit" :disabled="resched.processing || !reschedOk">Simpan Jadwal Ulang</Button><button type="button" @click="showResched = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Penerimaan</div>
            <table v-if="invoice.payments?.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tanggal</th><th class="px-4 py-2.5">Referensi</th><th class="px-4 py-2.5 text-right">Nominal</th><th class="px-4 py-2.5 text-center">Jurnal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in invoice.payments" :key="p.id">
                        <td class="px-4 py-2.5">{{ fmtDate(p.payment_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ p.reference ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(p.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><span v-if="p.journal_id" class="inline-flex items-center gap-1 text-xs text-neutral-400"><BookText class="h-3.5 w-3.5" /> #{{ p.journal_id }}</span></td>
                    </tr>
                </tbody>
            </table>
            <div v-else class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada penerimaan.</div>
        </div>
    </div>
</template>
