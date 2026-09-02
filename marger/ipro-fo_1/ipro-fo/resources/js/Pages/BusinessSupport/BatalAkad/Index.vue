<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { TriangleAlert, ArrowLeft, FileSpreadsheet, FilterX, Search } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
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

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

/* Nilai TAMPILAN per kolom — satu fungsi dipakai bertiga: merender selnya, menyusun opsi
   filternya, dan mencocokkan filternya. Kalau ketiganya punya versi sendiri-sendiri, sebuah
   nilai bisa tampil "—" di sel tetapi terdaftar sebagai "" di dropdown, dan mencentangnya
   tidak menyaring apa pun. */
const dCust = (r: Row) => r.customer ?? '—';
const dNo = (r: Row) => r.kpr_number ?? '—';
const dUnit = (r: Row) => r.unit ?? '—';
const dBank = (r: Row) => r.bank ?? '—';
const dTgl = (r: Row) => r.batal_at ?? '—';
const dStatus = (r: Row) => (r.status === 'cancelled' ? 'Dibatalkan' : 'Ditolak');
const dTahap = (r: Row) => r.tahap_sebelum ?? '—';
const dAkad = (r: Row) => (r.sempat_akad ? 'Sempat akad' : 'Belum akad');
const dPlafon = (r: Row) => fmtRp(r.plafon);
const dAlasan = (r: Row) => r.alasan ?? '—';

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.rows) s.add(fn(r));

    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optCust = computed(() => distinct(dCust));
const optNo = computed(() => distinct(dNo));
const optUnit = computed(() => distinct(dUnit));
const optBank = computed(() => distinct(dBank));
const optTgl = computed(() => distinct(dTgl));
const optStatus = computed(() => distinct(dStatus));
const optTahap = computed(() => distinct(dTahap));
const optAkad = computed(() => distinct(dAkad));
const optPlafon = computed(() => distinct(dPlafon));
const optAlasan = computed(() => distinct(dAlasan));

// Pencarian ketik (seluruh kolom) + checklist per kolom. Kosong = tampilkan semua.
const search = ref('');
const col = reactive<Record<string, string[]>>({
    cust: [], no: [], unit: [], bank: [], tgl: [], status: [], tahap: [], akad: [], plafon: [], alasan: [],
});
const hasFilter = computed(() => Boolean(search.value) || Object.values(col).some((a) => a.length));
function resetFilter() {
    search.value = '';
    (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = []));
}

const filtered = computed<Row[]>(() => props.rows.filter((r) => {
    if (search.value) {
        const q = search.value.toLowerCase();
        const hay = [dCust(r), dNo(r), dUnit(r), dBank(r), dTgl(r), dStatus(r), dTahap(r), dAkad(r), dPlafon(r), dAlasan(r), r.booking ?? '']
            .join(' ').toLowerCase();
        if (! hay.includes(q)) return false;
    }
    if (col.cust.length && ! col.cust.includes(dCust(r))) return false;
    if (col.no.length && ! col.no.includes(dNo(r))) return false;
    if (col.unit.length && ! col.unit.includes(dUnit(r))) return false;
    if (col.bank.length && ! col.bank.includes(dBank(r))) return false;
    if (col.tgl.length && ! col.tgl.includes(dTgl(r))) return false;
    if (col.status.length && ! col.status.includes(dStatus(r))) return false;
    if (col.tahap.length && ! col.tahap.includes(dTahap(r))) return false;
    if (col.akad.length && ! col.akad.includes(dAkad(r))) return false;
    if (col.plafon.length && ! col.plafon.includes(dPlafon(r))) return false;
    if (col.alasan.length && ! col.alasan.includes(dAlasan(r))) return false;

    return true;
}));

/* Opsi menyusut saat data berubah; centang yang nilainya sudah tidak ada dibuang. Tanpa ini
   sebuah filter bisa tertinggal aktif atas nilai yang tak lagi muncul di kolom mana pun, dan
   tabelnya kosong tanpa satu pun dropdown yang terlihat menyaring. */
