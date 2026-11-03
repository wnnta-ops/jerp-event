<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { gabungBerkas } from '@/Composables/useGabungPdf';
import { Files } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

type FileRow = { id: number; name: string; jenis: string | null; size: number; mime: string; url: string };
const props = defineProps<{
    berkas: {
        id: number; number: string; status: string; deleted: boolean; upload_date: string | null; document_date: string | null;
        needed_date: string | null; supplier: string | null; supplier_invoice_no: string | null; description: string;
        amount: number | null; invoiced: number; sisa: number | null; dept_head: string | null;
        uploader: string | null; cancel_reason: string | null; close_reason: string | null; files: FileRow[];
    };
    wajibKurang: string[];
    jenisChecklist: Array<{ name: string; is_mandatory: boolean }>;
    invoices: Array<{ id: number; number: string; total: number; status: string; date: string | null; approval: { status: string; url: string } | null }>;
    ttdApprovalUrl: string | null;
    timeline: Array<{ event: string; at: string; by: string; notes: string | null; changes: Array<{ field: string; old: any; new: any }> }>;
    can: { edit: boolean; submit: boolean; cancel: boolean; close: boolean; delete: boolean; invoice: boolean };
    /** User punya draft Registrasi Invoice Hutang yang memakai berkas ini. */
    draftInvoice?: boolean;
}>();

const label: Record<string, string> = {
    draft: 'Draft', menunggu_ttd: 'Menunggu TTD', siap_invoice: 'Siap Invoice', sebagian_diinvoice: 'Sebagian Diinvoice',
    diinvoice: 'Sudah Diinvoice', ditolak: 'Ditolak', dibatalkan: 'Dibatalkan', ditutup: 'Ditutup',
};
const variant: Record<string, string> = {
    draft: 'neutral', menunggu_ttd: 'warning', siap_invoice: 'info', sebagian_diinvoice: 'info',
    diinvoice: 'success', ditolak: 'danger', dibatalkan: 'neutral', ditutup: 'neutral',
};
const approvalText: Record<string, string> = { pending: 'Menunggu approval', in_review: 'Dalam approval', approved: 'Disetujui — boleh dibayar', rejected: 'Ditolak' };
const fieldLabel: Record<string, string> = {
    berkas_number: 'No. berkas', document_date: 'Tgl tagihan', needed_date: 'Jatuh tempo', supplier_id: 'Supplier', supplier_invoice_no: 'No. tagihan',
    amount: 'Total', description: 'Keterangan', dept_head_id: 'Dept Head',
    // Isian lama (sebelum disederhanakan) — tetap terbaca di riwayat.
    jenis: 'Jenis', subtotal: 'Subtotal', discount: 'Diskon', ppn_pct: 'PPN %', tax_inclusive: 'Termasuk PPN',
    cost_center_id: 'Cost center', expense_account_id: 'Akun beban',
};

const ada = (nama: string) => props.berkas.files.some((f) => f.jenis === nama);

// Pratinjau file di halaman (PDF / gambar).
const pratinjau = ref<FileRow | null>(null);

// "Lihat Semua": semua file digabung jadi satu PDF (sampul + daftar isi), urut sesuai checklist.
const semua = ref<{ url: string; nama: string } | null>(null);
const menggabung = ref(false);
const galatGabung = ref('');
async function lihatSemua() {
    if (semua.value) return;
    menggabung.value = true;
    galatGabung.value = '';
    try {
        const b = props.berkas;
        semua.value = await gabungBerkas({ ...b, status_label: label[b.status] ?? b.status }, props.jenisChecklist.map((j) => j.name));
    } catch (e) {
        galatGabung.value = 'Gagal menggabungkan file — coba buka satu per satu.';
    } finally {
        menggabung.value = false;
    }
}
function tutupSemua() {
    if (semua.value) URL.revokeObjectURL(semua.value.url);
    semua.value = null;
}
onBeforeUnmount(tutupSemua);

