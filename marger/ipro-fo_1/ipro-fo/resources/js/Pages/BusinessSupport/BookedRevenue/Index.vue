<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Plus, ArrowLeft, CircleDollarSign, FileSpreadsheet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = {
    id: number; br_number: string; booking_date: string | null;
    customer: { code: string; name: string } | null;
    spr: string | null; unit: string | null; description: string | null;
    // Jurnal yang terbit dari BR ini. JAMAK: jurnal Booking dan jurnal Pengakuan bernomor
    // berbeda, dan menampilkan salah satunya saja membuat yang lain seolah tidak pernah ada.
    journals: Array<{ id: number; number: string; jenis: string }>;
    dpp: number; ppn: number; total: number;
};
const props = defineProps<{
    rows: { data: Row[]; links?: Array<any>; total?: number };
    filters: { search?: string };
    summary: { count: number; total: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

const f = reactive({ search: props.filters.search ?? '' });
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('bs.booked-revenue.index'), { search: val.search || undefined }, { preserveState: true, replace: true }), 300);
});
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

/* Ekspor mengikuti kata pencarian yang sedang berlaku, supaya berkasnya berisi persis apa
   yang sedang dilihat — tetapi TANPA paginasi, sehingga jumlah barisnya cocok dengan kartu
   ringkasan di atas, bukan dengan halaman yang kebetulan terbuka. */
function exportExcel() {
    window.location.href = route('bs.booked-revenue.export', { search: f.search || undefined });
}
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CircleDollarSign class="h-6 w-6 text-primary-600" /> Booked Revenue <span class="text-base font-normal text-neutral-400">(Pendapatan Ter-booking)</span></h1>
            <Link :href="route('bs.booked-revenue.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Booking Baru</Button></Link>
        </div>

        <!-- Ringkasan — cukup jumlah & total nilai; tidak ada lagi status "diakui" / "dibatalkan". -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Jumlah Booked Revenue</p><p class="mt-1 text-2xl font-bold">{{ summary.count }}<span class="ml-1 text-sm font-normal text-neutral-400">record</span></p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Nilai Revenue (DPP)</p><p class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(summary.total) }}</p></div>
        </div>

        <!-- Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari no. BR / customer / unit / SPR…" :class="[inputCls, 'w-72']" />
            <button type="button" @click="exportExcel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700">
                <FileSpreadsheet class="h-4 w-4" /> Excel
            </button>
        </div>

        <!-- Tabel — rincian disamakan dengan Invoice Piutang (No. SPR, Unit, DPP, PPN, Total). -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">No. BR</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">No. SPR</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">No. Jurnal</th><th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th><th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold">{{ r.br_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.booking_date) }}</td>
                        <td class="px-4 py-2.5"><span v-if="r.customer"><span class="font-mono text-xs text-neutral-400">{{ r.customer.code }}</span> {{ r.customer.name }}</span><span v-else>—</span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.spr ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <!-- Nomornya menaut langsung ke jurnalnya: pertanyaan "jurnal mana yang
                             terbit dari BR ini" hampir selalu berlanjut jadi "tunjukkan isinya". -->
                        <td class="px-4 py-2.5">
                            <span v-if="!r.journals?.length" class="text-neutral-400">—</span>
                            <template v-else>
                                <Link v-for="j in r.journals" :key="j.id" :href="route('bs.gl.journal.show', j.id)"
                                      class="block whitespace-nowrap font-mono text-xs text-primary-600 hover:underline"
                                      :title="'Jurnal ' + j.jenis.toLowerCase() + ' dari ' + r.br_number">{{ j.number }}<span class="ml-1 font-sans text-[10px] text-neutral-400">{{ j.jenis }}</span></Link>
                            </template>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.description ?? '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.dpp) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.ppn) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                        <td class="px-4 py-2.5 text-right"><Link :href="route('bs.booked-revenue.show', r.id)" class="text-sm font-medium text-primary-600 hover:underline">Lihat / Edit</Link></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada Booked Revenue" description="Klik Booking Baru untuk mem-booking pendapatan dari customer & unit." />
        </div>

        <div v-if="(rows.links?.length ?? 0) > 3" class="flex flex-wrap gap-1">
            <component :is="l.url ? Link : 'span'" v-for="l in rows.links" :key="l.label" :href="l.url" v-html="l.label"
                class="rounded-md px-3 py-1.5 text-sm" :class="l.active ? 'bg-primary-600 text-white' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'" />
        </div>
    </div>
</template>
