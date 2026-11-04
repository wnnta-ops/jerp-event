<script setup lang="ts">
/**
 * Daftar Memo Urgent yang saya ajukan — cari, filter status approval / pembayaran / tanggal, dan
 * tombol "Tambahkan Memo Urgent". Setelah Ajukan, form kembali ke sini dengan notifikasi sukses.
 */
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Plus, Search, Printer, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Pagination from '@/Components/UI/Pagination.vue';

type Memo = {
    id: number; number: string; date: string | null; jenis: string; subject: string; department: string | null; supplier: string | null;
    amount: number; status: 'diajukan' | 'disetujui' | 'ditolak'; posisi: string; bayar: 'belum' | 'dibayar' | 'diinvoice'; url: string | null; cetak: string | null;
};
const props = defineProps<{
    memos: { data: Memo[]; links?: any[]; from?: number | null; to?: number | null; total?: number };
    filters: { q: string; status: string; bayar: string; dari: string; sampai: string };
    pilihanBayar: Record<string, string>;
    jumlah: { semua: number; diajukan: number; disetujui: number; ditolak: number };
}>();

const f = ref({ ...props.filters });
const tab = [
    { kunci: '', label: 'Semua', n: () => props.jumlah.semua },
    { kunci: 'diajukan', label: 'Diproses', n: () => props.jumlah.diajukan },
    { kunci: 'disetujui', label: 'Disetujui', n: () => props.jumlah.disetujui },
    { kunci: 'ditolak', label: 'Ditolak', n: () => props.jumlah.ditolak },
];

let jeda: ReturnType<typeof setTimeout> | null = null;
function saring(segera = false) {
    if (jeda) clearTimeout(jeda);
    const kirim = () => router.get(route('approval-inbox.memo'), Object.fromEntries(Object.entries(f.value).filter(([, v]) => v !== '')),
        { preserveState: true, preserveScroll: true, replace: true });
    segera ? kirim() : (jeda = setTimeout(kirim, 400));
}
watch(() => f.value.q, () => saring());
watch(() => [f.value.status, f.value.bayar, f.value.dari, f.value.sampai], () => saring(true));
const adaFilter = () => !!(f.value.q || f.value.bayar || f.value.dari || f.value.sampai);
function reset() { f.value = { q: '', status: f.value.status, bayar: '', dari: '', sampai: '' }; }

const fmtRp = (v: number) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const fmtTgl = (s: string | null) => (s ? new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const warnaStatus = (m: Memo) => (m.status === 'disetujui' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
    : m.status === 'ditolak' ? 'bg-danger-50 text-danger-700 dark:bg-danger-950/40 dark:text-danger-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300');
const labelBayar: Record<Memo['bayar'], string> = { belum: 'Belum dibayar', dibayar: 'Dibayar', diinvoice: 'Sudah diinvoice' };
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <Head title="Memo Urgent" />
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> Memo Urgent</h1>
                <p class="text-sm text-neutral-500">Memo Urgent yang Anda ajukan — posisi approval & pembayarannya.</p>
            </div>
            <Link :href="route('approval-inbox.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambahkan Memo Urgent</Button></Link>
        </div>

        <div class="card space-y-3 p-4">
            <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-800">
                <button v-for="t in tab" :key="t.kunci" type="button" @click="f.status = t.kunci"
                    :class="['-mb-px border-b-2 px-3 py-2 text-sm font-medium', f.status === t.kunci ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200']">
                    {{ t.label }} <span class="ml-1 rounded-full bg-neutral-100 px-1.5 text-[11px] dark:bg-neutral-800">{{ t.n() }}</span>
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[16rem] flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="f.q" type="search" placeholder="Cari no. memo, perihal, supplier, departemen…" :class="[inputCls, 'w-full pl-9']" />
                </div>
                <select v-model="f.bayar" :class="inputCls" title="Pembayaran">
                    <option value="">Semua pembayaran</option>
                    <option v-for="(label, k) in pilihanBayar" :key="k" :value="k">{{ label }}</option>
                </select>
                <label class="flex items-center gap-1.5 text-xs text-neutral-500">Dari <input v-model="f.dari" type="date" :class="inputCls" /></label>
                <label class="flex items-center gap-1.5 text-xs text-neutral-500">s.d. <input v-model="f.sampai" type="date" :class="inputCls" /></label>
                <button v-if="adaFilter()" type="button" class="inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-danger-600" @click="reset"><X class="h-3.5 w-3.5" /> Reset</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-left text-[11px] uppercase tracking-wide text-neutral-500 dark:bg-neutral-800/60">
                    <tr>
                        <th class="px-4 py-2.5">No. Memo</th>
                        <th class="px-4 py-2.5">Tanggal</th>
                        <th class="px-4 py-2.5">Perihal</th>
                        <th class="px-4 py-2.5">Supplier</th>
                        <th class="px-4 py-2.5 text-right">Nominal</th>
                        <th class="px-4 py-2.5">Posisi Approval</th>
                        <th class="px-4 py-2.5">Pembayaran</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="m in memos.data" :key="m.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40" :class="m.url ? 'cursor-pointer' : ''" @click="m.url && router.get(m.url)">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold">{{ m.number }}<div class="font-sans text-[11px] font-normal text-neutral-400">{{ m.jenis }}</div></td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtTgl(m.date) }}</td>
                        <td class="px-4 py-2.5"><div class="font-medium">{{ m.subject }}</div><div v-if="m.department" class="text-xs text-neutral-500">{{ m.department }}</div></td>
                        <td class="px-4 py-2.5">{{ m.supplier ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5"><span :class="['rounded-full px-2 py-0.5 text-[11px] font-medium', warnaStatus(m)]">{{ m.posisi }}</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-xs" :class="m.bayar === 'belum' ? 'text-neutral-500' : 'text-emerald-700 dark:text-emerald-300'">{{ labelBayar[m.bayar] }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right" @click.stop>
                            <a v-if="m.cetak" :href="m.cetak" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-primary-600" title="Cetak memo"><Printer class="h-3.5 w-3.5" /> Cetak</a>
                        </td>
                    </tr>
                    <tr v-if="!memos.data.length">
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-neutral-400">
                            {{ adaFilter() || f.status ? 'Tidak ada memo yang cocok dengan pencarian / filter.' : 'Belum ada Memo Urgent. Klik "Tambahkan Memo Urgent" untuk membuat.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="memos" satuan="memo" />
    </div>
</template>
