<script setup lang="ts">
import { computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ReceiptText, Ban, BookText, FileText, Banknote, Printer, PenLine } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    kuitansi: any;
    alat: Record<string, string>;
    /** Kuitansi Memo Urgent: TTD Kasir / Direktur / MW / BA (digital, otomatis setelah disetujui). */
    ttd: Array<{ title: string; name: string | null; signed: boolean; at: string | null }> | null;
    /** Invoice hutang yang menyusul memo ini (null = belum dibuat). */
    invoiceMemo: { id: number; invoice_number: string } | null;
}>();
const adaUm = computed(() => (props.kuitansi.lines ?? []).some((l: any) => Number(l.um_amount) > 0));
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
// Nomer Invoice = nomor semua invoice yang dibayar (umumnya 1; bila >1 digabung).
const invoiceNumbers = computed(() => {
    const nums = (props.kuitansi.lines ?? []).map((l: any) => l.invoice?.invoice_number).filter(Boolean);
    return nums.length ? nums.join(', ') : '—';
});
// Struktur jurnal pembayaran (JAP): total Dr/Cr + status balance.
const journalDebit = computed(() => (props.kuitansi.journal?.lines ?? []).reduce((s: number, l: any) => s + (Number(l.debit) || 0), 0));
const journalKredit = computed(() => (props.kuitansi.journal?.lines ?? []).reduce((s: number, l: any) => s + (Number(l.credit) || 0), 0));
const journalBalanced = computed(() => Math.abs(journalDebit.value - journalKredit.value) < 1 && journalDebit.value > 0);
type BadgeVariant = 'success' | 'neutral' | 'primary' | 'warning' | 'danger' | 'info';
const statusMeta: Record<string, { v: BadgeVariant; l: string }> = {
    pending:  { v: 'warning', l: 'Menunggu Approval' },
    active:   { v: 'success', l: 'Aktif' },
    rejected: { v: 'danger',  l: 'Ditolak' },
    void:     { v: 'danger',  l: 'Void' },
};
function stMeta(s: string): { v: BadgeVariant; l: string } { return statusMeta[s] ?? { v: 'neutral', l: s }; }
function voidIt() { if (confirm('Void kuitansi ini? Pembayaran akan dibatalkan dan jurnal pembayaran (struktur jurnal) DIHAPUS dari General Ledger.')) router.post(route('bs.ap.kuitansi.void', props.kuitansi.id), {}, { preserveScroll: true }); }
</script>
<template>
    <div class="max-w-5xl space-y-4">
        <Link :href="route('bs.ap.kuitansi.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kuitansi Hutang</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-xl font-bold"><ReceiptText class="h-6 w-6 text-danger-600" /> {{ kuitansi.kuitansi_number }}</h1>
            <div class="flex items-center gap-2">
                <a :href="route('bs.ap.kuitansi.print', kuitansi.id)" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Printer class="h-4 w-4" /> Cetak</a>
                <Badge :variant="stMeta(kuitansi.status).v">{{ stMeta(kuitansi.status).l }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Supplier</div><div class="font-medium">{{ kuitansi.supplier?.name }}</div></div>
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(kuitansi.kuitansi_date) }}</div></div>
            <div><div class="text-neutral-500">Alat Transaksi</div><div class="font-medium">{{ alat[kuitansi.alat_transaksi] }}</div></div>
            <div><div class="text-neutral-500">Dari Kas/Bank</div><div class="font-medium">{{ kuitansi.cash_account?.name }}</div></div>
            <div><div class="text-neutral-500">Kasir</div><div class="font-medium">{{ kuitansi.kasir ?? '—' }}</div></div>
            <div v-if="kuitansi.bg_number"><div class="text-neutral-500">No. BG / Cek</div><div class="font-medium font-mono">{{ kuitansi.bg_number }}</div></div>
            <div><div class="text-neutral-500">No. Manual</div><div class="font-medium">{{ kuitansi.manual_no ?? '—' }}</div></div>
            <div class="md:col-span-3 flex items-center justify-between rounded-lg bg-neutral-50 px-4 py-3 dark:bg-neutral-800"><span class="font-semibold">Total Dibayar</span><span class="font-mono text-xl font-bold">{{ fmtRp(kuitansi.total) }}</span></div>
        </div>

        <!-- Kuitansi Memo Urgent (dibayar sebelum invoice) -->
        <div v-if="kuitansi.memo" class="card space-y-3 p-5 text-sm">
            <div class="flex items-center gap-2 font-semibold"><FileText class="h-4 w-4 text-primary-600" /> Pembayaran Memo Urgent</div>
            <div class="grid gap-3 md:grid-cols-4">
                <div><div class="text-neutral-500">No. Memo</div><div class="font-mono font-medium">{{ kuitansi.memo.memo_number }}</div></div>
                <div class="md:col-span-2"><div class="text-neutral-500">Perihal</div><div class="font-medium">{{ kuitansi.memo.subject }}</div></div>
                <div><div class="text-neutral-500">Nominal Memo</div><div class="font-mono font-medium">{{ fmtRp(kuitansi.memo.amount) }}</div></div>
                <div class="md:col-span-2"><div class="text-neutral-500">Akun Uang Muka</div><div class="font-medium">{{ kuitansi.advance_account?.code }} {{ kuitansi.advance_account?.name }}</div></div>
                <div class="md:col-span-2"><div class="text-neutral-500">Invoice Hutang Penyusul</div>
                    <Link v-if="invoiceMemo" :href="route('bs.ap.show', invoiceMemo.id)" class="font-mono font-medium text-primary-600 hover:underline">{{ invoiceMemo.invoice_number }}</Link>
                    <div v-else class="font-semibold text-red-600">Belum dibuat — wajib menyusul</div>
                </div>
            </div>
            <div v-if="ttd" class="grid grid-cols-3 gap-2 border-t border-neutral-100 pt-3 dark:border-neutral-800">
                <div v-for="t in ttd" :key="t.title" class="rounded-lg border border-neutral-200 p-2 text-center text-xs dark:border-neutral-800">
                    <div class="font-semibold">{{ t.title }}</div>
                    <PenLine class="mx-auto my-1 h-4 w-4" :class="t.signed ? 'text-success-600' : 'text-neutral-300'" />
                    <div class="font-medium">{{ t.name ?? '—' }}</div>
                    <div :class="t.signed ? 'text-neutral-500' : 'text-amber-600'">{{ t.signed ? t.at : 'Menunggu persetujuan' }}</div>
                </div>
            </div>
        </div>

        <!-- Referensi dokumen: Invoice (yang dibayar) · Pengajuan (kuitansi) · Pembayaran (jurnal JAP) -->
        <div class="card p-5">
            <h2 class="mb-3 text-sm font-semibold">Referensi</h2>
            <!-- Lebar kolom proporsional ke panjang nomor (Invoice terpanjang) agar tak ada scroll -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-[2fr_1.4fr_1fr]">
                <div class="rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 dark:border-neutral-800 dark:bg-neutral-800/40">
                    <div class="flex items-center gap-1.5 text-xs font-medium text-neutral-500"><FileText class="h-3.5 w-3.5" /> Nomer Invoice</div>
                    <div class="mt-1 overflow-x-auto">
                        <Link v-for="l in kuitansi.lines" :key="l.id" :href="route('bs.ap.show', l.ap_invoice_id)" class="block whitespace-nowrap font-mono text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ l.invoice?.invoice_number }}</Link>
                        <span v-if="!kuitansi.lines?.length" class="font-mono text-sm text-neutral-400">—</span>
                    </div>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 dark:border-neutral-800 dark:bg-neutral-800/40">
                    <div class="flex items-center gap-1.5 text-xs font-medium text-neutral-500"><ReceiptText class="h-3.5 w-3.5" /> Nomer Pengajuan</div>
                    <div class="mt-1 overflow-x-auto whitespace-nowrap font-mono text-sm font-semibold text-neutral-800 dark:text-neutral-100" title="Dokumen ini">{{ kuitansi.kuitansi_number }}</div>
                </div>
                <div class="rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 dark:border-neutral-800 dark:bg-neutral-800/40">
                    <div class="flex items-center gap-1.5 text-xs font-medium text-neutral-500"><Banknote class="h-3.5 w-3.5" /> Nomer Pembayaran</div>
                    <div class="mt-1 overflow-x-auto">
                        <Link v-if="kuitansi.journal" :href="route('bs.gl.journal.show', kuitansi.journal_id)" class="whitespace-nowrap font-mono text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ kuitansi.journal.journal_number }}</Link>
                        <span v-else class="whitespace-nowrap font-mono text-sm text-neutral-400">— belum dibayar</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!kuitansi.memo" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Invoice yang Dibayar</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">No. Invoice</th><th class="px-4 py-2.5">Keterangan</th><th class="px-4 py-2.5 text-right">Nominal</th><th v-if="adaUm" class="px-4 py-2.5 text-right">Potong UM Memo</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in kuitansi.lines" :key="l.id">
                        <td class="px-4 py-2.5"><Link :href="route('bs.ap.show', l.ap_invoice_id)" class="font-mono text-xs text-primary-600 hover:underline">{{ l.invoice?.invoice_number }}</Link></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ l.invoice?.description }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                        <td v-if="adaUm" class="px-4 py-2.5 text-right font-mono text-red-600">{{ Number(l.um_amount) > 0 ? '−' + fmtRp(l.um_amount) : '—' }}<div v-if="l.invoice?.memo" class="text-[11px] text-neutral-400">Memo {{ l.invoice.memo.memo_number }}</div></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Struktur Jurnal Pembayaran (JAP) — tampilkan Dr/Cr agar lebih komunikatif -->
        <div v-if="kuitansi.journal" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="flex items-center gap-2 font-semibold"><BookText class="h-4 w-4 text-primary-600" /> Struktur Jurnal Pembayaran</div>
                <div class="flex items-center gap-2">
                    <Link :href="route('bs.gl.journal.show', kuitansi.journal_id)" class="font-mono text-xs text-primary-600 hover:underline dark:text-primary-400">{{ kuitansi.journal.journal_number }}</Link>
                    <Badge v-if="journalBalanced" variant="success">Balance</Badge>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Account</th><th class="px-4 py-2.5 text-right">Debit</th><th class="px-4 py-2.5 text-right">Kredit</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(jl, i) in kuitansi.journal.lines" :key="i">
                        <td class="px-4 py-2.5"><span class="font-mono text-xs text-neutral-400">{{ jl.account?.code }}</span> <span class="text-neutral-700 dark:text-neutral-200">{{ jl.account?.name }}</span><div v-if="jl.description" class="text-[11px] text-neutral-400">{{ jl.description }}</div></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(jl.debit) > 0 ? fmtRp(jl.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(jl.credit) > 0 ? fmtRp(jl.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <td class="px-4 py-2.5 text-right text-neutral-500">Total</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(journalDebit) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(journalKredit) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div v-if="kuitansi.status === 'pending'" class="rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:border-warning-700 dark:bg-warning-900/30 dark:text-warning-300">
            ⏳ Kuitansi ini <strong>menunggu persetujuan</strong> di Approval Pengeluaran Kas &amp; Bank. Jurnal JAP &amp; pelunasan invoice baru terbentuk setelah disetujui.
        </div>
        <div v-else-if="kuitansi.status === 'rejected'" class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
            ✗ Kuitansi ini <strong>ditolak</strong> — tidak ada jurnal atau pelunasan invoice yang terbentuk.
        </div>
        <div v-else-if="kuitansi.status === 'void'" class="rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
            ⊘ Kuitansi ini <strong>di-void</strong> — pembayaran dibatalkan, saldo hutang invoice dipulihkan, dan <strong>struktur jurnal pembayaran dihapus</strong> dari General Ledger (bukan jurnal balik).
        </div>

        <div v-if="kuitansi.void_journal" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-neutral-400">
            <Link :href="route('bs.gl.journal.show', kuitansi.void_journal_id)" class="inline-flex items-center gap-1 font-mono text-danger-500 hover:underline"><BookText class="h-3.5 w-3.5" /> Jurnal Void: {{ kuitansi.void_journal.journal_number }}</Link>
        </div>

        <div v-if="kuitansi.status === 'active'">
            <Button variant="secondary" @click="voidIt"><template #icon-left><Ban class="h-4 w-4" /></template>Void Kuitansi</Button>
        </div>
    </div>
</template>
