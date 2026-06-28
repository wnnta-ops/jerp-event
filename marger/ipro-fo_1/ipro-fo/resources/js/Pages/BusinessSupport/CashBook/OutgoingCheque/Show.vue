<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Printer, Send, CheckCircle2, Ban, ChevronDown, BookText, Link2, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

interface OpenInvoice { id: number; invoice_number: string; supplier_id: number; description: string | null; outstanding: number; due_date: string | null }
const props = defineProps<{
    cheque: any;
    types: Record<string, string>;
    statuses: Record<string, string>;
    suppliers?: Array<any>;
    openInvoices?: OpenInvoice[];
    debitAccounts?: Array<any>;
    defaultDebitAccount?: number | null;
    liabilityAccount?: { id: number; code: string; name: string } | null;
}>();
const today = new Date().toISOString().slice(0, 10);
const handoverForm = useForm<any>({ handover_date: today, supplier_id: '', lines: [] as Array<{ ap_invoice_id: number; amount: number }>, debit_account_id: props.defaultDebitAccount ?? '' });
const clearForm = useForm<any>({ cleared_date: today });
const voidForm = useForm<any>({ void_reason: '' });

// Warkat yang sudah punya baris invoice (dipilih lewat form Tambah) → langsung serahkan.
// Warkat tanpa baris → buka dialog pilih supplier + invoice yang dilunasi saat penyerahan.
const hasLines = computed<boolean>(() => Array.isArray(props.cheque.lines) && props.cheque.lines.length > 0);
// Warkat "tidak berhubungan hutang" (pihak ketiga) → dialog struktur jurnal (Dr beban / Cr BG-Cek Beredar).
const isPihakKetiga = computed<boolean>(() => props.cheque.payee_type === 'pihak_ketiga');
const showHandover = ref(false);
const showJournal = ref(false);

// ── Picker pelunasan saat penyerahan (mirror form Tambah) ──
const picks = ref<Record<number, { checked: boolean; amount: number }>>({});
const supplierInvoices = computed<OpenInvoice[]>(() =>
    handoverForm.supplier_id ? (props.openInvoices ?? []).filter((i) => i.supplier_id === Number(handoverForm.supplier_id)) : []);
const selectedLines = computed(() =>
    supplierInvoices.value
        .filter((i) => picks.value[i.id]?.checked && Number(picks.value[i.id]?.amount) > 0)
        .map((i) => ({ ap_invoice_id: i.id, amount: Number(picks.value[i.id].amount) })));
const settleTotal = computed(() => selectedLines.value.reduce((s, l) => s + l.amount, 0));
// Baris dicentang tapi nominal kosong/<=0/melebihi sisa → blokir submit & beri tanda (cegah drop diam-diam).
function pickInvalid(inv: OpenInvoice): boolean {
    const p = picks.value[inv.id];
    if (!p?.checked) return false;
    const a = Number(p.amount);
    return !(a > 0 && a <= inv.outstanding + 0.01);
}
const hasInvalidPick = computed<boolean>(() => supplierInvoices.value.some((i) => pickInvalid(i)));
watch(() => handoverForm.supplier_id, () => { picks.value = {}; });
watch(selectedLines, () => { handoverForm.lines = selectedLines.value; }, { deep: true });
function toggleInv(inv: OpenInvoice) {
    const cur = picks.value[inv.id];
    picks.value[inv.id] = cur?.checked ? { checked: false, amount: 0 } : { checked: true, amount: inv.outstanding };
}

function doHandover() { handoverForm.post(route('bs.cheque-out.handover', props.cheque.id), { preserveScroll: true, onSuccess: () => { showHandover.value = false; showJournal.value = false; } }); }
const debitAccountName = computed(() => {
    const a = (props.debitAccounts ?? []).find((x) => Number(x.id) === Number(handoverForm.debit_account_id));
    return a ? (a.code + ' — ' + a.name) : '—';
});
function doClear() { clearForm.post(route('bs.cheque-out.clear', props.cheque.id), { preserveScroll: true }); }
function doVoid() {
    if (!confirm('Tandai BG/Cek ini sebagai VOID (dibatalkan)? Tindakan ini hanya membatalkan pencatatan warkat, bukan reversal jurnal pembayaran.')) return;
    voidForm.post(route('bs.cheque-out.void', props.cheque.id), { preserveScroll: true });
}

const printOpen = ref(false);
function closePrintSoon() { setTimeout(() => { printOpen.value = false; }, 150); }
function printUrl(mode: string) { return route('bs.cheque-out.print', { cheque: props.cheque.id, mode }); }

