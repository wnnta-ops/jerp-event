<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Scale, CheckCircle2, Circle, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rec: any; lines: Array<any>; summary: any }>();

function toggle(line: any) {
    if (props.rec.status === 'reconciled') return;
    router.post(route('bs.bankrec.toggle', [props.rec.id, line.id]), {}, { preserveScroll: true });
}
function finalize() {
    if (!props.summary.balanced && !confirm('Selisih belum nol. Tetap finalisasi rekonsiliasi?')) return;
    if (props.summary.balanced && !confirm('Finalisasi rekonsiliasi ini?')) return;
    router.post(route('bs.bankrec.finalize', props.rec.id), {}, { preserveScroll: true });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.bankrec.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Rekonsiliasi Bank</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-xl font-bold"><Scale class="h-6 w-6 text-primary-600" /> {{ rec.account?.code }} - {{ rec.account?.name }}</h1>
            <Badge :variant="rec.status === 'reconciled' ? 'success' : 'warning'">{{ rec.status === 'reconciled' ? 'Reconciled' : 'Open' }}</Badge>
        </div>
        <p class="text-sm text-neutral-500">Per rekening koran tanggal <b>{{ fmtDate(rec.statement_date) }}</b></p>

        <!-- Ringkasan rekonsiliasi -->
        <div class="card p-5">
            <div class="grid gap-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between"><span class="text-neutral-500">Saldo Koran (bank)</span><span class="font-mono">{{ fmtRp(summary.statement) }}</span></div>
                <div class="flex justify-between"><span class="text-neutral-500">Saldo Buku (GL)</span><span class="font-mono">{{ fmtRp(summary.book) }}</span></div>
                <div class="flex justify-between text-success-600"><span>+ Setoran dalam perjalanan</span><span class="font-mono">{{ fmtRp(summary.deposits_in_transit) }}</span></div>
                <div class="flex justify-between text-danger-600"><span>− Pembayaran beredar (outstanding)</span><span class="font-mono">{{ fmtRp(summary.outstanding_payments) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold dark:border-neutral-700"><span>= Saldo Koran disesuaikan</span><span class="font-mono">{{ fmtRp(summary.adjusted) }}</span></div>
                <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold dark:border-neutral-700"><span>Selisih (Buku − Disesuaikan)</span><span class="font-mono" :class="summary.balanced ? 'text-success-600' : 'text-danger-600'">{{ fmtRp(summary.difference) }}</span></div>
            </div>
            <div class="mt-3 flex items-center justify-between rounded-lg px-4 py-2.5" :class="summary.balanced ? 'bg-success-50 text-success-700 dark:bg-success-900/20' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20'">
                <span class="flex items-center gap-2 text-sm font-semibold"><CheckCircle2 v-if="summary.balanced" class="h-5 w-5" /><Circle v-else class="h-5 w-5" /> {{ summary.balanced ? 'Seimbang — siap difinalisasi' : 'Belum seimbang — tandai transaksi yang ada di koran' }}</span>
                <span class="text-xs">{{ summary.cleared_count }}/{{ summary.total_count }} cleared</span>
            </div>
            <div v-if="rec.status !== 'reconciled'" class="mt-3"><Button variant="primary" @click="finalize"><template #icon-left><Lock class="h-4 w-4" /></template>Finalisasi Rekonsiliasi</Button></div>
        </div>

        <!-- Daftar mutasi -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3 text-center">Cleared</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Masuk</th><th class="px-4 py-3 text-right">Keluar</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in lines" :key="l.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="l.is_cleared ? 'bg-success-50/40 dark:bg-success-900/10' : ''">
                        <td class="px-4 py-2 text-center"><button type="button" @click="toggle(l)" :disabled="rec.status === 'reconciled'" class="disabled:opacity-50"><CheckCircle2 v-if="l.is_cleared" class="mx-auto h-5 w-5 text-success-500" /><Circle v-else class="mx-auto h-5 w-5 text-neutral-300" /></button></td>
                        <td class="px-4 py-2.5">{{ fmtDate(l.tx_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-600 dark:text-neutral-300">{{ l.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ Number(l.debit) > 0 ? fmtRp(l.debit) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ Number(l.credit) > 0 ? fmtRp(l.credit) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!lines.length" class="px-4 py-6 text-center text-sm text-neutral-400">Tidak ada mutasi kas/bank s/d tanggal koran.</div>
        </div>
    </div>
</template>
