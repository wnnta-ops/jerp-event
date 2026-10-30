<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = {
    id: number; number: string; date: string | null; supplier: string | null; supplier_invoice_no: string | null;
    amount: number | null; description: string; status: string; dept_head: string | null; uploader: string | null;
    files_count: number; invoice: { id: number; number: string } | null;
};

const props = defineProps<{
    tab: string;
    tabs: string[];
    counts: Record<string, number>;
    rows: Row[];
    canCreateInvoice: boolean;
    canUpload: boolean;
}>();

const label: Record<string, string> = { saya: 'Berkas Saya', siap: 'Siap Dibuat Invoice', semua: 'Semua Berkas' };
const kosong: Record<string, string> = {
    saya: 'Anda belum mengunggah berkas.',
    siap: 'Tidak ada berkas yang menunggu dibuat invoice.',
    semua: 'Belum ada berkas.',
};
const status: Record<string, { variant: string; text: string }> = {
    menunggu_ttd: { variant: 'warning', text: 'Menunggu TTD Dept Head' },
    siap_invoice: { variant: 'info', text: 'Siap dibuat invoice' },
    diinvoice: { variant: 'success', text: 'Sudah diinvoice' },
    ditolak: { variant: 'danger', text: 'Ditolak Dept Head' },
};

const go = (tab: string) => router.get(route('bs.ap.berkas.index'), { tab }, { preserveState: true, replace: true });
const rupiah = (v: number) => 'Rp ' + v.toLocaleString('id-ID');
</script>

<template>
    <Head title="Upload Berkas" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Upload Berkas</h1>
                <p class="text-sm text-neutral-500">Berkas tagihan → TTD Dept Head → Invoice Hutang → approval Accounting s.d. MW / BA → bayar.</p>
            </div>
            <Link v-if="canUpload" :href="route('bs.ap.berkas.create')"><Button>+ Unggah Berkas</Button></Link>
        </div>

        <div class="flex gap-2 overflow-x-auto border-b border-neutral-200 dark:border-neutral-800">
            <button
                v-for="t in tabs" :key="t" @click="go(t)"
                :class="['whitespace-nowrap px-4 py-2 text-sm font-medium', props.tab === t ? 'border-b-2 border-primary-600 text-primary-600' : 'text-neutral-500 hover:text-neutral-700']"
            >
                {{ label[t] ?? t }}
                <span class="ml-1 rounded-full bg-neutral-100 px-2 py-0.5 text-xs dark:bg-neutral-800">{{ counts[t] ?? 0 }}</span>
            </button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No. Berkas</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Diunggah</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-3 font-mono text-xs font-semibold">{{ r.number }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ r.description }}</p>
                            <p class="text-xs text-neutral-500">{{ r.supplier ?? 'Supplier belum dipilih' }}<template v-if="r.supplier_invoice_no"> · {{ r.supplier_invoice_no }}</template> · 📎 {{ r.files_count }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ r.amount != null ? rupiah(r.amount) : '—' }}</td>
                        <td class="px-4 py-3">
                            <p>{{ r.uploader }}</p>
                            <p class="text-xs text-neutral-500">{{ r.date }} · TTD: {{ r.dept_head }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="(status[r.status]?.variant as any) ?? 'neutral'" dot>{{ status[r.status]?.text ?? r.status }}</Badge>
                            <p v-if="r.invoice" class="mt-1 font-mono text-xs text-neutral-500">{{ r.invoice.number }}</p>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Link v-if="canCreateInvoice && r.status === 'siap_invoice'" :href="route('bs.ap.create', { berkas: r.id })" class="mr-3 text-sm font-medium text-primary-600 hover:underline">Buat Invoice →</Link>
                            <Link :href="route('bs.ap.berkas.show', r.id)" class="text-sm text-neutral-500 hover:underline">Detail</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" :title="kosong[props.tab] ?? 'Tidak ada data.'" />
        </div>
    </div>
</template>
