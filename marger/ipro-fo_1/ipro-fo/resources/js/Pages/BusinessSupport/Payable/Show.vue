<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, HandCoins, Wallet, BookText, FolderCheck, CheckCircle2, Printer, Save, Eraser, Pencil, Ban, Lock, Upload, FileText, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import DropBerkas, { type FileBerkas } from '@/Components/UI/DropBerkas.vue';

const props = defineProps<{
    invoice: any;
    /** PO asal invoice (bila source_type='po') — dasar tombol "Cetak Purchase Order". */
    purchaseOrder?: { id: number; po_number: string } | null;
    outstanding: number;
    cashAccounts: Array<any>;
    defaultCash: number | null;
    perubahanBudget?: Array<{ jenis: string; doc_no: string; nilai: number; status: string; url: string }>;
    writeoffs?: Array<{ memorial_number: string; memorial_date: string; amount: number; reason: string; journal_id: number | null; journal_number: string | null }>;
    writeoffTotal?: number;
    journal?: { id: number; number: string | null; date: string | null; lines: Array<{ account_code: string | null; account_name: string | null; dimensi: string | null; description: string | null; debit: number; credit: number }>; total_debit: number; total_credit: number } | null;
    /** Edit / Batal — diputuskan server (izin Grup Akses BO + keadaan "sudah diproses FAT"). null = invoice sudah BATAL. */
    aksi?: { dariRegistrasi: boolean; dariPostingFo?: boolean; diprosesFat: string | null; edit: boolean; editRute?: string; batal: boolean; penahanBatal: string | null } | null;
    infoBatal?: { at: string | null; by: string | null; reason: string | null } | null;
    /** File Kelengkapan Berkas: berkas invoice + tambahan dari rincian ini (hanya tambahan yang bisa dihapus). */
    kelengkapanFiles?: Array<{ id: number; name: string; jenis: string | null; size: number; url: string; tambahan: boolean; oleh: string | null; at: string | null }>;
    jenisBerkas?: Array<{ id: number; code?: string; name: string; is_mandatory: boolean }>;
    bolehKelengkapan?: boolean;
    /** Boleh membuka cetak Memo Internal (+ lampiran) — sama dengan pemeriksaan rute bs.ap.memo. */
    bolehMemo?: boolean;
}>();

/* ── BATAL ── alasan wajib; tercatat di invoice bersama siapa & kapan. */
const showBatal = ref(false);
const formBatal = useForm<any>({ alasan: '' });
function kirimBatal() {
    formBatal.post(route('bs.ap.batal', props.invoice.id), { preserveScroll: true, onSuccess: () => { showBatal.value = false; } });
}
const batal = computed(() => props.invoice.status === 'batal');

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
// File kelengkapan — seperti unggahan berkas di pengajuan: masuk ke berkas invoice (atau lampiran invoice bila tanpa berkas).
const MAKS_FILE = 20;
const fileBaru = ref<FileBerkas[]>([]);
const mengunggah = ref(false);
const idLainnya = computed(() => (props.jenisBerkas ?? []).find((j) => j.code === 'LAINNYA')?.id ?? null);
const bisaUnggah = computed(() => fileBaru.value.length > 0 && !mengunggah.value
    && fileBaru.value.every((u) => u.berkas_id && (u.berkas_id !== idLainnya.value || u.keterangan.trim())));
