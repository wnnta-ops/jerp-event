<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ShieldX, Search, FileText, FileSpreadsheet, FilterX, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';

type Row = { id: number; number: string; customer: string | null; tts: string | null; unit: string | null; bi_result: string | null; bi_result_pasangan: string | null; reject_type: string | null; reason: string | null; rejected_at: string | null; rejected_by: string | null };
const props = defineProps<{ records: Row[] }>();

function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const biMap: Record<string, string> = { clear: 'Clear', kol1: 'Kol 1', kol2: 'Kol 2', kol3: 'Kol 3', kol4: 'Kol 4', kol5: 'Kol 5', caution: 'Perlu Perhatian', reject: 'Ditolak' };
function biLabel(r: string | null) { return r ? (biMap[r] ?? r) : '—'; }
function typeLabel(t: string | null) { return t === 'batal_sepihak' ? 'Reject Batal Sepihak' : 'Reject BI Checking'; }

// Nilai tampilan per-kolom — dipakai utk render, opsi filter & pencocokan filter agar konsisten.
const dNo = (r: Row) => r.number ?? '';
const dCust = (r: Row) => r.customer ?? '—';
const dTts = (r: Row) => r.tts ?? '—';
const dUnit = (r: Row) => r.unit ?? '—';
const dBi = (r: Row) => biLabel(r.bi_result);
const dType = (r: Row) => typeLabel(r.reject_type);
const dReason = (r: Row) => r.reason ?? '—';
const dTgl = (r: Row) => fmtDate(r.rejected_at);

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.records) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optNo = computed(() => distinct(dNo));
const optCust = computed(() => distinct(dCust));
const optTts = computed(() => distinct(dTts));
const optUnit = computed(() => distinct(dUnit));
const optBi = computed(() => distinct(dBi));
const optType = computed(() => distinct(dType));
const optReason = computed(() => distinct(dReason));
const optTgl = computed(() => distinct(dTgl));

// Pencarian (typing, instan) + checklist per-kolom (kosong = tampilkan semua). Semua di sisi klien.
const search = ref('');
const col = reactive<Record<string, string[]>>({ no: [], cust: [], tts: [], unit: [], bi: [], type: [], reason: [], tgl: [] });
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() { search.value = ''; (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = [])); }

const filtered = computed<Row[]>(() => props.records.filter((r) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dNo(r), dCust(r), dTts(r), dUnit(r), dBi(r), dType(r), dReason(r), dTgl(r), r.rejected_by ?? ''].join(' ').toLowerCase();
        if (!hay.includes(q)) return false;
    }
    if (col.no.length && !col.no.includes(dNo(r))) return false;
    if (col.cust.length && !col.cust.includes(dCust(r))) return false;
    if (col.tts.length && !col.tts.includes(dTts(r))) return false;
    if (col.unit.length && !col.unit.includes(dUnit(r))) return false;
    if (col.bi.length && !col.bi.includes(dBi(r))) return false;
    if (col.type.length && !col.type.includes(dType(r))) return false;
    if (col.reason.length && !col.reason.includes(dReason(r))) return false;
    if (col.tgl.length && !col.tgl.includes(dTgl(r))) return false;
    return true;
}));

watch([optNo, optCust, optTts, optUnit, optBi, optType, optReason, optTgl], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.no = prune(col.no, optNo.value); col.cust = prune(col.cust, optCust.value); col.tts = prune(col.tts, optTts.value);
    col.unit = prune(col.unit, optUnit.value); col.bi = prune(col.bi, optBi.value); col.type = prune(col.type, optType.value); col.reason = prune(col.reason, optReason.value); col.tgl = prune(col.tgl, optTgl.value);
});

// Pilih baris (checklist) — Set id reaktif.
const selected = ref<Set<number>>(new Set());
const allFilteredSelected = computed(() => filtered.value.length > 0 && filtered.value.every((r) => selected.value.has(r.id)));
function toggleRow(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); }
function toggleAll() {
    if (allFilteredSelected.value) filtered.value.forEach((r) => selected.value.delete(r.id));
    else filtered.value.forEach((r) => selected.value.add(r.id));
}

/* Memo Rekom Batal MASSAL — satu memo untuk seluruh baris tercentang.
   Rute & perilakunya sama persis dengan Reject Proses KPR: tab baru, dan tombol per-baris
   memanggil rute yang SAMA dengan satu id. Satu jalur, jadi hasil massal dan hasil per-baris
   tidak mungkin perlahan berbeda. */
function printBulk() {
    if (!selected.value.size) return;
    const url = route('kpr.database.reject-bi.memo-form', { ids: [...selected.value].join(',') });
    window.open(url, '_blank', 'noopener');
}