// Batal / tutup / hapus — dialog di halaman.
const dialog = ref<'cancel' | 'close' | 'delete' | null>(null);
const alasan = ref('');
function konfirmasi() {
    const opsi = { preserveScroll: true, onSuccess: () => { dialog.value = null; alasan.value = ''; } };
    if (dialog.value === 'cancel') router.post(route('bs.ap.berkas.cancel', props.berkas.id), { reason: alasan.value }, opsi);
    else if (dialog.value === 'close') router.post(route('bs.ap.berkas.close', props.berkas.id), { reason: alasan.value }, opsi);
    else router.delete(route('bs.ap.berkas.destroy', props.berkas.id));
}
const ajukan = () => router.post(route('bs.ap.berkas.submit', props.berkas.id), {}, { preserveScroll: true });

// 2026-11-02 → 02/11/26
const tglPendek = (iso: string | null) => (iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(2, 4) : '-');
const rupiah = (v: number | null) => (v == null ? '—' : 'Rp ' + Math.round(v).toLocaleString('id-ID'));
const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
const waktu = (iso: string) => new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const tampil = (v: any) => (v === null || v === '' || v === undefined ? '—' : typeof v === 'boolean' ? (v ? 'Ya' : 'Tidak') : String(v));
</script>

<template>
    <Head :title="berkas.number + ' — Upload Berkas'" />
    <div class="mx-auto max-w-5xl space-y-4">
        <Link :href="route('approval-inbox.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Approval Inbox</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-mono text-sm text-neutral-500">{{ berkas.number }}</p>
                <h1 class="text-2xl font-bold">{{ berkas.description }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <Badge :variant="(variant[berkas.status] as any) ?? 'neutral'" dot>{{ label[berkas.status] ?? berkas.status }}</Badge>
                <Badge v-if="berkas.deleted" variant="danger">Dihapus</Badge>
            </div>
        </div>

        <!-- Draft Registrasi Invoice Hutang yang memakai berkas ini (dari tombol "Edit" berkas di wizard). -->
        <div v-if="draftInvoice" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-900 dark:bg-primary-950/40">
            <span>Registrasi Invoice Hutang Anda dengan berkas ini tersimpan sebagai <b>draft</b>.</span>
            <Link :href="route('bs.ap.create')" class="font-medium text-primary-700 hover:underline dark:text-primary-300">Lanjutkan invoice →</Link>
        </div>

        <!-- Aksi -->
        <div class="flex flex-wrap gap-2">
            <Link v-if="can.invoice" :href="route('bs.ap.create', { berkas: berkas.id })"><Button size="sm">Buat Invoice Hutang</Button></Link>

            <Button v-if="can.submit" size="sm" :disabled="wajibKurang.length > 0" @click="ajukan">{{ berkas.status === 'ditolak' ? 'Ajukan Ulang' : 'Ajukan ke Dept Head' }}</Button>
            <a :href="route('bs.ap.berkas.print', berkas.id)" target="_blank" rel="noopener"><Button size="sm" variant="outline">Cetak / PDF</Button></a>
            <Button v-if="can.close" size="sm" variant="warning" @click="dialog = 'close'">Tutup Parsial</Button>
            <Button v-if="can.cancel" size="sm" variant="ghost" @click="dialog = 'cancel'">Batalkan</Button>
            <Button v-if="can.delete" size="sm" variant="ghost" class="text-red-600" @click="dialog = 'delete'">Hapus</Button>
        </div>
        <p v-if="berkas.cancel_reason || berkas.close_reason" class="rounded-lg bg-neutral-100 px-3 py-2 text-sm dark:bg-neutral-800">
            ⓘ {{ berkas.cancel_reason ? 'Dibatalkan: ' + berkas.cancel_reason : 'Ditutup: ' + berkas.close_reason }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <div class="card space-y-4 p-6">
                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div><dt class="text-xs uppercase text-neutral-500">Tgl unggah</dt><dd class="font-medium">{{ berkas.upload_date }}</dd></div>
                        <div><dt class="text-xs uppercase text-neutral-500">Tgl tagihan</dt><dd class="font-medium">{{ berkas.document_date ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-neutral-500">Jatuh tempo</dt><dd class="font-medium">{{ berkas.needed_date ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-neutral-500">Supplier</dt><dd class="font-medium">{{ berkas.supplier ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-neutral-500">No. tagihan</dt><dd class="font-medium">{{ berkas.supplier_invoice_no ?? '—' }}</dd></div>
                        <div><dt class="text-xs uppercase text-neutral-500">Pengunggah / TTD</dt><dd class="font-medium">{{ berkas.uploader }} · {{ berkas.dept_head }}</dd></div>
                    </dl>
                    <table class="w-full text-sm tabular-nums">
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr class="font-bold"><td class="py-1.5">Total tagihan</td><td class="py-1.5 text-right">{{ rupiah(berkas.amount) }}</td></tr>
                            <tr><td class="py-1.5 text-neutral-500">Sudah diinvoice</td><td class="py-1.5 text-right">{{ rupiah(berkas.invoiced) }}</td></tr>
                            <tr v-if="berkas.sisa != null"><td class="py-1.5 text-neutral-500">Sisa</td><td class="py-1.5 text-right">{{ rupiah(berkas.sisa) }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- File -->
                <div class="card space-y-3 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <p class="text-xs uppercase text-neutral-500">File berkas ({{ berkas.files.length }})</p>
                            <button v-if="berkas.files.length > 1" type="button" :disabled="menggabung" title="Gabungkan semua file jadi satu PDF"
                                class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-medium text-primary-700 transition hover:bg-primary-100 disabled:opacity-60 dark:bg-primary-950/40 dark:text-primary-300"
                                @click="lihatSemua">
                                <Files class="h-3 w-3" />{{ menggabung ? 'Menggabung…' : 'Lihat semua' }}
                            </button>
                        </div>
                        <ul class="flex flex-wrap gap-1 text-xs">
                            <li v-for="j in jenisChecklist" :key="j.name" :class="['rounded-full px-2 py-0.5', ada(j.name) ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : j.is_mandatory ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800']">
                                {{ ada(j.name) ? '✓' : '○' }} {{ j.name }}{{ j.is_mandatory ? ' (wajib)' : '' }}
                            </li>
                        </ul>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="f in berkas.files" :key="f.id" class="flex items-center gap-3 rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                            <span class="text-lg">{{ f.mime === 'application/pdf' ? '📄' : '🖼️' }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ f.name }}</span>
                                <span class="text-xs text-neutral-500">{{ f.jenis ?? 'Jenis lain' }} · {{ ukuran(f.size) }}</span>
                            </span>
                            <button class="text-xs font-medium text-primary-600 hover:underline" @click="pratinjau = f">Lihat</button>
                            <a :href="f.url" target="_blank" rel="noopener" class="text-xs text-neutral-500 hover:underline">Buka ↗</a>
                        </li>
                    </ul>
                    <p v-if="galatGabung" class="text-xs text-red-600">{{ galatGabung }}</p>
                    <p v-if="can.edit" class="text-xs text-neutral-500">Tambah atau ganti file lewat tombol {{ berkas.status === 'ditolak' ? 'Revisi' : 'Edit' }}.</p>
                </div>

                <!-- Invoice -->
                <div v-if="invoices.length" class="card space-y-2 p-6">
                    <p class="text-xs uppercase text-neutral-500">Invoice hutang dari berkas ini</p>
                    <div v-for="i in invoices" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-800">
                        <Link :href="route('bs.ap.show', i.id)" class="font-mono text-primary-600 hover:underline">{{ i.number }}</Link>
                        <span class="tabular-nums">{{ rupiah(i.total) }}</span>
                        <Link v-if="i.approval" :href="i.approval.url" class="text-xs text-primary-600 hover:underline">{{ approvalText[i.approval.status] ?? i.approval.status }} →</Link>
                    </div>
                </div>
            </div>

            <!-- Riwayat -->
            <div class="card space-y-4 p-6 text-sm">
                <div>
                    <p class="font-semibold">Alur</p>
                    <p class="text-xs text-neutral-500">TTD Dept Head → Invoice (tim AP) → Accounting → Finance → GM → Direktur → MW / BA</p>
                    <Link v-if="ttdApprovalUrl" :href="ttdApprovalUrl" class="text-xs text-primary-600 hover:underline">Lacak TTD di Approval Inbox →</Link>
                </div>
                <div class="border-t border-neutral-200 pt-3 dark:border-neutral-800">
                    <p class="mb-2 font-semibold">Riwayat</p>
                    <ol class="space-y-3">
                        <li v-for="(t, i) in timeline" :key="i" class="border-l-2 border-neutral-200 pl-3 dark:border-neutral-700">
                            <p class="font-medium">{{ t.event }}</p>
                            <p class="text-xs text-neutral-500">{{ t.by }} · {{ waktu(t.at) }}</p>
                            <p v-if="t.notes" class="text-xs italic text-neutral-600 dark:text-neutral-300">“{{ t.notes }}”</p>
                            <ul v-if="t.changes.length" class="mt-1 space-y-0.5 text-xs text-neutral-600 dark:text-neutral-300">
                                <li v-for="c in t.changes" :key="c.field">{{ fieldLabel[c.field] ?? c.field }}: <span class="line-through opacity-60">{{ tampil(c.old) }}</span> → {{ tampil(c.new) }}</li>
                            </ul>
                        </li>
                        <li v-if="!timeline.length" class="text-xs text-neutral-500">Belum ada riwayat.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Pratinjau file -->
        <div v-if="pratinjau" class="fixed inset-0 z-50 flex flex-col bg-black/70 p-4" @click.self="pratinjau = null">
            <div class="mx-auto flex w-full max-w-4xl items-center justify-between gap-3 pb-2 text-white">
                <span class="truncate text-sm">{{ pratinjau.jenis ?? 'Jenis lain' }} · {{ pratinjau.name }}</span>
                <button class="rounded-md bg-white/15 px-3 py-1 text-sm hover:bg-white/25" @click="pratinjau = null">Tutup</button>
            </div>
            <div class="mx-auto flex min-h-0 w-full max-w-4xl flex-1 items-center justify-center overflow-auto rounded-lg bg-white">
                <iframe v-if="pratinjau.mime === 'application/pdf'" :src="pratinjau.url" class="h-full w-full" :title="pratinjau.name" />
                <img v-else :src="pratinjau.url" :alt="pratinjau.name" class="max-h-full max-w-full object-contain" />
            </div>
        </div>

        <!-- Lihat semua: satu PDF gabungan -->
        <div v-if="semua" class="fixed inset-0 z-50 flex flex-col bg-black/70 p-4" @click.self="tutupSemua">
            <div class="mx-auto flex w-full max-w-4xl items-center justify-between gap-3 pb-2 text-white">
                <span class="truncate text-sm">Semua berkas · {{ berkas.files.length }} file digabung</span>
                <div class="flex gap-2">
                    <a :href="semua.url" :download="semua.nama" class="rounded-md bg-white/15 px-3 py-1 text-sm hover:bg-white/25">Unduh PDF</a>
                    <button class="rounded-md bg-white/15 px-3 py-1 text-sm hover:bg-white/25" @click="tutupSemua">Tutup</button>
                </div>
            </div>
            <div class="mx-auto min-h-0 w-full max-w-4xl flex-1 overflow-hidden rounded-lg bg-white">
                <iframe :src="semua.url" class="h-full w-full" :title="semua.nama" />
            </div>
        </div>

        <!-- Dialog batal / tutup / hapus -->
        <div v-if="dialog" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="dialog = null">
            <div class="card w-full max-w-md space-y-3 p-5">
                <p class="font-semibold">{{ dialog === 'cancel' ? 'Batalkan berkas?' : dialog === 'close' ? 'Tutup berkas (parsial)?' : 'Hapus berkas?' }}</p>
                <p v-if="dialog === 'close'" class="text-sm text-neutral-600">Sisa {{ rupiah(berkas.sisa) }} tidak akan dibuat invoice.</p>
                <p v-if="dialog === 'delete'" class="text-sm text-neutral-600">Berkas yang dihapus tetap bisa dilihat tim AP lewat "Tampilkan yang dihapus".</p>
                <template v-if="dialog !== 'delete'">
                    <label class="label-base" for="alasan">Alasan <span class="text-red-500">*</span></label>
                    <textarea id="alasan" v-model="alasan" rows="3" class="input-base h-auto py-2" />
                </template>
                <div class="flex justify-end gap-2">
                    <Button variant="secondary" @click="dialog = null">Kembali</Button>
                    <Button :variant="dialog === 'delete' ? 'danger' : 'warning'" :disabled="dialog !== 'delete' && !alasan.trim()" @click="konfirmasi">
                        {{ dialog === 'cancel' ? 'Batalkan' : dialog === 'close' ? 'Tutup berkas' : 'Hapus' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