watch([optCust, optNo, optUnit, optBank, optTgl, optStatus, optTahap, optAkad, optPlafon, optAlasan], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.cust = prune(col.cust, optCust.value); col.no = prune(col.no, optNo.value);
    col.unit = prune(col.unit, optUnit.value); col.bank = prune(col.bank, optBank.value);
    col.tgl = prune(col.tgl, optTgl.value); col.status = prune(col.status, optStatus.value);
    col.tahap = prune(col.tahap, optTahap.value); col.akad = prune(col.akad, optAkad.value);
    col.plafon = prune(col.plafon, optPlafon.value); col.alasan = prune(col.alasan, optAlasan.value);
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
        if (! peta.has(key)) peta.set(key, { key, nama: dCust(r), rows: [] });
        peta.get(key)!.rows.push(r);
    }

    return [...peta.values()];
});

// ── Centang baris ────────────────────────────────────────────────────────────────────────
const selected = ref<Set<number>>(new Set());
const allFilteredSelected = computed(() => filtered.value.length > 0 && filtered.value.every((r) => selected.value.has(r.id)));
function toggleRow(id: number) { selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id); }
function toggleAll() {
    if (allFilteredSelected.value) filtered.value.forEach((r) => selected.value.delete(r.id));
    else filtered.value.forEach((r) => selected.value.add(r.id));
}
function grupTercentang(g: Grup) { return g.rows.every((r) => selected.value.has(r.id)); }
function toggleGrup(g: Grup) {
    if (grupTercentang(g)) g.rows.forEach((r) => selected.value.delete(r.id));
    else g.rows.forEach((r) => selected.value.add(r.id));
}

/* Ekspor mengirim ID BARIS, bukan salinan filternya.
   Sebelumnya tombol ini mengirim q/status/akad dan sisi server menyalin ulang logika
   penyaringannya. Salinan itu harus ikut berubah setiap kali layarnya bertambah filter —
   dan sekarang filternya ada sepuluh. Dengan mengirim id, berkasnya dijamin berisi persis
   baris yang sedang terlihat, berapa pun filter yang dipakai.
   Tidak ada yang dicentang = seluruh hasil filter, mengikuti kebiasaan Reject Proses KPR. */
