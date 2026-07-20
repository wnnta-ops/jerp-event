<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Coins, Wallet, BookText, CalendarClock, Plus, Trash2, Pencil, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    invoice: any;
    outstanding: number;
    cashAccounts: Array<any>;
    defaultCash: number | null;
}>();

// ── Ladder nilai — rincian disamakan dengan "Sudah Booked Revenue" ──
// Harga − Diskon/Free DP → DPP (Nilai Revenue) → PPN → Total. Harga & diskon tidak tersimpan di
// invoice; datang dari Booked Revenue asal (NULL untuk invoice lama → baris Harga/Diskon disembunyikan).
// Invoice piutang TIDAK punya jurnal sendiri (lahir dari Booked Revenue) — tidak ada jejak jurnal di sini.
const hasGross = computed(() => props.invoice.gross_price != null);
const total = computed(() => Number(props.invoice.total ?? 0));
const dpp = computed(() => Number(props.invoice.dpp ?? 0));
const ppn = computed(() => Number(props.invoice.ppn_amount ?? 0));
const pph = computed(() => Number(props.invoice.pph_amount ?? 0));
const netReceivable = computed(() => Number(props.invoice.receivable_amount ?? props.invoice.net_receivable ?? 0));

// ── Edit metadata (nilai TERKUNCI; hanya tanggal / jatuh tempo / keterangan / no. ref) ──
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
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Receivable</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold">
                <Coins class="h-6 w-6 text-success-600" /> {{ invoice.invoice_number }}
                <Badge :variant="invoice.status === 'paid' ? 'success' : 'warning'">{{ invoice.status === 'paid' ? 'Lunas' : 'Open' }}</Badge>
            </h1>
            <Button v-if="!editing" variant="secondary" @click="editing = true"><template #icon-left><Pencil class="h-4 w-4" /></template>Edit Rincian</Button>
        </div>

        <p class="flex items-center gap-1.5 rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-500 dark:bg-neutral-800">
            <Lock class="h-3.5 w-3.5" /> Invoice ini lahir otomatis dari <strong>Booked Revenue</strong> dan tidak dibuat manual. Nilai terkunci; yang bisa diubah hanya tanggal, jatuh tempo, no. ref, dan keterangan.
        </p>

        <!-- Rincian — disamakan dengan Sudah Booked Revenue (ladder nilai + Customer & Unit) -->
        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Nilai -->
            <div class="card p-5 lg:col-span-1">
                <div class="text-xs uppercase text-neutral-400">Total (termasuk PPN)</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(total) }}</div>
                <dl class="mt-3 space-y-1 text-sm">
                    <div v-if="hasGross" class="flex justify-between"><dt class="text-neutral-500">Harga</dt><dd class="tabular-nums">{{ fmtRp(invoice.gross_price) }}</dd></div>
                    <div v-if="hasGross" class="flex justify-between"><dt class="text-neutral-500">Diskon/Free DP</dt><dd class="tabular-nums text-danger-600">− {{ fmtRp(invoice.discount) }}</dd></div>
                    <div class="flex justify-between" :class="hasGross ? 'border-t border-neutral-200 pt-1 dark:border-neutral-700' : ''"><dt class="text-neutral-500">DPP (Nilai Revenue)</dt><dd class="tabular-nums">{{ fmtRp(dpp) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">PPN</dt><dd class="tabular-nums">{{ fmtRp(ppn) }}</dd></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold dark:border-neutral-700"><dt>Total</dt><dd class="tabular-nums text-primary-600">{{ fmtRp(total) }}</dd></div>
                </dl>
                <div class="mt-3 text-sm text-neutral-500">Tanggal: {{ fmtDate(invoice.invoice_date) }}</div>
            </div>

            <!-- Customer & Unit + edit metadata -->
            <div class="card p-5 text-sm lg:col-span-2">
                <div class="mb-2 font-semibold">Customer &amp; Unit</div>
                <dl class="grid grid-cols-[130px_1fr] gap-y-1.5">
                    <dt class="text-neutral-500">Customer</dt><dd><span v-if="invoice.customer"><span class="font-mono text-xs text-neutral-400">{{ invoice.customer.code }}</span> {{ invoice.customer.full_name }}</span><span v-else>—</span></dd>
                    <dt class="text-neutral-500">Telepon</dt><dd>{{ invoice.customer?.phone || '—' }}</dd>
                    <dt class="text-neutral-500">Email</dt><dd>{{ invoice.customer?.email || '—' }}</dd>
                    <dt class="text-neutral-500">No. SPR</dt><dd class="font-mono">{{ invoice.booking?.booking_number ?? '—' }}</dd>
                    <dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ invoice.booking?.unit?.unit_code ?? '—' }}</dd>
                </dl>

                <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <template v-if="!editing">
                        <dl class="grid grid-cols-[130px_1fr] gap-y-1.5">
                            <dt class="text-neutral-500">Tgl Invoice</dt><dd>{{ fmtDate(invoice.invoice_date) }}</dd>
                            <template v-if="invoice.receive_date"><dt class="text-neutral-500">Tgl Serah Tagih</dt><dd>{{ fmtDate(invoice.receive_date) }}</dd></template>
                            <dt class="text-neutral-500">Jatuh Tempo</dt><dd>{{ fmtDate(invoice.due_date) }}</dd>
                            <template v-if="invoice.customer_ref_no"><dt class="text-neutral-500">No. Ref. Customer</dt><dd>{{ invoice.customer_ref_no }}</dd></template>
                            <template v-if="invoice.manual_no"><dt class="text-neutral-500">No. Manual</dt><dd>{{ invoice.manual_no }}</dd></template>
                            <template v-if="invoice.trx_type && invoice.trx_type !== 'normal'"><dt class="text-neutral-500">Tipe Transaksi</dt><dd>{{ trxLabel[invoice.trx_type] ?? invoice.trx_type }}</dd></template>
                            <dt class="text-neutral-500">Keterangan</dt><dd>{{ invoice.description || '—' }}</dd>
                        </dl>
                    </template>
                    <form v-else @submit.prevent="simpanEdit" class="space-y-3">
                        <p class="text-xs text-neutral-400">Nilai tagihan terkunci — yang bisa diubah hanya tanggal, jatuh tempo, no. ref, dan keterangan.</p>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Invoice *</label>
                            <input v-model="edit.invoice_date" type="date" required :class="inputCls" />
                            <p v-if="edit.errors.invoice_date" class="mt-1 text-xs text-danger-500">{{ edit.errors.invoice_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo</label>
                            <input v-model="edit.due_date" type="date" :class="inputCls" />
                            <p v-if="edit.errors.due_date" class="mt-1 text-xs text-danger-500">{{ edit.errors.due_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">No. Ref. Customer</label>
                            <input v-model="edit.customer_ref_no" maxlength="80" :class="inputCls" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label>
                            <input v-model="edit.description" maxlength="255" required :class="inputCls" />
                            <p v-if="edit.errors.description" class="mt-1 text-xs text-danger-500">{{ edit.errors.description }}</p>
                        </div>
                        <div class="flex gap-2">
                            <Button type="submit" :disabled="edit.processing">Simpan</Button>
                            <button type="button" @click="batalEdit" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Penagihan (fitur AR — di luar rincian Booked Revenue) -->
        <div class="card p-5">
            <div class="grid gap-2 text-sm sm:grid-cols-2">
                <div v-if="pph > 0" class="flex justify-between"><span class="text-neutral-500">PPh dipungut pelanggan ({{ invoice.pph_pct }}%)</span><span class="font-mono text-danger-600">{{ fmtRp(pph) }}</span></div>
                <div v-if="pph > 0" class="flex justify-between font-semibold"><span>Net Ditagih</span><span class="font-mono">{{ fmtRp(netReceivable) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">Terbayar</span><span class="font-mono text-success-600">{{ fmtRp(invoice.paid_amount) }}</span></div>
                <div class="flex justify-between sm:col-span-2 rounded-lg bg-neutral-50 px-3 py-2 font-bold dark:bg-neutral-800"><span>Sisa Piutang</span><span class="font-mono" :class="outstanding > 0 ? 'text-success-600' : 'text-neutral-400'">{{ fmtRp(outstanding) }}</span></div>
            </div>
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
            <div v-else-if="!showResched" class="px-4 py-6 text-center text-sm text-neutral-400">
                Tanpa termin — tagih penuh {{ fmtRp(outstanding) }} pada jatuh tempo {{ fmtDate(invoice.due_date) }}. Gunakan <b>Jadwal Ulang</b> untuk memecah menjadi beberapa termin.
            </div>

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
