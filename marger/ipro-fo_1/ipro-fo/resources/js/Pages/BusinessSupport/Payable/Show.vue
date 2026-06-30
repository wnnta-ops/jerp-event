<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins, Wallet, BookText, FolderCheck, CheckCircle2, Printer, Save, Eraser } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    invoice: any;
    outstanding: number;
    cashAccounts: Array<any>;
    defaultCash: number | null;
    writeoffs?: Array<{ memorial_number: string; memorial_date: string; amount: number; reason: string; journal_id: number | null; journal_number: string | null }>;
    writeoffTotal?: number;
    journal?: { id: number; number: string | null; date: string | null; lines: Array<{ account_code: string | null; account_name: string | null; dimensi: string | null; description: string | null; debit: number; credit: number }>; total_debit: number; total_credit: number } | null;
}>();

// Penghapusan hutang menambah paid_amount; pisahkan dari pembayaran tunai nyata.
const writeoffTotal = computed(() => Number(props.writeoffTotal ?? 0));
const realPaid = computed(() => Math.max(0, Number(props.invoice.paid_amount ?? 0) - writeoffTotal.value));
const showTerbayar = computed(() => writeoffTotal.value < 0.01 || realPaid.value > 0.009);
// Jurnal pendaftaran balance bila total debit ≈ total kredit (selisih < Rp1).
const journalBalanced = computed(() => !!props.journal && Math.abs((props.journal.total_debit || 0) - (props.journal.total_credit || 0)) < 1);

const showPay = ref(false);
const today = new Date().toISOString().slice(0, 10);
const pay = useForm<any>({
    payment_date: today, cash_account_id: props.defaultCash ?? '', amount: props.outstanding, reference: '',
});
function submitPay() { pay.post(route('bs.ap.pay', props.invoice.id), { onSuccess: () => { showPay.value = false; } }); }

