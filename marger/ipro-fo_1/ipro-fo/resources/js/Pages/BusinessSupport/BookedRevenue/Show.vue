<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleDollarSign, CheckCircle2, XCircle, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ br: any }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const statusMeta: Record<string, { label: string; variant: 'warning' | 'success' | 'neutral' }> = {
    booked:     { label: 'Booked',     variant: 'warning' },
    recognized: { label: 'Diakui',     variant: 'success' },
    cancelled:  { label: 'Dibatalkan', variant: 'neutral' },
};

function recognize() {
    if (confirm(`Akui pendapatan ${props.br.br_number} sebesar ${fmtRp(props.br.amount)}?\n\nJurnal: Dr Pendapatan Diterima Dimuka / Cr Penjualan Usaha.`))
        router.post(route('bs.booked-revenue.recognize', props.br.id), {}, { preserveScroll: true });
}
function cancel() {
    if (confirm(`Batalkan Booked Revenue ${props.br.br_number}?\n\nJurnal balik diposting (Dr Pendapatan Diterima Dimuka / Cr Piutang Usaha).`))
        router.post(route('bs.booked-revenue.cancel', props.br.id), {}, { preserveScroll: true });
}
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <CircleDollarSign class="h-6 w-6 text-primary-600" /> {{ br.br_number }}
                <Badge :variant="statusMeta[br.status]?.variant ?? 'neutral'">{{ statusMeta[br.status]?.label ?? br.status }}</Badge>
            </h1>
            <div v-if="br.status === 'booked'" class="flex items-center gap-2">
                <button @click="cancel" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"><XCircle class="h-4 w-4" /> Batalkan</button>
                <Button @click="recognize"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Akui Pendapatan</Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Nilai -->
            <div class="card p-5 lg:col-span-1">
                <div class="text-xs uppercase text-neutral-400">Nilai Revenue</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(br.amount) }}</div>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-neutral-500">Harga</dt><dd class="tabular-nums">{{ fmtRp(br.gross_price) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Diskon/Free DP</dt><dd class="tabular-nums text-danger-600">− {{ fmtRp(br.discount) }}</dd></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 dark:border-neutral-700"><dt class="text-neutral-500">Revenue (÷ 1,11)</dt><dd class="tabular-nums font-semibold text-primary-600">{{ fmtRp(br.amount) }}</dd></div>
                </dl>
                <div class="mt-3 text-sm text-neutral-500">Tanggal: {{ fmtDate(br.booking_date) }}</div>
                <div v-if="br.recognized_at" class="text-sm text-emerald-600">Diakui: {{ fmtDate(br.recognized_at) }}</div>
                <div v-if="br.cancelled_at" class="text-sm text-neutral-500">Dibatalkan: {{ fmtDate(br.cancelled_at) }}</div>
            </div>

            <!-- Customer & Unit -->
            <div class="card p-5 text-sm lg:col-span-2">
                <div class="mb-2 font-semibold">Customer &amp; Unit</div>
                <dl class="grid grid-cols-[130px_1fr] gap-y-1.5">
                    <dt class="text-neutral-500">Customer</dt><dd><span v-if="br.customer"><span class="font-mono text-xs text-neutral-400">{{ br.customer.code }}</span> {{ br.customer.name }}</span><span v-else>—</span></dd>
                    <dt class="text-neutral-500">Telepon</dt><dd>{{ br.customer?.phone || '—' }}</dd>
                    <dt class="text-neutral-500">Email</dt><dd>{{ br.customer?.email || '—' }}</dd>
                    <dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ br.unit ?? '—' }}</dd>
                    <dt class="text-neutral-500">Referensi</dt><dd>{{ br.reference || '—' }}</dd>
                    <dt class="text-neutral-500">Keterangan</dt><dd>{{ br.description || '—' }}</dd>
                </dl>
            </div>
        </div>

        <!-- Jurnal -->
        <div class="card p-5 text-sm">
            <div class="mb-3 flex items-center gap-2 font-semibold"><FileText class="h-5 w-5 text-primary-600" /> Jejak Jurnal (GL)</div>
            <dl class="grid grid-cols-[180px_1fr] gap-y-2">
                <dt class="text-neutral-500">Akun Diterima Dimuka</dt><dd class="font-mono text-xs">{{ br.deferred || '—' }}</dd>
                <dt class="text-neutral-500">Jurnal Booking</dt>
                <dd><span class="font-mono">{{ br.booking_journal || '—' }}</span> <span class="text-xs text-neutral-400">Dr Piutang / Cr Pendapatan Diterima Dimuka</span></dd>
                <dt class="text-neutral-500">Jurnal Pengakuan</dt>
                <dd><span class="font-mono">{{ br.recognition_journal || '—' }}</span> <span v-if="br.recognition_journal" class="text-xs text-neutral-400">Dr Pendapatan Diterima Dimuka / Cr Penjualan Usaha</span></dd>
            </dl>
        </div>
    </div>
</template>