// Unduh Excel: baris TERPILIH bila ada centang; selain itu mengikuti hasil FILTER yang tampil.
function downloadExcel() {
    const ids = selected.value.size ? [...selected.value] : filtered.value.map((r) => r.id);
    if (!ids.length) return;
    const param = ids.length === props.records.length ? {} : { ids: ids.join(',') };
    window.open(route('kpr.database.reject-bi.excel', param), '_blank', 'noopener');
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('kpr.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Proses KPR</Link>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldX class="h-6 w-6 text-danger-600" /> Database KPR — Reject BI Checking</h1>
                <p class="text-sm text-neutral-500">Inisiasi KPR yang ditolak pada tahap BI Checking, beserta alasannya.</p>
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
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 pt-3 pb-1.5"><input type="checkbox" :checked="allFilteredSelected" @change="toggleAll" class="h-4 w-4 accent-danger-600" title="Pilih semua (hasil filter)" /></th>
                        <th class="whitespace-nowrap px-3 pt-3 pb-1.5">No. Inisiasi</th><th class="px-3 pt-3 pb-1.5">Customer</th><th class="px-3 pt-3 pb-1.5">TTS</th><th class="px-3 pt-3 pb-1.5">Unit</th><th class="px-3 pt-3 pb-1.5 text-center">Hasil BI</th><th class="px-3 pt-3 pb-1.5">Jenis Reject</th><th class="px-3 pt-3 pb-1.5">Alasan Penolakan</th><th class="whitespace-nowrap px-3 pt-3 pb-1.5">Ditolak</th><th class="px-3 pt-3 pb-1.5 text-center">Detail</th>
                    </tr>
                    <!-- Filter checklist per-kolom -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cust" :options="optCust" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tts" :options="optTts" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.unit" :options="optUnit" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.bi" :options="optBi" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.type" :options="optType" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.reason" :options="optReason" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800 align-top">
                    <tr v-for="r in filtered" :key="r.id" :class="selected.has(r.id) ? 'bg-danger-50/40 dark:bg-danger-900/10' : ''">
                        <td class="px-3 py-3"><input type="checkbox" :checked="selected.has(r.id)" @change="toggleRow(r.id)" class="h-4 w-4 accent-danger-600" /></td>
                        <td class="whitespace-nowrap px-3 py-3"><Link :href="route('kpr.database.reject-bi.detail', r.id)" class="font-mono text-xs text-primary-700 hover:underline dark:text-primary-300">{{ r.number }}</Link></td>
                        <td class="px-3 py-3 font-medium">{{ r.customer ?? '—' }}</td>
                        <td class="px-3 py-3 font-mono text-xs text-neutral-500">{{ r.tts ?? '—' }}</td>
                        <td class="px-3 py-3">{{ r.unit ?? '—' }}</td>
                        <td class="px-3 py-3 text-center"><Badge size="sm" :variant="r.bi_result ? 'warning' : 'neutral'">{{ biLabel(r.bi_result) }}</Badge></td>
                        <td class="px-3 py-3 whitespace-nowrap"><Badge size="sm" :variant="r.reject_type === 'batal_sepihak' ? 'danger' : 'warning'">{{ typeLabel(r.reject_type) }}</Badge></td>
                        <td class="px-3 py-3"><span v-if="r.reason" class="text-neutral-700 dark:text-neutral-200">{{ r.reason }}</span><span v-else class="text-xs italic text-neutral-400">— tanpa alasan —</span></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ fmtDate(r.rejected_at) }}<div v-if="r.rejected_by" class="text-xs text-neutral-400">oleh {{ r.rejected_by }}</div></td>
                        <td class="px-3 py-3 text-center whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5">
                                <Link :href="route('kpr.database.reject-bi.detail', r.id)" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><FileText class="h-3.5 w-3.5" /> Report</Link>
                                <!-- Tab baru, bukan Inertia visit: tujuannya lembar cetak, dan daftar ini biasa
                                     dipakai mencetak beberapa berkas berturut-turut tanpa kehilangan filternya.

                                     Dulu tombol ini membuka "Berita Acara Reject BI Checking" (lembar A4
                                     kpr.database.reject-bi.cetak). Lembar itu TIDAK dihapus — ia tetap
                                     terjangkau dari tombol Cetak di halaman Report/detail inisiasi. Yang
                                     berubah hanya tombol di daftar ini, supaya alurnya sama dengan Reject
                                     Proses KPR: dari daftar langsung ke memo pembatalannya. -->
                                <a :href="route('kpr.database.reject-bi.memo-form', { ids: r.id })" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-danger-300 bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 hover:bg-danger-100 dark:border-danger-700/50 dark:bg-danger-900/30 dark:text-danger-300"><Printer class="h-3.5 w-3.5" /> Memo Rekom Batal</a>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!props.records.length" title="Belum ada inisiasi yang ditolak" description="Inisiasi KPR yang Anda tolak pada tahap BI Checking akan muncul di sini beserta alasannya." />
            <EmptyState v-else-if="!filtered.length" title="Tidak ada hasil" description="Tidak ada inisiasi yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
        </div>
    </div>
</template>