function unggahKelengkapan() {
    mengunggah.value = true;
    router.post(route('bs.ap.kelengkapan-file.store', props.invoice.id), {
        files: fileBaru.value.map((u) => ({ file: u.file, berkas_id: u.berkas_id, keterangan: u.keterangan })),
    }, { forceFormData: true, preserveScroll: true, onSuccess: () => { fileBaru.value = []; }, onFinish: () => { mengunggah.value = false; } });
}
function hapusKelengkapan(f: { id: number; name: string }) {
    if (!confirm(`Hapus file "${f.name}"?`)) return;
    router.delete(route('bs.ap.kelengkapan-file.destroy', [props.invoice.id, f.id]), { preserveScroll: true });
}
const errorsKelengkapan = computed(() => Object.entries(usePage().props.errors ?? {}).filter(([k]) => k.startsWith('files')).map(([, v]) => v));
// true tepat setelah invoice baru disimpan (flash 'created') → tampilkan banner + tombol cetak.
const justCreated = computed(() => Boolean(usePage().props.flash?.created));
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><HandCoins class="h-6 w-6 text-danger-600" /> {{ invoice.invoice_number }}</h1>
            <div class="flex items-center gap-2">
                <!-- Tautan ke PO asal: rincian invoice ini memang berasal dari PO tersebut. -->
                <a v-if="purchaseOrder" :href="route('bs.po.print', { ids: purchaseOrder.id })" target="_blank" rel="noopener"
                    :title="`Cetak Purchase Order ${purchaseOrder.po_number} (bisa diedit sebelum dicetak)`"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-primary-400 bg-primary-50 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-700/50 dark:bg-primary-900/30 dark:text-primary-300"><Printer class="h-4 w-4" /> Cetak Purchase Order</a>
                <a v-if="! batal" :href="route('bs.ap.memo', invoice.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg bg-danger-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-danger-700"><Printer class="h-4 w-4" /> Cetak Memo Internal</a>
                <Link v-if="aksi?.edit" :href="route(aksi?.editRute ?? 'bs.ap.edit', invoice.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Pencil class="h-4 w-4" /> Edit</Link>
                <button v-if="aksi?.batal" type="button" @click="showBatal = true" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 bg-white px-3 py-1.5 text-sm font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700/60 dark:bg-neutral-800"><Ban class="h-4 w-4" /> Batal</button>
                <Badge v-if="batal" variant="danger">BATAL</Badge>
                <Badge v-else :variant="invoice.status === 'paid' ? 'success' : 'warning'">{{ invoice.status === 'paid' ? 'Lunas' : 'Open' }}</Badge>
            </div>
        </div>

        <!-- Sudah BATAL: siapa, kapan, alasannya. -->
        <div v-if="batal" class="card border border-danger-300 bg-danger-50 p-4 text-sm dark:border-danger-800 dark:bg-danger-900/20">
            <div class="flex items-center gap-2 font-semibold text-danger-700 dark:text-danger-300"><Ban class="h-4 w-4" /> Invoice ini sudah DIBATALKAN</div>
            <div class="mt-1 text-neutral-600 dark:text-neutral-300">
                {{ infoBatal?.at ?? '—' }} oleh {{ infoBatal?.by ?? '—' }} · Alasan: {{ infoBatal?.reason ?? '—' }}
            </div>
            <div class="mt-1 text-xs text-neutral-500">Jurnal, termin pembayaran, dan Purchase Order otomatisnya ikut dibatalkan. Invoice ini tidak lagi dihitung di Aging, Kartu Hutang, maupun Kuitansi.</div>
        </div>
        <!-- Sudah diproses FAT: Edit terkunci — katakan sebabnya, jangan sekadar menyembunyikan tombol. -->
        <div v-else-if="(aksi?.dariRegistrasi || aksi?.dariPostingFo) && aksi?.diprosesFat" class="card flex items-start gap-2 border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-900/20">
            <Lock class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <div>
                <b>Sudah diproses FAT</b> ({{ aksi.diprosesFat }}) — tidak bisa diedit. Batalkan dulu bila perlu diubah.
                <span v-if="aksi.penahanBatal" class="block text-xs text-neutral-500">Untuk membatalkan: {{ aksi.penahanBatal }}</span>
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

        <!-- Alokasi / Koreksi Budget untuk invoice ini — tiap baris membuka Lampiran Perubahan Budget (cetak). -->
        <div v-if="perubahanBudget?.length" class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Perubahan Budget (Alokasi / Koreksi)</h2>
                <!-- Satu dokumen: Memo Internal di halaman 1, sesudahnya Lampiran Perubahan Budget tiap alokasi / koreksi. -->
                <a v-if="bolehMemo" :href="route('bs.ap.memo', invoice.id)" target="_blank" rel="noopener" class="text-sm text-primary-600 hover:underline">Memo Internal + Lampiran ↗</a>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="p in perubahanBudget" :key="p.url">
                        <td class="px-4 py-2">{{ p.jenis }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ p.doc_no }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Math.round(p.nilai).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-2">{{ p.status }}</td>
                        <td class="px-4 py-2 text-right"><a v-if="!bolehMemo" :href="p.url" target="_blank" rel="noopener" class="text-primary-600 hover:underline">Lampiran ↗</a></td>
                    </tr>
                </tbody>
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

            <!-- File kelengkapan: file berkas invoice + file yang ditambahkan di sini. -->
            <div class="mt-4 border-t border-neutral-100 pt-3 dark:border-neutral-800">
                <div class="text-xs font-medium text-neutral-500">File berkas ({{ kelengkapanFiles?.length ?? 0 }})</div>
                <ul class="mt-1 divide-y divide-neutral-100 text-sm dark:divide-neutral-800">
                    <li v-for="f in kelengkapanFiles ?? []" :key="f.id" class="flex items-center justify-between gap-2 py-1.5">
                        <span class="flex min-w-0 items-center gap-2">
                            <FileText class="h-4 w-4 shrink-0 text-neutral-400" />
                            <span class="shrink-0 text-xs font-medium text-neutral-500">{{ f.jenis ?? 'Jenis lain' }}</span>
                            <span class="truncate">{{ f.name }}</span>
                            <span v-if="f.tambahan" class="shrink-0 text-[11px] text-neutral-400">· {{ f.oleh ?? '—' }}, {{ f.at }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            <a :href="f.url" target="_blank" rel="noopener" class="text-xs text-primary-600 hover:underline">Buka</a>
                            <button v-if="f.tambahan && bolehKelengkapan" type="button" class="text-neutral-400 hover:text-danger-600" title="Hapus file" @click="hapusKelengkapan(f)"><Trash2 class="h-3.5 w-3.5" /></button>
                        </span>
                    </li>
                    <li v-if="!(kelengkapanFiles?.length)" class="py-1.5 text-xs text-neutral-400">Belum ada file.</li>
                </ul>
                <div v-if="bolehKelengkapan && (kelengkapanFiles?.length ?? 0) < MAKS_FILE" class="mt-3">
                    <DropBerkas v-model="fileBaru" :jenis="jenisBerkas ?? []" :maks="MAKS_FILE - (kelengkapanFiles?.length ?? 0)" />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="button" :disabled="!bisaUnggah" @click="unggahKelengkapan" class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-danger-600 px-3 text-xs font-semibold text-white hover:bg-danger-700 disabled:opacity-50"><Upload class="h-3.5 w-3.5" /> {{ mengunggah ? 'Mengunggah…' : 'Upload File' }}</button>
                        <span v-for="(e, i) in errorsKelengkapan" :key="i" class="text-xs text-danger-500">{{ e }}</span>
                    </div>
                </div>
            </div>
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
    <Teleport to="body">
        <div v-if="showBatal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showBatal = false">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                <h2 class="flex items-center gap-2 text-lg font-bold text-danger-600"><Ban class="h-5 w-5" /> Batalkan {{ invoice.invoice_number }}?</h2>
                <p class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                    Jurnal, termin pembayaran, dan Purchase Order otomatisnya ikut dibatalkan. Invoice tetap tercatat dengan status
                    <b>BATAL</b> dan tidak bisa dikembalikan — bila perlu, buat invoice baru.
                </p>
                <label class="mb-1 mt-4 block text-xs font-medium text-neutral-500">Alasan pembatalan *</label>
                <textarea v-model="formBatal.alasan" rows="3" maxlength="255"
                    :class="['w-full rounded-lg border bg-white px-3 py-2 text-sm dark:bg-neutral-900', formBatal.errors.alasan || ! formBatal.alasan.trim() ? 'border-danger-500 ring-1 ring-danger-400/40' : 'border-neutral-300 dark:border-neutral-700']"></textarea>
                <p v-if="formBatal.errors.alasan || ! formBatal.alasan.trim()" class="mt-1 text-[11px] font-medium text-danger-500">{{ formBatal.errors.alasan ?? 'Wajib diisi' }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="showBatal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Tidak jadi</button>
                    <button type="button" :disabled="! formBatal.alasan.trim() || formBatal.processing" @click="kirimBatal"
                        class="rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-700 disabled:opacity-50">Ya, Batalkan</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