function exportExcel() {
    const ids = selected.value.size ? [...selected.value] : filtered.value.map((r) => r.id);
    if (! ids.length) return;
    window.location.href = route('bs.batal-akad.export', { ids: ids.join(',') });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><TriangleAlert class="h-6 w-6 text-danger-600" /> Rincian Batal Akad</h1>
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

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-48 flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" placeholder="Cari semua kolom (ketik)…"
                       class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <button v-if="hasFilter" type="button" @click="resetFilter"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
            <button type="button" :disabled="! filtered.length" @click="exportExcel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700 disabled:cursor-not-allowed disabled:opacity-50">
                <FileSpreadsheet class="h-4 w-4" /> Excel<span v-if="selected.size" class="ml-1 rounded-full bg-white/25 px-1.5 text-xs">{{ selected.size }}</span>
            </button>
        </div>

        <p class="text-xs text-neutral-500">
            {{ filtered.length }} dari {{ rows.length }} KPR<span v-if="selected.size"> · {{ selected.size }} tercentang</span>
        </p>

        <TableTopScroll v-if="rows.length">
            <table class="w-full min-w-[76rem] text-sm">
                <colgroup>
                    <col class="w-10" /><col class="w-[14rem]" /><col class="w-[11rem]" /><col class="w-[10rem]" />
                    <col class="w-[11rem]" /><col class="w-[9rem]" /><col class="w-[8rem]" /><col class="w-[9rem]" />
                    <col class="w-[8rem]" /><col class="w-[9rem]" /><col />
                </colgroup>
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 pt-3 pb-1.5">
                            <input type="checkbox" :checked="allFilteredSelected" @change="toggleAll"
                                   class="h-4 w-4 accent-danger-600" title="Pilih semua (hasil filter)" />
                        </th>
                        <th class="px-3 pt-3 pb-1.5">Customer</th>
                        <th class="whitespace-nowrap px-3 pt-3 pb-1.5">No. KPR</th>
                        <th class="px-3 pt-3 pb-1.5">Unit</th>
                        <th class="px-3 pt-3 pb-1.5">Bank</th>
                        <th class="whitespace-nowrap px-3 pt-3 pb-1.5">Tanggal Batal Akad</th>
                        <th class="px-3 pt-3 pb-1.5">Status</th>
                        <th class="px-3 pt-3 pb-1.5">Tahap Sebelum Batal</th>
                        <th class="px-3 pt-3 pb-1.5">Sempat Akad</th>
                        <th class="px-3 pt-3 pb-1.5 text-right">Plafon</th>
                        <th class="px-3 pt-3 pb-1.5">Keterangan</th>
                    </tr>
                    <!-- Filter checklist per-kolom — urutannya mengikuti kolomnya. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cust" :options="optCust" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.unit" :options="optUnit" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.bank" :options="optBank" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.status" :options="optStatus" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tahap" :options="optTahap" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.akad" :options="optAkad" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.plafon" :options="optPlafon" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.alasan" :options="optAlasan" align="right" /></th>
                    </tr>
                </thead>
                <!-- Satu <tbody> per customer: garis pemisah tebal hanya jatuh ANTAR customer. -->
                <tbody v-for="g in grouped" :key="g.key"
                       class="border-t-2 border-neutral-200 align-top dark:border-neutral-700">
                    <tr v-for="(r, i) in g.rows" :key="r.id"
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40"
                        :class="selected.has(r.id) ? 'bg-danger-50/40 dark:bg-danger-900/10' : ''">
                        <td class="px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">
                            <input type="checkbox" :checked="selected.has(r.id)" @change="toggleRow(r.id)" class="h-4 w-4 accent-danger-600" />
                        </td>

                        <!-- Nama ditulis SEKALI, membentang menaungi seluruh pembatalannya. -->
                        <td v-if="i === 0" :rowspan="g.rows.length"
                            class="bg-neutral-50/60 px-3 py-2 font-medium dark:bg-neutral-800/40">
                            <div class="flex items-start gap-2">
                                <!-- Mencentang seluruh pembatalan satu customer sekaligus — inilah
                                     gunanya dikelompokkan. -->
                                <input type="checkbox" :checked="grupTercentang(g)" @change="toggleGrup(g)"
                                       class="mt-0.5 h-3.5 w-3.5 accent-danger-600"
                                       :title="'Pilih semua ' + g.rows.length + ' pembatalan ' + g.nama" />
                                <div class="min-w-0">
                                    <span class="block break-words">{{ g.nama }}</span>
                                    <span v-if="g.rows.length > 1"
                                          class="mt-1 inline-block rounded-md bg-danger-100 px-1.5 py-0.5 text-[10.5px] font-semibold text-danger-700 dark:bg-danger-900/40 dark:text-danger-300">{{ g.rows.length }}× batal akad</span>
                                    <span v-else class="mt-1 block text-[10.5px] text-neutral-400">1 pembatalan</span>
                                </div>
                            </div>
                        </td>

                        <td class="px-3 py-2 font-mono text-xs font-semibold" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ dNo(r) }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ dUnit(r) }}</td>
                        <td class="whitespace-nowrap px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ dBank(r) }}</td>
                        <td class="px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">
                            <span class="font-medium">{{ dTgl(r) }}</span>
                            <p v-if="r.sempat_akad" class="text-[11px] text-info-600 dark:text-info-400" :title="'Akad sempat terjadi pada ' + r.akad_at">akad {{ r.akad_at }}</p>
                        </td>
                        <td class="px-3 py-2" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><Badge :variant="r.status === 'cancelled' ? 'danger' : 'warning'" size="sm">{{ dStatus(r) }}</Badge></td>
                        <td class="px-3 py-2 text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ dTahap(r) }}</td>
                        <td class="px-3 py-2 text-xs" :class="[i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : '', r.sempat_akad ? 'text-info-600 dark:text-info-400' : 'text-neutral-500']">{{ dAkad(r) }}</td>
                        <td class="px-3 py-2 text-right font-mono" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''">{{ dPlafon(r) }}</td>
                        <td class="px-3 py-2 text-xs text-neutral-500" :class="i > 0 ? 'border-t border-neutral-100 dark:border-neutral-800' : ''"><span class="block max-w-[240px] truncate" :title="r.alasan ?? ''">{{ dAlasan(r) }}</span></td>
                    </tr>
                </tbody>
                <tbody v-if="! filtered.length">
                    <tr>
                        <td colspan="11" class="px-4 py-8 text-center text-sm text-neutral-500">Tidak ada KPR yang cocok dengan filter.</td>
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
