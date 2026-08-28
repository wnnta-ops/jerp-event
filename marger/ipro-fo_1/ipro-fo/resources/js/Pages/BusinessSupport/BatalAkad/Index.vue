<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { TriangleAlert, RotateCcw, ArrowLeft, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ScrollTopButton from '@/Components/UI/ScrollTopButton.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';

type Row = {
    id: number; kpr_number: string | null; customer_id: number | null; customer: string | null; unit: string | null; booking: string | null;
    bank: string | null; status: 'cancelled' | 'rejected'; status_label: string;
    batal_at: string | null; tahap_sebelum: string | null; akad_at: string | null; sempat_akad: boolean;
    alasan: string | null; plafon: number;
};
const props = defineProps<{ rows: Row[]; totals: { count: number; cancelled: number; rejected: number; sempat_akad: number } }>();

const f = ref({ q: '', status: '', akad: '' });
function resetFilter() { f.value = { q: '', status: '', akad: '' }; }

/* Saringan halaman ini murni di sisi peramban dan tidak pernah masuk URL, jadi tombol
   ekspornya yang mengirimkannya. Tanpa itu berkasnya berisi lebih banyak baris daripada
   yang sedang dilihat operator. */
function exportExcel() {
    window.location.href = route('bs.batal-akad.export', {
        q: f.value.q || undefined,
        status: f.value.status || undefined,
        akad: f.value.akad || undefined,
    });
}
const filtered = computed(() => {
    const s = f.value.q.trim().toLowerCase();
    return props.rows.filter((r) => {
        if (f.value.status && r.status !== f.value.status) return false;
        if (f.value.akad === 'ya' && ! r.sempat_akad) return false;
        if (f.value.akad === 'tidak' && r.sempat_akad) return false;
        if (! s) return true;
        return [r.kpr_number, r.customer, r.unit, r.booking, r.bank, r.alasan].some((v) => String(v ?? '').toLowerCase().includes(s));
    });
});

/* Pengajuan digabungkan per CUSTOMER — logika yang sama dengan Reject Proses KPR. Satu
   customer dengan empat pembatalan tampil sebagai empat baris yang namanya berulang, dan
   tidak ada cara melihat bahwa keempatnya orang yang sama.

   Kuncinya customer_id, BUKAN nama: dua orang berbeda yang kebetulan bernama sama akan
   tergabung jadi satu baris kalau namanya yang dipakai, dan pembatalan milik orang lain
   terbaca sebagai miliknya. Baris tanpa customer dikelompokkan sendiri-sendiri (kunci
   memakai id pengajuannya) supaya tidak saling menempel. */
type Grup = { key: string; nama: string; rows: Row[] };
const grouped = computed<Grup[]>(() => {
    const peta = new Map<string, Grup>();
    for (const r of filtered.value) {
        const key = r.customer_id != null ? 'c' + r.customer_id : 'x' + r.id;
        if (! peta.has(key)) peta.set(key, { key, nama: r.customer ?? '—', rows: [] });
        peta.get(key)!.rows.push(r);
    }

    return [...peta.values()];
});

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function d(v: any) { return v ?? '—'; }
const fCls = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-800';
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><TriangleAlert class="h-6 w-6 text-danger-600" /> Rincian Batal Akad</h1>
            <button type="button" @click="exportExcel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700">
                <FileSpreadsheet class="h-4 w-4" /> Excel
            </button>
            <p class="text-sm text-neutral-500">
                Customer yang Proses KPR-nya <strong>dibatalkan</strong> atau <strong>ditolak</strong>. Baris ini sengaja
                <strong>dikeluarkan dari antrean</strong> Approval Retensi &amp; Approval UM Penjualan agar tidak ikut disetujui —
                yang telanjur dijurnal tetap tampil di halaman tersebut sebagai riwayat.
            </p>
        </div>

        <!-- Ringkasan -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="card p-4"><p class="text-xs text-neutral-500">Total Batal Akad</p><p class="mt-1 text-2xl font-bold">{{ totals.count }}</p></div>
            <div class="card p-4"><p class="text-xs text-neutral-500">Dibatalkan</p><p class="mt-1 text-2xl font-bold text-danger-600">{{ totals.cancelled }}</p></div>
            <div class="card p-4"><p class="text-xs text-neutral-500">Ditolak</p><p class="mt-1 text-2xl font-bold text-warning-600">{{ totals.rejected }}</p></div>
            <div class="card p-4">
                <p class="text-xs text-neutral-500">Sempat Akad</p>
                <p class="mt-1 text-2xl font-bold text-info-600">{{ totals.sempat_akad }}</p>
                <p class="text-[11px] text-neutral-400">akad terjadi lalu dibatalkan</p>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-neutral-500">{{ filtered.length }} dari {{ rows.length }} KPR</p>
            <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
        </div>

        <TableTopScroll v-if="rows.length">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-neutral-200 text-left text-xs uppercase text-neutral-500 dark:border-neutral-700">
                        <th class="px-3 py-2">Customer</th>
                        <th class="px-3 py-2">No. KPR</th>
                        <th class="px-3 py-2">Unit</th>
                        <th class="px-3 py-2">Bank</th>
                        <th class="px-3 py-2">Tanggal Batal Akad</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Tahap Sebelum Batal</th>
                        <th class="px-3 py-2 text-right">Plafon</th>
                        <th class="px-3 py-2">Keterangan</th>
                    </tr>
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th colspan="4" class="px-3 py-1.5"><input v-model="f.q" type="text" placeholder="Cari No. KPR / customer / unit / bank…" :class="fCls" /></th>
                        <th class="px-3 py-1.5">
                            <select v-model="f.akad" :class="fCls">
                                <option value="">Semua</option>
                                <option value="ya">Sempat akad</option>
                                <option value="tidak">Belum akad</option>
                            </select>
                        </th>
                        <th class="px-3 py-1.5">
                            <select v-model="f.status" :class="fCls">
                                <option value="">Semua</option>
                                <option value="cancelled">Dibatalkan</option>
                                <option value="rejected">Ditolak</option>
                            </select>
                        </th>
                        <th colspan="3"></th>
                    </tr>
                </thead>
                <tbody v-for="g in grouped" :key="g.key"
                       class="border-t-2 border-neutral-200 align-top dark:border-neutral-700">
                    <tr v-for="(r, i) in g.rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
                        <td v-if="i === 0" :rowspan="g.rows.length"
                            class="bg-neutral-50/60 px-3 py-2 font-medium dark:bg-neutral-800/40">
                            <span class="block break-words">{{ g.nama }}</span>
                            <span v-if="g.rows.length > 1"
                                  class="mt-1 inline-block rounded-md bg-danger-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ g.rows.length }}× batal akad</span>
                            <span v-else class="mt-1 block text-[10.5px] text-neutral-400">1 pembatalan</span>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs font-semibold" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ d(r.kpr_number) }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ d(r.unit) }}</td>
                        <td class="whitespace-nowrap px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ d(r.bank) }}</td>
                        <td class="px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">
                            <span class="font-medium">{{ d(r.batal_at) }}</span>
                            <p v-if="r.sempat_akad" class="text-[11px] text-info-600 dark:text-info-400" :title="'Akad sempat terjadi pada ' + r.akad_at">akad {{ r.akad_at }}</p>
                        </td>
                        <td class="px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><Badge :variant="r.status === 'cancelled' ? 'danger' : 'warning'" size="sm">{{ r.status === 'cancelled' ? 'Dibatalkan' : 'Ditolak' }}</Badge></td>
                        <td class="px-3 py-2 text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ d(r.tahap_sebelum) }}</td>
                        <td class="px-3 py-2 text-right font-mono" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ fmtRp(r.plafon) }}</td>
                        <td class="px-3 py-2 text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><span class="block max-w-[240px] truncate" :title="r.alasan ?? ''">{{ d(r.alasan) }}</span></td>
                    </tr>
                </tbody>
                <tbody v-if="! filtered.length">
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-sm text-neutral-500">Tidak ada KPR yang cocok dengan filter.</td>
                    </tr>
                </tbody>
            </table>
        </TableTopScroll>

        <EmptyState v-else title="Tidak ada batal akad"
            description="Belum ada Proses KPR yang dibatalkan atau ditolak. Bila ada, customer-nya akan tampil di sini beserta tanggal batalnya.">
            <template #icon><TriangleAlert class="h-12 w-12" /></template>
        </EmptyState>

        <ScrollTopButton />
    </div>
</template>
