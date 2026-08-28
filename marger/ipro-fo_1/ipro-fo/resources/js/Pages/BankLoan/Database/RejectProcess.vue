<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ShieldX, Search, Printer, FilterX, FileSpreadsheet } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Row = { id: number; number: string; customer_id: number | null; customer: string | null; bank: string | null; unit: string | null; stage: string; reason: string | null; rejected_at: string | null };
const props = defineProps<{ records: Row[] }>();

function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Nilai tampilan per-kolom — dipakai utk render, opsi filter & pencocokan filter agar konsisten.
const dNo = (r: Row) => r.number ?? '';
const dCust = (r: Row) => r.customer ?? '—';
const dBank = (r: Row) => r.bank ?? '—';
const dUnit = (r: Row) => r.unit ?? '—';
const dStage = (r: Row) => r.stage ?? '—';
const dReason = (r: Row) => r.reason ?? '—';
const dTgl = (r: Row) => fmtDate(r.rejected_at);

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.records) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optCust = computed(() => distinct(dCust));
const optBank = computed(() => distinct(dBank));
const optUnit = computed(() => distinct(dUnit));
const optStage = computed(() => distinct(dStage));
const optReason = computed(() => distinct(dReason));
const optTgl = computed(() => distinct(dTgl));

// Pencarian (typing, instan) + checklist per-kolom (kosong = tampilkan semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], cust: [], bank: [], unit: [], stage: [], reason: [], tgl: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

const filtered = computed<Row[]>(() => props.records.filter((r) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(r), dCust(r), dBank(r), dUnit(r), dStage(r), dReason(r), dTgl(r)].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.no.length && !col.no.includes(dNo(r))) return false;
    if (col.cust.length && !col.cust.includes(dCust(r))) return false;
    if (col.bank.length && !col.bank.includes(dBank(r))) return false;
    if (col.unit.length && !col.unit.includes(dUnit(r))) return false;
    if (col.stage.length && !col.stage.includes(dStage(r))) return false;
    if (col.reason.length && !col.reason.includes(dReason(r))) return false;
    if (col.tgl.length && !col.tgl.includes(dTgl(r))) return false;
    return true;
}));

watch([optNo, optCust, optBank, optUnit, optStage, optReason, optTgl], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.cust = prune(col.cust, optCust.value); col.bank = prune(col.bank, optBank.value);
    col.unit = prune(col.unit, optUnit.value); col.stage = prune(col.stage, optStage.value); col.reason = prune(col.reason, optReason.value); col.tgl = prune(col.tgl, optTgl.value);
});

/* Pengajuan digabungkan per CUSTOMER: satu nama berdiri sekali, membentang menaungi seluruh
   penolakannya. Sebelumnya satu customer dengan lima penolakan tampil sebagai lima baris
   yang namanya berulang, dan tidak ada cara melihat bahwa kelimanya orang yang sama.

   Kuncinya customer_id, BUKAN nama. Dua orang berbeda yang kebetulan bernama sama akan
   tergabung jadi satu baris kalau namanya yang dipakai, dan penolakan milik orang lain
   terbaca sebagai miliknya. Baris tanpa customer dikelompokkan sendiri per baris (kunci
   memakai id pengajuannya) supaya tidak saling menempel. */
type Grup = { key: string; nama: string; rows: Row[] };
const grouped = computed<Grup[]>(() => {
    const peta = new Map<string, Grup>();
    for (const r of filtered.value) {
        const key = r.customer_id != null ? 'c' + r.customer_id : 'x' + r.id;
        if (! peta.has(key)) peta.set(key, { key, nama: dCust(r), rows: [] });
        peta.get(key)!.rows.push(r);
    }

    return [...peta.values()];
});

// Pilih baris (massal) — Set id reaktif (Vue 3 melacak mutasi Set).
const selected = ref<Set<number>>(new Set());
const allFilteredSelected = computed(() => filtered.value.length > 0 && filtered.value.every((r) => selected.value.has(r.id)));
function toggleRow(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); }
function toggleAll() {
    if (allFilteredSelected.value) filtered.value.forEach((r) => selected.value.delete(r.id));
    else filtered.value.forEach((r) => selected.value.add(r.id));
}
/** Semua penolakan satu customer tercentang? */
function grupTercentang(g: Grup) { return g.rows.every((r) => selected.value.has(r.id)); }
/** Centang / lepas seluruh penolakan satu customer — inilah gunanya dikelompokkan. */
function toggleGrup(g: Grup) {
    if (grupTercentang(g)) g.rows.forEach((r) => selected.value.delete(r.id));
    else g.rows.forEach((r) => selected.value.add(r.id));
}

function printBulk() {
    if (!selected.value.size) return;
    const url = route('kpr.database.reject-kpr.memo-form', { ids: [...selected.value].join(',') });
    window.open(url, '_blank', 'noopener');
}