// #140: objek PPN = ppn_base (fallback DPP utk data lama ber-PPN), non-objek = DPP − objek.
const ppnBase = computed(() => {
    const dpp = Number(props.invoice.dpp) || 0;
    const base = Number(props.invoice.ppn_base) || 0;
    return Math.min(base > 0 ? base : dpp, dpp);
});
const ppnNonObject = computed(() => Math.max(0, (Number(props.invoice.dpp) || 0) - ppnBase.value));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const trxLabel: Record<string, string> = { normal: 'Normal', denda: 'Realisasi Denda', saldo_awal: 'Saldo Awal' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// Kelengkapan berkas = free-text (catatan berkas yang masih kurang). Tampil di kolom daftar Invoice Hutang.
const berkasNotes = ref<string>(props.invoice.berkas_notes ?? '');
const berkasForm = useForm<{ notes: string }>({ notes: '' });
const berkasDirty = computed(() => (berkasNotes.value ?? '') !== (props.invoice.berkas_notes ?? ''));
// Re-sync textarea & dirty-flag setelah Inertia reload (mis. selesai Simpan/Clear).
watch(() => props.invoice.berkas_notes, (v) => { berkasNotes.value = v ?? ''; });
function saveBerkas() { berkasForm.notes = berkasNotes.value ?? ''; berkasForm.post(route('bs.ap.berkas-notes', props.invoice.id), { preserveScroll: true }); }
function clearBerkas() { berkasNotes.value = ''; berkasForm.notes = ''; berkasForm.post(route('bs.ap.berkas-notes', props.invoice.id), { preserveScroll: true }); }
// true tepat setelah invoice baru disimpan (flash 'created') → tampilkan banner + tombol cetak.
const justCreated = computed(() => Boolean(usePage().props.flash?.created));
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> {{ invoice.invoice_number }}</h1>
            <div class="flex items-center gap-2">
                <a :href="route('bs.ap.memo', invoice.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-danger-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-danger-700"><Printer class="h-4 w-4" /> Cetak Memo Internal</a>
                <Badge :variant="invoice.status === 'paid' ? 'success' : 'warning'">{{ invoice.status === 'paid' ? 'Lunas' : 'Open' }}</Badge>
            </div>
        </div>

        <!-- Tepat setelah "Simpan & Buat Jurnal": konfirmasi tersimpan + tombol cetak -->
        <div v-if="justCreated" class="card flex flex-wrap items-center justify-between gap-3 border border-success-300 bg-success-50 p-4 dark:border-success-700 dark:bg-success-900/20">
            <div class="flex items-center gap-2 text-sm">
                <CheckCircle2 class="h-5 w-5 shrink-0 text-success-600" />
                <span>Invoice <b class="font-mono">{{ invoice.invoice_number }}</b> tersimpan &amp; jurnal otomatis terbentuk. Silakan cetak dokumennya:</span>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a :href="route('bs.ap.memo', invoice.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-danger-600 px-3 py-2 text-sm font-semibold text-white hover:bg-danger-700"><Printer class="h-4 w-4" /> Cetak Memo Internal</a>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Supplier</div><div class="font-medium">{{ invoice.supplier?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Kategori</div><div class="font-medium">{{ invoice.category?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Tipe Transaksi</div><div class="font-medium">{{ trxLabel[invoice.trx_type] ?? invoice.trx_type }}</div></div>
            <div><div class="text-neutral-500">Unit</div><div class="font-medium font-mono">{{ invoice.unit?.unit_code ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Customer</div><div class="font-medium">{{ invoice.customer?.full_name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">No. Invoice Supplier</div><div class="font-medium">{{ invoice.supplier_invoice_no ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Tgl Invoice / Terima</div><div class="font-medium">{{ fmtDate(invoice.invoice_date) }}<span v-if="invoice.receive_date" class="text-neutral-400"> · {{ fmtDate(invoice.receive_date) }}</span></div></div>
            <div><div class="text-neutral-500">Jatuh Tempo</div><div class="font-medium">{{ fmtDate(invoice.due_date) }}<span v-if="invoice.term_days" class="text-neutral-400"> ({{ invoice.term_days }} hari)</span></div></div>
            <div class="md:col-span-3"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ invoice.description }}</div></div>
        </div>

        <div class="card p-5">
            <div class="grid gap-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between"><span class="text-neutral-500">DPP</span><span class="font-mono">{{ fmtRp(invoice.dpp) }}</span></div>
                <!-- #140: rincian objek vs non-objek PPN -->
                <template v-if="invoice.ppn_mode === 'with'">
                    <div class="flex justify-between text-xs text-neutral-400 sm:col-span-2"><span class="pl-3">Objek PPN</span><span class="font-mono">{{ fmtRp(ppnBase) }}</span></div>
                    <div v-if="ppnNonObject > 0" class="flex justify-between text-xs text-neutral-400 sm:col-span-2"><span class="pl-3">Non-objek PPN</span><span class="font-mono">{{ fmtRp(ppnNonObject) }}</span></div>
                </template>
                <div class="flex justify-between"><span class="text-neutral-500">PPN ({{ invoice.ppn_pct }}%)</span><span class="font-mono">{{ fmtRp(invoice.ppn_amount) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">Total (DPP+PPN)</span><span class="font-mono">{{ fmtRp(invoice.total) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">PPh {{ invoice.pph_mode === 'borne' ? 'ditanggung' : 'dipotong' }} ({{ invoice.pph_pct }}%)</span><span class="font-mono text-danger-600">{{ invoice.pph_amount > 0 ? (invoice.pph_mode === 'borne' ? fmtRp(invoice.pph_amount) : '−' + fmtRp(invoice.pph_amount).slice(3)) : fmtRp(0) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold dark:border-neutral-700 sm:col-span-2"><span>Net Dibayar ke Supplier</span><span class="font-mono">{{ fmtRp(invoice.payable_amount) }}</span></div>
                <div v-if="showTerbayar" class="flex justify-between border-t border-neutral-200 pt-2 dark:border-neutral-700 sm:col-span-2"><span class="text-neutral-500">Terbayar</span><span class="font-mono text-success-600">{{ fmtRp(realPaid) }}</span></div>
                <div v-if="writeoffTotal > 0.009" class="flex justify-between dark:border-neutral-700 sm:col-span-2" :class="showTerbayar ? '' : 'border-t border-neutral-200 pt-2'"><span class="text-neutral-500">Memo Penghapusan Hutang</span><span class="font-mono text-amber-600">{{ fmtRp(writeoffTotal) }}</span></div>
                <div class="flex justify-between sm:col-span-2 rounded-lg bg-neutral-50 px-3 py-2 font-bold dark:bg-neutral-800"><span>Sisa Hutang</span><span class="font-mono" :class="outstanding > 0 ? 'text-danger-600' : 'text-success-600'">{{ fmtRp(outstanding) }}</span></div>
            </div>
            <div v-if="invoice.journal_id" class="mt-3 text-xs text-neutral-400">Jurnal pendaftaran: #{{ invoice.journal_id }} (Dr Akun Debit + PPN / Cr Hutang + PPh)</div>
        </div>

        <!-- Struktur Jurnal pendaftaran invoice (Dr Akun + PPN Masukan / Cr Hutang + PPh) — penting untuk audit. -->
        <div v-if="journal" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="flex items-center gap-2 font-semibold"><BookText class="h-5 w-5 text-primary-600" /> Struktur Jurnal</h2>
                <div class="flex items-center gap-2">
                    <Link v-if="journal.id" :href="route('bs.gl.journal.show', journal.id)" class="inline-flex items-center gap-1 font-mono text-xs text-primary-600 hover:underline dark:text-primary-400" :title="'Jurnal GL: ' + (journal.number ?? ('#' + journal.id))"><BookText class="h-3.5 w-3.5" /> {{ invoice.invoice_number }}</Link>
                    <Badge :variant="journalBalanced ? 'success' : 'warning'" size="sm">{{ journalBalanced ? 'Balance' : 'Tidak balance' }}</Badge>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">Account</th>
                    <th class="px-4 py-2.5">Dimensi</th>
                    <th class="px-4 py-2.5">Keterangan</th>
                    <th class="px-4 py-2.5 text-right">Debit</th>
                    <th class="px-4 py-2.5 text-right">Kredit</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(l, i) in journal.lines" :key="i">
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-500">{{ l.account_code }}</span> — {{ l.account_name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ l.dimensi ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-600 dark:text-neutral-300">{{ l.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ l.debit > 0 ? fmtRp(l.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ l.credit > 0 ? fmtRp(l.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <td class="px-4 py-2.5" colspan="3">Grand Total</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(journal.total_debit) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(journal.total_credit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Memo Penghapusan Hutang — alasan + rincian sisa hutang yang dihapus (bukan dibayar). -->
        <div v-if="writeoffs?.length" class="card overflow-hidden border border-amber-200 dark:border-amber-900/40">
            <div class="flex items-center justify-between border-b border-neutral-200 bg-amber-50 px-4 py-3 dark:border-neutral-800 dark:bg-amber-900/20">
                <h2 class="flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300"><Eraser class="h-5 w-5" /> Memo Penghapusan Hutang</h2>
                <Link :href="route('bs.ap.penghapusan.report')" class="text-xs text-neutral-500 hover:underline">Lihat semua memo →</Link>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. Memo</th><th class="px-4 py-2.5">Tanggal</th><th class="px-4 py-2.5">Alasan Penghapusan</th><th class="px-4 py-2.5 text-right">Nilai Dihapus</th><th class="px-4 py-2.5">Jurnal GL</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="w in writeoffs" :key="w.memorial_number">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ w.memorial_number }}</td>
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtDate(w.memorial_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-600 dark:text-neutral-300">{{ w.reason }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(w.amount) }}</td>
                        <td class="px-4 py-2.5"><Link v-if="w.journal_id" :href="route('bs.gl.journal.show', w.journal_id)" class="inline-flex items-center gap-1 font-mono text-xs text-primary-600 hover:underline dark:text-primary-400"><BookText class="h-3.5 w-3.5" /> {{ w.journal_number ?? ('#' + w.journal_id) }}</Link><span v-else class="text-neutral-400">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="invoice.lines?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Detil Tagihan</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Qty</th><th class="px-4 py-2.5 text-right">Harga</th><th class="px-4 py-2.5 text-right">Jumlah</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in invoice.lines" :key="l.id"><td class="px-4 py-2.5">{{ l.description }}</td><td class="px-4 py-2.5 text-right font-mono">{{ Number(l.qty) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.unit_price) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="invoice.schedules?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Struktur Pembayaran</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Termin</th><th class="px-4 py-2.5">Jatuh Tempo</th><th class="px-4 py-2.5 text-right">Jumlah</th><th class="px-4 py-2.5 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="s in invoice.schedules" :key="s.id"><td class="px-4 py-2.5 font-mono">#{{ s.installment_no }}</td><td class="px-4 py-2.5">{{ fmtDate(s.due_date) }}</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(s.amount) }}</td><td class="px-4 py-2.5 text-center"><Badge :variant="s.status==='paid' ? 'success' : 'warning'" size="sm">{{ s.status==='paid' ? 'Lunas' : 'Belum' }}</Badge></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card p-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 font-semibold"><FolderCheck class="h-5 w-5 text-danger-600" /> Kelengkapan Berkas</h2>
                <div class="flex items-center gap-2">
                    <button v-if="berkasNotes || invoice.berkas_notes" type="button" @click="clearBerkas" :disabled="berkasForm.processing" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-xs font-medium text-neutral-600 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"><Eraser class="h-3.5 w-3.5" /> Clear</button>
                    <button type="button" @click="saveBerkas" :disabled="berkasForm.processing || !berkasDirty" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-danger-600 px-3 text-xs font-semibold text-white hover:bg-danger-700 disabled:opacity-50"><Save class="h-3.5 w-3.5" /> Simpan</button>
                </div>
            </div>
            <textarea v-model="berkasNotes" rows="3" placeholder="Catat kelengkapan / berkas yang masih kurang (mis. Faktur Pajak belum ada, PO/SPK menyusul)…" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
            <p class="mt-1.5 text-[11px] text-neutral-400">Catatan ini tampil pada kolom “Kelengkapan Berkas” di daftar Invoice Hutang.</p>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Pembayaran</div>
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
            <div v-else class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada pembayaran.</div>
        </div>
    </div>
</template>