const linesTotal = computed<number>(() => (props.cheque.lines ?? []).reduce((s: number, l: any) => s + Number(l.amount ?? 0), 0));
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
const badge: Record<string, any> = { available: 'neutral', issued: 'warning', cleared: 'success', void: 'danger' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <Link :href="route('bs.cheque-out.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> BG/Cek Keluar</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> {{ cheque.cheque_number }}</h1>
            <div class="flex items-center gap-2">
                <!-- #73 opsi cetak: dengan tanggal / tanpa tanggal / hanya tanggal -->
                <div class="relative">
                    <button type="button" @click="printOpen = !printOpen" @blur="closePrintSoon" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> Cetak BG/Cek <ChevronDown class="h-3.5 w-3.5" /></button>
                    <div v-if="printOpen" class="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-lg border border-neutral-200 bg-white text-sm shadow-lg dark:border-neutral-700 dark:bg-neutral-800">
                        <a :href="printUrl('with')" target="_blank" rel="noopener" class="block px-4 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-700">Cetak (dengan tanggal)</a>
                        <a :href="printUrl('without')" target="_blank" rel="noopener" class="block px-4 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-700">Cetak (tanpa tanggal)</a>
                        <a :href="printUrl('dateonly')" target="_blank" rel="noopener" class="block px-4 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-700">Cetak (hanya tanggal)</a>
                    </div>
                </div>
                <Badge :variant="badge[cheque.status] ?? 'neutral'">{{ statuses[cheque.status] }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Jenis</div><div class="font-medium">{{ types[cheque.cheque_type] }}</div></div>
            <div><div class="text-neutral-500">Bank Penerbit</div><div class="font-medium"><span class="font-mono text-xs text-neutral-400">{{ cheque.bank_account?.code }}</span> {{ cheque.bank_account?.name }}</div></div>
            <div>
                <div class="text-neutral-500">Penerima <span v-if="cheque.payee_type" class="text-[11px] text-neutral-400">({{ cheque.payee_type === 'pihak_ketiga' ? 'Pihak Ketiga' : 'Supplier/Vendor' }})</span></div>
                <div class="font-medium">{{ cheque.payee ?? '—' }}</div>
            </div>
            <div v-if="cheque.supplier"><div class="text-neutral-500">Supplier (Hutang)</div><div class="font-medium"><span class="font-mono text-xs text-neutral-400">{{ cheque.supplier.code }}</span> {{ cheque.supplier.name }}</div></div>
            <div><div class="text-neutral-500">Tgl BG/Cek</div><div class="font-medium">{{ fmtDate(cheque.cheque_date) }}</div></div>
            <!-- #72 bank/rekening penerima — hanya untuk Bilyet Giro -->
            <template v-if="cheque.cheque_type === 'bg'">
                <div><div class="text-neutral-500">Bank Penerima</div><div class="font-medium">{{ cheque.recipient_bank ?? '—' }}</div></div>
                <div><div class="text-neutral-500">No. Rekening Penerima</div><div class="font-mono font-medium">{{ cheque.recipient_account_no ?? '—' }}</div></div>
            </template>
            <div><div class="text-neutral-500">Pejabat TTD 1</div><div class="font-medium">{{ cheque.signer_name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Pejabat TTD 2</div><div class="font-medium">{{ cheque.signer_name_2 ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Nominal</div><div class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ cheque.amount != null ? fmtRp(cheque.amount) : '—' }}</div></div>
            <div><div class="text-neutral-500">Tgl Diserahkan</div><div class="font-medium">{{ fmtDate(cheque.handover_date) }}</div></div>
            <div><div class="text-neutral-500">Tgl Dicairkan</div><div class="font-medium">{{ fmtDate(cheque.cleared_date) }}</div></div>
            <div v-if="cheque.status === 'void'" class="md:col-span-2"><div class="text-neutral-500">Void</div><div class="font-medium text-danger-600">Dibatalkan {{ fmtDate(cheque.void_date) }}<span v-if="cheque.void_reason"> — {{ cheque.void_reason }}</span></div></div>
            <div v-if="cheque.description" class="md:col-span-2"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ cheque.description }}</div></div>
        </div>

        <!-- Invoice hutang yang direferensikan untuk dilunasi (multi-invoice) -->
        <div v-if="cheque.lines && cheque.lines.length" class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><Link2 class="h-5 w-5 text-primary-600" /> Pelunasan Hutang Account Payable</h2>
            <p class="mb-3 text-xs text-neutral-500">Hutang dilunasi saat warkat <b>diserahkan</b> ke supplier — tersinkron dengan Invoice Hutang, Kartu Hutang & modul AP.</p>
            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-800/60">
                        <tr><th class="px-3 py-2">No. Invoice</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2 text-right">Dilunasi</th><th class="px-3 py-2 text-center">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="ln in cheque.lines" :key="ln.id">
                            <td class="px-3 py-2 font-mono text-xs"><Link v-if="ln.invoice" :href="route('bs.ap.show', ln.ap_invoice_id)" class="text-primary-600 hover:underline dark:text-primary-400">{{ ln.invoice.invoice_number }}</Link><span v-else>—</span></td>
                            <td class="px-3 py-2 text-neutral-500">{{ ln.invoice?.description ?? '—' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtRp(ln.amount) }}</td>
                            <td class="px-3 py-2 text-center"><Badge :variant="ln.payment_id ? 'success' : 'neutral'">{{ ln.payment_id ? 'Lunas' : 'Menunggu serah' }}</Badge></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800/60">
                        <tr><td colspan="2" class="px-3 py-2 text-right">Total</td><td class="px-3 py-2 text-right font-mono text-primary-700 dark:text-primary-300">{{ fmtRp(linesTotal) }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Jurnal General Ledger — nomor jurnal serah / cair / void agar mudah ditelusuri -->
        <div v-if="cheque.handover_journal || cheque.clear_journal || cheque.void_journal" class="card p-5">
            <h2 class="mb-3 flex items-center gap-2 font-semibold"><BookText class="h-5 w-5 text-primary-600" /> Jurnal General Ledger</h2>
            <div class="grid gap-2 text-sm sm:grid-cols-2">
                <div v-if="cheque.handover_journal" class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                    <span class="text-neutral-500">Penyerahan <span class="text-[11px] text-neutral-400">(Dr Hutang / Cr BG-Cek Beredar)</span></span>
                    <Link :href="route('bs.gl.journal.show', cheque.handover_journal_id)" class="inline-flex shrink-0 items-center gap-1 font-mono text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"><BookText class="h-3.5 w-3.5" /> {{ cheque.handover_journal.journal_number }}</Link>
                </div>
                <div v-if="cheque.clear_journal" class="flex items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 dark:border-neutral-700">
                    <span class="text-neutral-500">Pencairan <span class="text-[11px] text-neutral-400">(Dr BG-Cek Beredar / Cr Bank)</span></span>
                    <Link :href="route('bs.gl.journal.show', cheque.clear_journal_id)" class="inline-flex shrink-0 items-center gap-1 font-mono text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"><BookText class="h-3.5 w-3.5" /> {{ cheque.clear_journal.journal_number }}</Link>
                </div>
                <div v-if="cheque.void_journal" class="flex items-center justify-between gap-3 rounded-lg border border-danger-200 px-3 py-2 dark:border-danger-800/50">
                    <span class="text-neutral-500">Void <span class="text-[11px] text-neutral-400">(pembalik penyerahan)</span></span>
                    <Link :href="route('bs.gl.journal.show', cheque.void_journal_id)" class="inline-flex shrink-0 items-center gap-1 font-mono text-xs font-semibold text-danger-600 hover:underline dark:text-danger-400"><BookText class="h-3.5 w-3.5" /> {{ cheque.void_journal.journal_number }}</Link>
                </div>
            </div>
        </div>

        <!-- Aksi siklus -->
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Status & Aksi</h2>
            <div v-if="cheque.status === 'available'" class="space-y-2">
                <!-- Sudah mereferensikan invoice (dipilih lewat form Tambah) → langsung serahkan & lunasi -->
                <template v-if="hasLines">
                    <div class="flex flex-wrap items-end gap-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Diserahkan ke Supplier *</label><input v-model="handoverForm.handover_date" type="date" :class="[inputCls,'w-auto']" /></div>
                        <Button :disabled="handoverForm.processing" @click="doHandover"><template #icon-left><Send class="h-4 w-4" /></template>Tandai Diserahkan (Beredar)</Button>
                    </div>
                    <p class="text-xs text-neutral-400">Penyerahan akan melunasi {{ cheque.lines.length }} invoice hutang ({{ fmtRp(linesTotal) }}).</p>
                </template>
                <!-- Pihak ketiga (tidak berhubungan hutang) → dialog struktur jurnal (Dr beban / Cr BG-Cek Beredar) -->
                <template v-else-if="isPihakKetiga">
                    <div class="flex flex-wrap items-end gap-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Diserahkan *</label><input v-model="handoverForm.handover_date" type="date" :class="[inputCls,'w-auto']" /></div>
                        <Button :disabled="handoverForm.processing" @click="showJournal = true"><template #icon-left><Send class="h-4 w-4" /></template>Tandai Diserahkan (Beredar)</Button>
                    </div>
                    <p class="text-xs text-neutral-400">Penyerahan akan memposting struktur jurnal (Dr Beban / Cr Utang BG-Cek Beredar).</p>
                </template>
                <!-- Belum ada referensi → buka dialog pilih supplier & invoice yang dilunasi saat penyerahan -->
                <template v-else>
                    <div class="flex flex-wrap items-end gap-2">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Diserahkan ke Supplier *</label><input v-model="handoverForm.handover_date" type="date" :class="[inputCls,'w-auto']" /></div>
                        <Button :disabled="handoverForm.processing" @click="showHandover = true"><template #icon-left><Send class="h-4 w-4" /></template>Tandai Diserahkan (Beredar)</Button>
                    </div>
                    <p class="text-xs text-neutral-400">Saat penyerahan Anda dapat memilih supplier &amp; invoice hutang yang dilunasi warkat ini.</p>
                </template>
            </div>
            <div v-else-if="cheque.status === 'issued'" class="flex flex-wrap items-end gap-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Dicairkan *</label><input v-model="clearForm.cleared_date" type="date" :class="[inputCls,'w-auto']" /></div>
                <Button variant="success" :disabled="clearForm.processing" @click="doClear"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Tandai Dicairkan</Button>
            </div>
            <div v-else-if="cheque.status === 'cleared'" class="flex items-center gap-2 text-sm text-success-600"><CheckCircle2 class="h-4 w-4" /> BG/Cek sudah dicairkan pada {{ fmtDate(cheque.cleared_date) }}.</div>
            <div v-else class="flex items-center gap-2 text-sm text-danger-600"><Ban class="h-4 w-4" /> BG/Cek di-VOID pada {{ fmtDate(cheque.void_date) }}.<span v-if="cheque.void_reason"> {{ cheque.void_reason }}</span></div>

            <!-- #74 VOID — di bagian bawah, selama belum cair/void -->
            <div v-if="cheque.status !== 'cleared' && cheque.status !== 'void'" class="mt-4 flex flex-wrap items-end gap-2 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                <div class="flex-1 min-w-48"><label class="mb-1 block text-xs font-medium text-neutral-500">Alasan Void (opsional)</label><input v-model="voidForm.void_reason" placeholder="mis. salah cetak / batal bayar" :class="inputCls" /></div>
                <Button variant="danger" :disabled="voidForm.processing" @click="doVoid"><template #icon-left><Ban class="h-4 w-4" /></template>Void / Batalkan BG/Cek</Button>
            </div>
            <p v-if="cheque.status !== 'cleared' && cheque.status !== 'void'" class="mt-2 text-xs text-neutral-400">Void hanya membatalkan pencatatan warkat; tidak membalik jurnal pembayaran apa pun.</p>
        </div>

        <!-- Dialog penyerahan: pilih supplier + invoice hutang yang dilunasi (untuk warkat tanpa referensi) -->
        <Teleport to="body">
            <div v-if="showHandover" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showHandover = false">
                <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Send class="h-5 w-5 text-primary-600" /> Serahkan BG/Cek &amp; Lunasi Hutang</h2>
                        <button type="button" @click="showHandover = false" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X class="h-5 w-5" /></button>
                    </div>
                    <div class="space-y-4 overflow-y-auto px-6 py-4">
                        <p class="text-xs text-neutral-500">Pilih supplier lalu centang invoice/tagihan yang dilunasi warkat ini. Hutang dilunasi saat warkat <b>diserahkan</b> — tersinkron dengan Invoice Hutang, Kartu Hutang &amp; seluruh modul Account Payable. Nominal warkat otomatis disesuaikan = total yang dilunasi. Kosongkan untuk menyerahkan tanpa pelunasan.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Diserahkan *</label><input v-model="handoverForm.handover_date" type="date" :class="inputCls" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier (Hutang)</label><SearchSelect v-model="handoverForm.supplier_id" :options="suppliers ?? []" placeholder="— pilih supplier (opsional) —" /></div>
                        </div>

                        <div v-if="handoverForm.supplier_id && supplierInvoices.length === 0" class="rounded-lg border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-400 dark:border-neutral-700">Tidak ada invoice hutang terbuka untuk supplier ini.</div>

                        <div v-else-if="handoverForm.supplier_id" class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                            <table class="w-full text-sm">
                                <thead class="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-800/60">
                                    <tr>
                                        <th class="w-10 px-3 py-2"></th>
                                        <th class="px-3 py-2">No. Invoice</th>
                                        <th class="px-3 py-2">Keterangan</th>
                                        <th class="px-3 py-2 text-right">Sisa Hutang</th>
                                        <th class="px-3 py-2 text-right">Dilunasi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    <tr v-for="inv in supplierInvoices" :key="inv.id" :class="picks[inv.id]?.checked ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
                                        <td class="px-3 py-2"><input type="checkbox" :checked="picks[inv.id]?.checked" @change="toggleInv(inv)" class="h-4 w-4 rounded border-neutral-300" /></td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ inv.invoice_number }}</td>
                                        <td class="px-3 py-2 text-neutral-500">{{ inv.description ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(inv.outstanding) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <input v-if="picks[inv.id]?.checked" v-model.number="picks[inv.id].amount" type="number" step="1" min="1" :max="inv.outstanding"
                                                   :class="[inputCls,'h-8 w-36 text-right font-mono', pickInvalid(inv) ? 'border-danger-400 ring-1 ring-danger-300' : '']" />
                                            <span v-else class="text-neutral-300">—</span>
                                            <p v-if="pickInvalid(inv)" class="mt-1 text-[11px] text-danger-500">Nominal harus &gt; 0 &amp; ≤ sisa hutang.</p>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="selectedLines.length" class="border-t border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800/60">
                                    <tr>
                                        <td colspan="4" class="px-3 py-2 text-right">Total Dilunasi (= Nominal Warkat)</td>
                                        <td class="px-3 py-2 text-right font-mono text-primary-700 dark:text-primary-300">{{ fmtRp(settleTotal) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p v-if="handoverForm.errors.lines" class="text-xs text-danger-500">{{ handoverForm.errors.lines }}</p>
                        <p v-if="handoverForm.errors.supplier_id" class="text-xs text-danger-500">{{ handoverForm.errors.supplier_id }}</p>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <button type="button" @click="showHandover = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <Button :disabled="handoverForm.processing || hasInvalidPick" @click="doHandover"><template #icon-left><Send class="h-4 w-4" /></template>{{ selectedLines.length ? 'Serahkan & Lunasi' : 'Serahkan (tanpa pelunasan)' }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Dialog struktur jurnal penyerahan untuk warkat PIHAK KETIGA (tidak berhubungan hutang) -->
        <Teleport to="body">
            <div v-if="showJournal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showJournal = false">
                <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><BookText class="h-5 w-5 text-primary-600" /> Struktur Jurnal Penyerahan</h2>
                        <button type="button" @click="showJournal = false" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X class="h-5 w-5" /></button>
                    </div>
                    <div class="space-y-4 px-6 py-4">
                        <p class="text-xs text-neutral-500">Warkat ke <b>pihak ketiga</b> (tidak berhubungan hutang). Saat diserahkan, jurnal otomatis: <b>Debit</b> akun beban (default Beban Pokok Penjualan, bisa diganti) &amp; <b>Kredit</b> Utang BG/Cek Beredar.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Diserahkan *</label><input v-model="handoverForm.handover_date" type="date" :class="inputCls" /></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Debit (Beban) *</label><SearchSelect v-model="handoverForm.debit_account_id" :options="debitAccounts ?? []" placeholder="— pilih akun debit —" /><p v-if="handoverForm.errors.debit_account_id" class="mt-1 text-xs text-danger-500">{{ handoverForm.errors.debit_account_id }}</p></div>
                        </div>

                        <!-- Pratinjau jurnal -->
                        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                            <table class="w-full text-sm">
                                <thead class="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-800/60">
                                    <tr><th class="px-3 py-2">Akun</th><th class="px-3 py-2 text-right">Debit</th><th class="px-3 py-2 text-right">Kredit</th></tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                    <tr>
                                        <td class="px-3 py-2">{{ debitAccountName }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(cheque.amount) }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-neutral-300">—</td>
                                    </tr>
                                    <tr>
                                        <td class="px-3 py-2"><span v-if="liabilityAccount" class="font-mono text-xs text-neutral-400">{{ liabilityAccount.code }}</span> {{ liabilityAccount?.name ?? 'Utang BG/Cek Beredar' }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-neutral-300">—</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(cheque.amount) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800/60">
                                    <tr><td class="px-3 py-2 text-right">Total</td><td class="px-3 py-2 text-right font-mono">{{ fmtRp(cheque.amount) }}</td><td class="px-3 py-2 text-right font-mono">{{ fmtRp(cheque.amount) }}</td></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-neutral-200 px-6 py-4 dark:border-neutral-800">
                        <button type="button" @click="showJournal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        <Button :disabled="handoverForm.processing || !handoverForm.debit_account_id" @click="doHandover"><template #icon-left><Send class="h-4 w-4" /></template>Serahkan &amp; Posting Jurnal</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
