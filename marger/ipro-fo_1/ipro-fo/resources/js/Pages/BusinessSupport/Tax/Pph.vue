<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Landmark, Download, ReceiptText, Printer, BookText } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    period: { year: number; month: number };
    rows: Array<any>;
    byArticle: Record<string, number>;
    totalTerutang: number;
    prepaid: number;
    gl: Record<string, number>;
    belumApproved: Record<string, number>;
    akun: Array<{ id: number; code: string; name: string; article: string }>;
    periode: { from: string; to: string };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.pph'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const articleKeys = Object.keys(props.byArticle);

// Tautan ke Detail Transaksi Jurnal untuk satu akun Hutang PPh, membawa rentang masa
// pajaknya. Layar tujuan berbawaan "bulan berjalan", jadi tanpa rentang ini tautannya
// mendarat di masa yang salah dan terbaca sebagai "jurnalnya tidak ada".
function linkAkun(article: string) {
    const a = props.akun.find((x) => x.article === article);

    return route('bs.gl.journal.detail', {
        f_account: a?.id || undefined,
        date_from: props.periode.from,
        date_to: props.periode.to,
    });
}
const linkCls = 'font-mono text-xs text-primary-600 hover:underline dark:text-primary-400';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.section', { section: 'query' })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Query — Account Payable</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Landmark class="h-6 w-6 text-accent-600" /> Rekap PPh <span class="text-base font-normal text-neutral-400">(SPT Masa — Pemotongan)</span></h1>
            <div class="flex gap-2">
                <select v-model.number="month" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select>
                <select v-model.number="year" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <a :href="route('bs.tax.cetak.pph', { year: period.year, month: period.month })" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Printer class="h-4 w-4" /> Cetak SPT</a>
                <a :href="route('bs.tax.pph.export', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> CSV</a>
            </div>
        </div>
        <p class="text-sm text-neutral-500">Masa Pajak <b>{{ months[period.month-1] }} {{ period.year }}</b> — PPh yang dipotong perusahaan (kewajiban setor).</p>
        <p class="text-sm text-neutral-500">
            Rinciannya dibaca langsung dari <b>akun Hutang PPh di buku besar</b>, jadi isinya sama dengan
            <b>Detail Transaksi Jurnal</b> untuk keempat akun itu — klik kode akun pada kartu di bawah untuk membukanya.
            Jurnal yang <b>belum Approved</b> tetap ditampilkan agar rinciannya utuh, tetapi tidak ikut menaikkan angka SPT.
        </p>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="a in articleKeys" :key="a" class="card p-4">
                <div class="text-xs uppercase text-neutral-400">{{ a }}</div>
                <div class="mt-1 text-xl font-bold" :class="byArticle[a] > 0 ? 'text-accent-600' : 'text-neutral-400'">{{ fmtRp(byArticle[a]) }}</div>
                <div class="mt-1 text-[11px] text-neutral-400">GL: {{ fmtRp(gl[a]) }} <span v-if="Math.abs(gl[a]-byArticle[a]) < 1" class="text-success-600">✓</span></div>
                <!-- Jurnal yang belum Approved tetap tampil di rincian tetapi TIDAK ikut
                     menaikkan angka SPT — disebut terpisah supaya selisihnya tidak jadi teka-teki. -->
                <div v-if="belumApproved[a]" class="mt-0.5 text-[11px] text-warning-600 dark:text-warning-400">belum approved: {{ fmtRp(belumApproved[a]) }}</div>
                <a :href="linkAkun(a)" :class="[linkCls, 'mt-1 inline-flex items-center gap-1']" :title="'Buka Detail Transaksi Jurnal untuk akun ' + a">
                    <BookText class="h-3 w-3" /> {{ akun.find((x) => x.article === a)?.code ?? '—' }}
                </a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-5 ring-1 ring-accent-200 dark:ring-accent-900"><div class="text-sm font-semibold text-accent-700 dark:text-accent-300">Total PPh Terutang (disetor)</div><div class="mt-1 text-2xl font-bold text-accent-600">{{ fmtRp(totalTerutang) }}</div><div class="mt-1 text-xs text-neutral-400">Jumlah seluruh pemotongan masa ini.</div></div>
            <div class="card p-5"><div class="text-sm font-semibold">PPh Dibayar Dimuka (kredit pajak)</div><div class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(prepaid) }}</div><div class="mt-1 text-xs text-neutral-400">PPh dipungut pelanggan dari piutang (AR) — mengurangi PPh badan, bukan kewajiban setor.</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Lawan Transaksi</th><th class="px-4 py-3">No. Dokumen</th><th class="px-4 py-3">Pasal</th><th class="px-4 py-3">Akun</th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">DPP/Bruto</th><th class="px-4 py-3 text-right">PPh</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, idx) in rows" :key="idx" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-4 py-2.5"><Badge :variant="r.source === 'Payroll' ? 'primary' : 'neutral'" size="sm">{{ r.source }}</Badge></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5">{{ r.partner }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.doc_no }}</td>
                        <td class="px-4 py-2.5">{{ r.article }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.account_code }}</td>
                        <td class="px-4 py-2.5">
                            <a :href="route('bs.gl.journal.show', r.journal_id)" :class="linkCls">{{ r.journal_number }}</a>
                        </td>
                        <td class="px-4 py-2.5"><Badge :variant="r.efektif ? 'success' : 'warning'" size="sm">{{ r.status_label }}</Badge></td>
                        <!-- DPP hanya ada bila dokumennya invoice hutang. Ditulis apa adanya
                             sebagai "—"; menampilkan Rp 0 akan terbaca sebagai DPP nol. -->
                        <td class="px-4 py-2.5 text-right font-mono">{{ r.base === null ? '—' : fmtRp(r.base) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono" :class="r.efektif ? 'text-accent-600' : 'text-neutral-400'">{{ fmtRp(r.pph) }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800"><td colspan="9" class="px-4 py-2.5 text-right">Total PPh Terutang (jurnal Approved saja)</td><td class="px-4 py-2.5 text-right font-mono text-accent-600">{{ fmtRp(totalTerutang) }}</td></tr></tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada pemotongan PPh" description="Tidak ada PPh dipotong (payroll/AP) pada masa pajak ini." />
        </div>
    </div>
</template>
