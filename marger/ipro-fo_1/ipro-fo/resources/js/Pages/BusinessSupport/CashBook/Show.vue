<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Banknote, BookText, Pencil, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ trx: any; types: Record<string, string> }>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const badge: Record<string, any> = { in: 'success', out: 'danger', transfer: 'info' };
const isIn = props.trx.trx_type === 'in';

// Rincian akun; fallback ke akun lawan tunggal bila data lama belum punya baris.
const lines = computed(() => {
    const ls = props.trx.lines;
    if (Array.isArray(ls) && ls.length) return ls;
    return [{ id: 0, account: props.trx.counter_account, amount: props.trx.amount, description: null }];
});
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> {{ trx.trx_number }}</h1>
            <div class="flex items-center gap-2">
                <a :href="route('bs.cashbook.print', trx.id)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Printer class="h-4 w-4" /> Cetak</a>
                <Link :href="route('bs.cashbook.edit', trx.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Pencil class="h-4 w-4" /> Edit</Link>
                <Badge :variant="badge[trx.trx_type] ?? 'neutral'">{{ types[trx.trx_type] }}</Badge>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(trx.trx_date) }}</div></div>
            <div><div class="text-neutral-500">Referensi</div><div class="font-medium">{{ trx.reference ?? '—' }}</div></div>
            <div><div class="text-neutral-500">{{ trx.trx_type === 'transfer' ? 'Rekening Sumber' : 'Rekening Kas/Bank' }}</div><div class="font-medium"><span class="font-mono text-xs text-neutral-400">{{ trx.cash_account?.code }}</span> {{ trx.cash_account?.name }}</div></div>
            <div v-if="trx.payee"><div class="text-neutral-500">Pihak</div><div class="font-medium">{{ trx.payee }}</div></div>
            <div class="md:col-span-2"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ trx.description }}</div></div>

            <!-- #15: rincian akun lawan (bisa >1 akun). -->
            <div class="md:col-span-2">
                <div class="mb-1 text-neutral-500">{{ trx.trx_type === 'transfer' ? 'Rekening Tujuan' : 'Rincian Akun Lawan' }}</div>
                <table class="w-full">
                    <tbody>
                        <tr v-for="l in lines" :key="l.id" class="border-b border-neutral-100 last:border-0 dark:border-neutral-800">
                            <td class="py-1.5 pr-2"><span class="font-mono text-xs text-neutral-400">{{ l.account?.code }}</span> {{ l.account?.name }}<span v-if="l.description" class="text-neutral-400"> — {{ l.description }}</span></td>
                            <td class="py-1.5 text-right font-mono">{{ fmtRp(Number(l.amount)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="md:col-span-2 flex items-center justify-between rounded-lg px-4 py-3" :class="isIn ? 'bg-success-50 dark:bg-success-900/20' : 'bg-neutral-50 dark:bg-neutral-800'">
                <span class="font-semibold">Total Nominal<span v-if="lines.length > 1" class="ml-1 text-xs font-normal text-neutral-500">({{ lines.length }} akun)</span></span>
                <span class="font-mono text-xl font-bold" :class="isIn ? 'text-success-700 dark:text-success-300' : 'text-danger-600'">{{ fmtRp(trx.amount) }}</span>
            </div>
        </div>

        <div v-if="trx.journal_id" class="card flex items-center gap-3 p-4 text-sm">
            <BookText class="h-5 w-5 text-primary-600" />
            <div class="flex-1">
                <div class="font-medium">Jurnal otomatis terbentuk</div>
                <div class="text-neutral-500">
                    {{ trx.trx_type === 'in' ? 'Dr Kas/Bank · Cr Akun Lawan' : trx.trx_type === 'out' ? 'Dr Akun Lawan · Cr Kas/Bank' : 'Dr Rekening Tujuan · Cr Rekening Sumber' }} — #{{ trx.journal_id }}
                </div>
            </div>
            <Link :href="route('bs.gl.journal.show', trx.journal_id)" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700">Lihat Jurnal</Link>
        </div>
    </div>
</template>