// Unduh Excel: baris TERPILIH bila ada centang; selain itu mengikuti hasil FILTER yang tampil.
function downloadExcel() {
    const ids = selected.value.size ? [...selected.value] : filtered.value.map((r) => r.id);
    if (!ids.length) return;
    const param = ids.length === props.records.length ? {} : { ids: ids.join(',') };
    window.open(route('kpr.database.reject-kpr.excel', param), '_blank', 'noopener');
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('kpr.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Proses KPR</Link>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldX class="h-6 w-6 text-danger-600" /> Database KPR — Reject Proses KPR</h1>
                <p class="text-sm text-neutral-500">Proses KPR yang ditolak pada proses Submitted atau SP3K, beserta alasannya.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" :disabled="!filtered.length" @click="downloadExcel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-3 text-sm font-semibold text-white hover:bg-success-700 disabled:cursor-not-allowed disabled:opacity-50"><FileSpreadsheet class="h-4 w-4" /> Download Excel<span v-if="selected.size" class="ml-1 rounded-full bg-white/25 px-1.5 text-xs">{{ selected.size }}</span></button>
                <button type="button" :disabled="!selected.size" @click="printBulk" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-danger-600 px-3 text-sm font-semibold text-white hover:bg-danger-700 disabled:cursor-not-allowed disabled:opacity-50"><Printer class="h-4 w-4" /> Memo Rekom Batal (Massal)<span v-if="selected.size" class="ml-1 rounded-full bg-white/25 px-1.5 text-xs">{{ selected.size }}</span></button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" placeholder="Cari semua kolom (ketik)…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <button v-if="hasFilter" type="button" @click="resetFilter" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <colgroup>
                    <col class="w-10" /><col class="w-[15rem]" /><col class="w-[11rem]" />
                    <col class="w-[12rem]" /><col class="w-[11rem]" /><col class="w-[8rem]" />
                    <col /><col class="w-[8rem]" /><col class="w-[12rem]" />
                </colgroup>
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 pt-3 pb-1.5"><input type="checkbox" :checked="allFilteredSelected" @change="toggleAll" class="h-4 w-4 accent-danger-600" title="Pilih semua (hasil filter)" /></th>
                        <th class="px-3 pt-3 pb-1.5">Customer</th>
                        <th class="whitespace-nowrap px-3 pt-3 pb-1.5">No. KPR</th>
                        <th class="px-3 pt-3 pb-1.5">Bank</th><th class="px-3 pt-3 pb-1.5">Unit</th>
                        <th class="px-3 pt-3 pb-1.5 text-center">Ditolak di Tahap</th>
                        <th class="px-3 pt-3 pb-1.5">Alasan Penolakan</th>
                        <th class="whitespace-nowrap px-3 pt-3 pb-1.5">Tgl Ditolak</th>
                        <th class="px-3 pt-3 pb-1.5 text-center">Aksi</th>
                    </tr>
                    <!-- Filter checklist per-kolom — urutannya mengikuti kolomnya. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cust" :options="optCust" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.bank" :options="optBank" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.unit" :options="optUnit" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.stage" :options="optStage" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.reason" :options="optReason" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"></th>
                    </tr>
                </thead>
                <!-- Satu <tbody> per customer: garis pemisah tebal hanya jatuh ANTAR customer,
                     sehingga batas kelompoknya terbaca tanpa perlu warna selang-seling. -->
                <tbody v-for="g in grouped" :key="g.key"
                       class="border-t-2 border-neutral-200 align-top dark:border-neutral-700">
                    <tr v-for="(r, i) in g.rows" :key="r.id"
                        :class="selected.has(r.id) ? 'bg-danger-50/40 dark:bg-danger-900/10' : ''">
                        <td class="px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">
                            <input type="checkbox" :checked="selected.has(r.id)" @change="toggleRow(r.id)" class="h-4 w-4 accent-danger-600" />
                        </td>

                        <!-- Nama ditulis SEKALI, membentang menaungi seluruh penolakannya. -->
                        <td v-if="i === 0" :rowspan="g.rows.length"
                            class="px-3 py-3 font-medium bg-neutral-50/60 dark:bg-neutral-800/40">
                            <div class="flex items-start gap-2">
                                <!-- Mencentang seluruh penolakan satu customer sekaligus — inilah
                                     gunanya dikelompokkan: Memo Rekom Batal biasanya dibuat untuk
                                     semua pengajuan orang yang sama. -->
                                <input type="checkbox" :checked="grupTercentang(g)" @change="toggleGrup(g)"
                                       class="mt-0.5 h-3.5 w-3.5 accent-danger-600"
                                       :title="'Pilih semua ' + g.rows.length + ' penolakan ' + g.nama" />
                                <div class="min-w-0">
                                    <span class="block break-words">{{ g.nama }}</span>
                                    <!-- Jumlahnya disebut supaya yang berulang langsung terlihat,
                                         bukan harus dihitung sendiri dari tinggi barisnya. -->
                                    <span v-if="g.rows.length > 1"
                                          class="mt-1 inline-block rounded-md bg-danger-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ g.rows.length }}× ditolak</span>
                                    <span v-else class="mt-1 block text-[10.5px] text-neutral-400">1 penolakan</span>
                                </div>
                            </div>
                        </td>

                        <td class="whitespace-nowrap px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><Link :href="route('kpr.show', r.id)" class="font-mono text-xs text-primary-700 hover:underline dark:text-primary-300">{{ r.number }}</Link></td>
                        <td class="px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ r.bank ?? '—' }}</td>
                        <td class="px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ r.unit ?? '—' }}</td>
                        <td class="px-3 py-3 text-center" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><Badge size="sm" :variant="r.stage === 'SP3K' ? 'info' : 'warning'">{{ r.stage }}</Badge></td>
                        <td class="px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><span v-if="r.reason" class="text-neutral-700 dark:text-neutral-200">{{ r.reason }}</span><span v-else class="text-xs italic text-neutral-400">— tanpa alasan —</span></td>
                        <td class="whitespace-nowrap px-3 py-3" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ fmtDate(r.rejected_at) }}</td>
                        <td class="px-3 py-3 text-center whitespace-nowrap" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">
                            <a :href="route('kpr.database.reject-kpr.memo-form', { ids: r.id })" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 bg-danger-50 px-3 py-1.5 text-xs font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-3.5 w-3.5" /> Memo Rekom Batal</a>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.records.length" title="Belum ada proses KPR yang ditolak" description="Proses KPR yang Anda tolak pada proses Submitted/SP3K akan muncul di sini beserta alasannya." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada proses KPR yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
