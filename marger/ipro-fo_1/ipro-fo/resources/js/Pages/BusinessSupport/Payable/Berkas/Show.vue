<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    berkas: {
        id: number; number: string; date: string | null; supplier: string | null; supplier_invoice_no: string | null;
        amount: number | null; description: string; status: string; dept_head: string | null; uploader: string | null;
        invoice: { id: number; number: string } | null;
        files: Array<{ id: number; name: string; jenis: string | null; size: number; mime: string; url: string }>;
    };
    ttdApprovalUrl: string | null;
    invoiceApproval: { status: string; url: string } | null;
    canCreateInvoice: boolean;
}>();

const status: Record<string, { variant: string; text: string }> = {
    menunggu_ttd: { variant: 'warning', text: 'Menunggu TTD Dept Head' },
    siap_invoice: { variant: 'info', text: 'Siap dibuat invoice' },
    diinvoice: { variant: 'success', text: 'Sudah diinvoice' },
    ditolak: { variant: 'danger', text: 'Ditolak Dept Head' },
};
const approvalText: Record<string, string> = {
    pending: 'Menunggu approval', in_review: 'Dalam approval', approved: 'Disetujui — boleh dibayar', rejected: 'Ditolak',
};
const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
</script>

<template>
    <Head :title="berkas.number + ' — Upload Berkas'" />
    <div class="mx-auto max-w-4xl space-y-4">
        <Link :href="route('bs.ap.berkas.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Upload Berkas</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-mono text-sm text-neutral-500">{{ berkas.number }}</p>
                <h1 class="text-2xl font-bold">{{ berkas.description }}</h1>
            </div>
            <Badge :variant="(status[berkas.status]?.variant as any) ?? 'neutral'" dot>{{ status[berkas.status]?.text ?? berkas.status }}</Badge>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card space-y-4 p-6 lg:col-span-2">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs uppercase text-neutral-500">Tanggal</dt><dd class="font-medium">{{ berkas.date }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">Nominal</dt><dd class="font-medium tabular-nums">{{ berkas.amount != null ? rupiah(berkas.amount) : '—' }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">Supplier</dt><dd class="font-medium">{{ berkas.supplier ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">No. Tagihan Supplier</dt><dd class="font-medium">{{ berkas.supplier_invoice_no ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">Diunggah oleh</dt><dd class="font-medium">{{ berkas.uploader }}</dd></div>
                    <div><dt class="text-xs uppercase text-neutral-500">TTD Dept Head</dt><dd class="font-medium">{{ berkas.dept_head }}</dd></div>
                </dl>

                <div class="border-t border-neutral-200 pt-4 dark:border-neutral-800">
                    <p class="mb-2 text-xs uppercase text-neutral-500">File berkas ({{ berkas.files.length }})</p>
                    <ul class="space-y-2">
                        <li v-for="f in berkas.files" :key="f.id">
                            <a :href="f.url" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-lg border border-neutral-200 p-3 hover:border-primary-300 hover:bg-primary-50/40 dark:border-neutral-800 dark:hover:bg-neutral-800">
                                <span class="text-lg">{{ f.mime === 'application/pdf' ? '📄' : '🖼️' }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ f.name }}</span>
                                    <span class="text-xs text-neutral-500">{{ f.jenis ?? 'Jenis lain' }} · {{ ukuran(f.size) }}</span>
                                </span>
                                <span class="text-xs text-primary-600">Buka ↗</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card space-y-4 p-6 text-sm">
                <p class="font-semibold">Alur</p>
                <ol class="space-y-3">
                    <li>
                        <p class="font-medium">1 · TTD Dept Head</p>
                        <p class="text-xs text-neutral-500">{{ berkas.status === 'menunggu_ttd' ? 'Menunggu ' + berkas.dept_head : berkas.status === 'ditolak' ? 'Ditolak ' + berkas.dept_head : 'Ditandatangani ' + berkas.dept_head }}</p>
                        <Link v-if="ttdApprovalUrl" :href="ttdApprovalUrl" class="text-xs text-primary-600 hover:underline">Lihat di Approval Inbox →</Link>
                    </li>
                    <li>
                        <p class="font-medium">2 · Invoice Hutang (tim AP)</p>
                        <p v-if="berkas.invoice" class="text-xs">
                            <Link :href="route('bs.ap.show', berkas.invoice.id)" class="font-mono text-primary-600 hover:underline">{{ berkas.invoice.number }}</Link>
                        </p>
                        <p v-else class="text-xs text-neutral-500">{{ berkas.status === 'siap_invoice' ? 'Menunggu dibuat' : 'Belum' }}</p>
                    </li>
                    <li>
                        <p class="font-medium">3 · Approval invoice</p>
                        <p class="text-xs text-neutral-500">Accounting → Finance → GM → Direktur → MW / BA</p>
                        <template v-if="invoiceApproval">
                            <p class="text-xs">{{ approvalText[invoiceApproval.status] ?? invoiceApproval.status }}</p>
                            <Link :href="invoiceApproval.url" class="text-xs text-primary-600 hover:underline">Lacak di Approval Inbox →</Link>
                        </template>
                    </li>
                </ol>
                <Link v-if="canCreateInvoice" :href="route('bs.ap.create', { berkas: berkas.id })" class="block"><Button class="w-full">Buat Invoice Hutang</Button></Link>
            </div>
        </div>
    </div>
</template>
