<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, FileCheck2, Undo2, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ journal: any; sources: Record<string, string>; statuses: Record<string, string> }>();
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const statusBadge: Record<string, any> = { draft: 'warning', final: 'info', posted: 'success' };

function act(name: string) { router.post(route(`bs.gl.journal.${name}`, props.journal.id), {}, { preserveScroll: true }); }
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.journal.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Detail Transaksi Jurnal</Link>
        <div class="flex items-center justify-between">
            <h1 class="font-mono text-2xl font-bold">{{ journal.journal_number }}</h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.gl.journal.edit', journal.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Pencil class="h-4 w-4" /> Edit Jurnal</Link>
                <Badge :variant="statusBadge[journal.status] ?? 'neutral'">{{ statuses[journal.status] ?? journal.status }}</Badge>
                <Badge variant="info">{{ sources[journal.source] ?? journal.source }}</Badge>
            </div>
        </div>

        <!-- Siklus: Baru → Final → Approved -->
        <div class="card flex flex-wrap items-center gap-3 p-4">
            <div class="flex items-center gap-1.5 text-sm">
                <span :class="['rounded-full px-2.5 py-0.5 text-xs font-semibold', journal.status==='draft' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30' : 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800']">Baru</span>
                <span class="text-neutral-300">→</span>
                <span :class="['rounded-full px-2.5 py-0.5 text-xs font-semibold', journal.status==='final' ? 'bg-sky-100 text-sky-700 dark:bg-sky-900/30' : (journal.status==='posted' ? 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800' : 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800')]">Final</span>
                <span class="text-neutral-300">→</span>
                <span :class="['rounded-full px-2.5 py-0.5 text-xs font-semibold', journal.status==='posted' ? 'bg-success-100 text-success-700 dark:bg-success-900/30' : 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800']">Approved</span>
            </div>
            <div class="ml-auto flex gap-2">
                <Button v-if="journal.status==='draft'" size="sm" @click="act('finalize')"><template #icon-left><FileCheck2 class="h-4 w-4" /></template>Finalisasi</Button>
                <Button v-if="journal.status==='final'" size="sm" @click="act('approve')"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Approve</Button>
                <button v-if="journal.status==='final' || journal.status==='posted'" @click="act('reject')" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Undo2 class="h-4 w-4" /> Kembalikan ke Baru</button>
            </div>
        </div>
        <p v-if="journal.status!=='posted'" class="-mt-2 text-xs text-amber-600">Jurnal hanya efektif di Neraca Saldo / Laporan Keuangan setelah berstatus <b>Approved</b>.</p>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(journal.journal_date) }}</div></div>
            <div><div class="text-neutral-500">Periode</div><div class="font-medium">{{ journal.period_year }}/{{ journal.period_month }}</div></div>
            <div><div class="text-neutral-500">No. Manual</div><div class="font-medium">{{ journal.reference ?? '—' }}</div></div>
            <div class="md:col-span-3"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ journal.description }}</div></div>
        </div>
        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in journal.lines" :key="l.id">
                        <td class="px-4 py-2.5"><span class="font-mono">{{ l.account?.code }}</span> {{ l.account?.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ l.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.debit) > 0 ? fmtRp(l.debit) : '' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(l.credit) > 0 ? fmtRp(l.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 dark:bg-neutral-800 font-bold"><tr><td colspan="2" class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(journal.total_debit) }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(journal.total_credit) }}</td></tr></tfoot>
            </table>
        </div>
    </div>
</template>
