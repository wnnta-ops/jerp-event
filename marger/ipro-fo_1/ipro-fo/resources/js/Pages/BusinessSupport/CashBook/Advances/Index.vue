<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeft, Wallet, HandCoins, Search, FileBarChart } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Bucket = { rows: any[]; count: number; total: number };
const props = defineProps<{
    advances: any;
    filters: { status?: string; search?: string };
    summary: { outstanding: number; count: number };
    buckets: { realisasi: Bucket; refund: Bucket; reimburse: Bucket };
    canEditDocDate?: boolean;
}>();
const status = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('bs.advance.index'), { status: status.value || undefined, search: search.value || undefined }, { preserveState: true, replace: true }); }
// #125: simpan tanggal terima dokumen (hanya finance) tanpa berpindah halaman.
function saveDocDate(r: any, e: Event) {
    router.post(route('bs.advance.received-date', r.id), { received_document_date: (e.target as HTMLInputElement).value || null }, { preserveScroll: true, preserveState: false });
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

// #134: tab bon sementara
type TabKey = 'all' | 'realisasi' | 'refund' | 'reimburse';
const tabs: Array<{ key: TabKey; label: string }> = [
    { key: 'all', label: 'Bon Sementara' },
    { key: 'realisasi', label: 'Realisasi Bon Sementara' },
    { key: 'refund', label: 'Refund Bon Sementara' },
    { key: 'reimburse', label: 'Reimburse Bon Sementara' },
];
const activeTab = ref<TabKey>('all');
const tabCount = computed<Record<TabKey, number>>(() => ({
    all: props.advances.total ?? props.advances.data?.length ?? 0,
    realisasi: props.buckets.realisasi.count,
    refund: props.buckets.refund.count,
    reimburse: props.buckets.reimburse.count,
}));
const statusLabel: Record<string, string> = { requested: 'Diajukan', outstanding: 'Outstanding', realized: 'Realized' };
function statusVariant(s: string) { return s === 'realized' ? 'success' : (s === 'requested' ? 'info' : 'warning'); }

/**
 * Kategori = CARA PEMBAYARANNYA (Reguler / Debit VA).
 *
 * Hanya bermakna bila bonnya SUDAH dibayar. payment_method berbawaan 'reguler' sejak bon
 * dibuat, jadi membacanya sendirian akan melabeli bon yang masih "Diajukan" sebagai
 * "Pembayaran Reguler" — padahal belum satu rupiah pun keluar, dan caranya bahkan belum
 * dipilih. Penandanya paid_date: itulah yang terisi saat Finance mencatat pembayaran.
 */
type Kategori = { label: string; nada: string; ket: string };
function kategoriBayar(r: any): Kategori | null {
    if (! r?.paid_date) return null;

    return r.payment_method === 'debit_va'
        ? { label: 'Debit VA', ket: 'Pembayaran Debit VA' + (r.va_expired_date ? ' — expired ' + fmtDate(r.va_expired_date) : ''),
            nada: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-900/25 dark:text-violet-300 dark:ring-violet-800/60' }
        : { label: 'Reguler', ket: 'Pembayaran Reguler (transfer / tunai)',
            nada: 'bg-neutral-100 text-neutral-600 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-700' };
}

/**
 * Cara bon yang SUDAH direalisasi diselesaikan — dibaca dari settlement = nominal − realisasi,
 * tanda yang sama dengan yang dipakai tab Refund/Reimburse di halaman ini, jadi baris dan tab
 * tidak akan pernah menyebut hal yang berbeda untuk bon yang sama.
 *
 *   settlement > 0 → realisasi lebih KECIL → sisanya dikembalikan ke kas  → Refund
 *   settlement < 0 → realisasi lebih BESAR → kekurangannya diganti kas    → Reimburse
 *   settlement = 0 → pengajuan & realisasi sama nilainya                  → Match
 *
 * Hanya berlaku untuk status realized: bon yang belum direalisasi belum punya selisih apa pun.
 */
type Selesai = { key: 'refund' | 'reimburse' | 'match'; label: string; nada: string; ket: string };
function penyelesaian(r: any): Selesai | null {
    if (r?.status !== 'realized') return null;
    const s = Number(r.settlement ?? 0);
    if (s > 0) return { key: 'refund', label: 'Refund', nada: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-900/25 dark:text-sky-300 dark:ring-sky-800/60', ket: 'Sisa ' + fmtRp(s) + ' dikembalikan ke kas' };
    if (s < 0) return { key: 'reimburse', label: 'Reimburse', nada: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/25 dark:text-amber-300 dark:ring-amber-800/60', ket: 'Kekurangan ' + fmtRp(Math.abs(s)) + ' diganti dari kas' };
    return { key: 'match', label: 'Match', nada: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/25 dark:text-emerald-300 dark:ring-emerald-800/60', ket: 'Pengajuan & realisasi sama nilainya' };
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-amber-600" /> Bon Sementara <span class="text-base font-normal text-neutral-400">(Panjar)</span></h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.advance.report')" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><FileBarChart class="h-4 w-4" /> Laporan</Link>
                <Link :href="route('bs.advance.payments')" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-100 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-300"><HandCoins class="h-4 w-4" /> Bayar (Finance)</Link>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Panjar Outstanding</div><div class="mt-1 text-xl font-bold text-amber-600">{{ fmtRp(summary.outstanding) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Bon Belum Direalisasi</div><div class="mt-1 text-xl font-bold">{{ summary.count }}</div></div>
        </div>

        <!-- #134: tab bon sementara / realisasi / refund / reimburse -->
        <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                @click="activeTab = t.key"
                :class="[
                    'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition',
                    activeTab === t.key
                        ? 'border-b-2 border-amber-600 text-amber-700 dark:text-amber-300'
                        : 'text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200',
                ]"
            >
                {{ t.label }}
                <span :class="['rounded-full px-1.5 py-0.5 text-[10px] font-semibold', activeTab === t.key ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800']">{{ tabCount[t.key] }}</span>
            </button>
        </div>

        <!-- TAB: Bon Sementara (semua pengajuan, paginated + filter) -->
        <template v-if="activeTab === 'all'">
            <!-- #87: filter per pemegang/department + status -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-48">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="search" @keyup.enter="applyFilter" placeholder="Cari pemegang / department / keperluan / no…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">Semua status</option><option value="requested">Diajukan (belum dibayar)</option><option value="outstanding">Outstanding</option><option value="realized">Realized</option>
                </select>
            </div>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No.</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Pemegang</th><th class="px-4 py-3">Keperluan</th><th class="px-4 py-3 text-right">Nilai</th><th class="px-4 py-3 text-center">Kategori</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-center">Tgl Terima Dokumen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in advances.data" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.advance.show', r.id))">
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ r.advance_number }}</td>
                            <td class="px-4 py-2.5">{{ fmtDate(r.advance_date) }}</td>
                            <td class="px-4 py-2.5">{{ r.holder }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                            <!-- Kategori = cara pembayarannya. Kosong ("—") selama bonnya belum
                                 dibayar: caranya memang belum ditentukan saat itu. -->
                            <td class="px-4 py-2.5 text-center">
                                <span v-if="kategoriBayar(r)" :title="kategoriBayar(r)!.ket"
                                      class="inline-flex items-center whitespace-nowrap rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold ring-1"
                                      :class="kategoriBayar(r)!.nada">{{ kategoriBayar(r)!.label }}</span>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <div class="flex flex-wrap items-center justify-center gap-1">
                                    <Badge :variant="statusVariant(r.status)" size="sm">{{ statusLabel[r.status] ?? r.status }}</Badge>
                                    <!-- Penanda cara penyelesaian, hanya untuk bon yang sudah direalisasi.
                                         Nominal selisihnya ditaruh di tooltip, bukan di badge: kolomnya sempit
                                         dan yang dicari sekilas adalah JENISnya, bukan angkanya. -->
                                    <span v-if="penyelesaian(r)" :title="penyelesaian(r)!.ket"
                                          class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold ring-1"
                                          :class="penyelesaian(r)!.nada">{{ penyelesaian(r)!.label }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-center" @click.stop>
                                <input v-if="canEditDocDate" type="date" :value="r.received_document_date" @change="saveDocDate(r, $event)" class="h-8 rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                                <span v-else class="text-neutral-500">{{ r.received_document_date ? fmtDate(r.received_document_date) : '—' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-if="!advances.data?.length" title="Belum ada bon sementara" description="Klik Ajukan Bon untuk mencatat panjar operasional." />
            </div>
        </template>

        <!-- TAB: Realisasi / Refund / Reimburse (dari bucket) -->
        <template v-else>
            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">No.</th><th class="px-4 py-3">Tgl Bon</th><th class="px-4 py-3">Tgl Realisasi</th><th class="px-4 py-3">Pemegang</th><th class="px-4 py-3">Keperluan</th><th class="px-4 py-3 text-right">Nilai Bon</th><th class="px-4 py-3 text-right">Realisasi</th>
                        <th v-if="activeTab === 'refund'" class="px-4 py-3 text-right">Sisa (Refund)</th>
                        <th v-else-if="activeTab === 'reimburse'" class="px-4 py-3 text-right">Kekurangan (Reimburse)</th>
                        <th class="px-4 py-3 text-center">Kategori</th>
                        <th class="px-4 py-3 text-center">Tgl Terima Dokumen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in buckets[activeTab].rows" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('bs.advance.show', r.id))">
                            <td class="px-4 py-2.5 font-mono font-semibold">{{ r.advance_number }}</td>
                            <td class="px-4 py-2.5">{{ fmtDate(r.advance_date) }}</td>
                            <td class="px-4 py-2.5">{{ fmtDate(r.realized_date) }}</td>
                            <td class="px-4 py-2.5">{{ r.holder }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ r.purpose }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.amount) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.realized_amount) }}</td>
                            <td v-if="activeTab === 'refund'" class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(r.settlement) }}</td>
                            <td v-else-if="activeTab === 'reimburse'" class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(Math.abs(r.settlement)) }}</td>
                            <td class="px-4 py-2.5 text-center">
                                <span v-if="kategoriBayar(r)" :title="kategoriBayar(r)!.ket"
                                      class="inline-flex items-center whitespace-nowrap rounded-md px-1.5 py-0.5 text-[10.5px] font-semibold ring-1"
                                      :class="kategoriBayar(r)!.nada">{{ kategoriBayar(r)!.label }}</span>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-center" @click.stop>
                                <input v-if="canEditDocDate" type="date" :value="r.received_document_date" @change="saveDocDate(r, $event)" class="h-8 rounded border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                                <span v-else class="text-neutral-500">{{ r.received_document_date ? fmtDate(r.received_document_date) : '—' }}</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="buckets[activeTab].count" class="border-t border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800/50">
                        <tr>
                            <td class="px-4 py-2.5" :colspan="activeTab === 'realisasi' ? 6 : 7">Total ({{ buckets[activeTab].count }} bon)</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(buckets[activeTab].total) }}</td>
                            <!-- Dua sel penutup: Kategori + Tgl Terima Dokumen. Sejak kolom
                                 Kategori ada, baris total kurang satu sel pada SEMUA tab —
                                 kekurangan lebar pada tfoot tidak menimbulkan galat, hanya
                                 membuat garis totalnya berhenti sebelum ujung tabel. -->
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                <EmptyState v-if="!buckets[activeTab].count"
                    :title="activeTab === 'realisasi' ? 'Belum ada realisasi setara' : (activeTab === 'refund' ? 'Belum ada refund' : 'Belum ada reimburse')"
                    :description="activeTab === 'realisasi' ? 'Bon yang nilai realisasinya sama persis dengan pengajuan akan muncul di sini.' : (activeTab === 'refund' ? 'Bon yang realisasinya lebih kecil dari pengajuan (ada sisa) akan muncul di sini.' : 'Bon yang realisasinya lebih besar dari pengajuan (kurang/nombok) akan muncul di sini.')" />
            </div>
        </template>

        <!-- Pagination (hanya tab "Bon Sementara") -->
        <div v-if="activeTab === 'all' && advances.links?.length > 3" class="flex flex-wrap items-center justify-center gap-1">
            <template v-for="(l, i) in advances.links" :key="i">
                <Link v-if="l.url" :href="l.url" preserve-scroll v-html="l.label"
                    :class="['min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm', l.active ? 'border-amber-500 bg-amber-50 font-semibold text-amber-700 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-300' : 'border-neutral-300 bg-white text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900']" />
                <span v-else v-html="l.label" class="min-w-9 rounded-lg border border-neutral-200 px-3 py-1.5 text-center text-sm text-neutral-400 dark:border-neutral-800" />
            </template>
        </div>
    </div>
</template>
