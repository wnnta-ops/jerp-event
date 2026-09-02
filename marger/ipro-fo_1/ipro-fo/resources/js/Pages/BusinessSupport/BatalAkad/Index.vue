<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { TriangleAlert, RotateCcw, ArrowLeft, FileSpreadsheet } from 'lucide-vue-next';
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

/* `status` tidak lagi ada di sini — penyaringan status kini lewat checklist kolom Status
   (col.stat), sejajar dengan delapan kolom lainnya. Menyisakan dua tempat untuk menyaring
   hal yang sama hanya membuat keduanya bisa berselisih. */
const f = ref({ q: '', akad: '' });

function fmtRpNum(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

/* Nilai TAMPILAN per kolom. Satu fungsi dipakai bertiga — untuk menggambar sel, menyusun
   daftar pilihan filter, dan mencocokkan filter — supaya yang dicentang orang selalu sama
   persis dengan yang terbaca di layar. Kalau ketiganya dihitung sendiri-sendiri, kolom
   berformat (tanggal, rupiah, badge status) akan punya pilihan yang tak pernah cocok. */
const dCust  = (r: Row) => r.customer ?? '—';
const dNo    = (r: Row) => r.kpr_number ?? '—';
const dUnit  = (r: Row) => r.unit ?? '—';
const dBank  = (r: Row) => r.bank ?? '—';
const dTgl   = (r: Row) => r.batal_at ?? '—';
const dStat  = (r: Row) => (r.status === 'cancelled' ? 'Dibatalkan' : 'Ditolak');
const dTahap = (r: Row) => r.tahap_sebelum ?? '—';
const dPlaf  = (r: Row) => fmtRpNum(r.plafon);
const dKet   = (r: Row) => r.alasan ?? '—';

function distinct(fn: (r: Row) => string) {
    const s = new Set<string>();
    for (const r of props.rows) s.add(fn(r));
    return [...s].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
}
const optCust  = computed(() => distinct(dCust));
const optNo    = computed(() => distinct(dNo));
const optUnit  = computed(() => distinct(dUnit));
const optBank  = computed(() => distinct(dBank));
const optTgl   = computed(() => distinct(dTgl));
const optStat  = computed(() => distinct(dStat));
const optTahap = computed(() => distinct(dTahap));
const optPlaf  = computed(() => distinct(dPlaf));
const optKet   = computed(() => distinct(dKet));

/* Checklist per kolom — kosong berarti tampilkan semua, sama seperti Reject Proses KPR. */
const col = reactive<Record<string, string[]>>({
    cust: [], no: [], unit: [], bank: [], tgl: [], stat: [], tahap: [], plaf: [], ket: [],
});
const adaFilterKolom = computed(() => Object.values(col).some((a) => a.length));

function resetFilter() {
    f.value = { q: '', akad: '' };
    (Object.keys(col) as Array<keyof typeof col>).forEach((k) => (col[k] = []));
}

/* Pilihan yang sudah tidak ada lagi setelah data berubah ikut dibuang dari centangan.
   Tanpa ini sebuah centangan bisa tertinggal pada nilai yang tak lagi ada, dan tabelnya
   kosong tanpa sebab yang terlihat. */
watch([optCust, optNo, optUnit, optBank, optTgl, optStat, optTahap, optPlaf, optKet], () => {
    const prune = (sel: string[], opts: string[]) => { const s = new Set(opts); return sel.filter((v) => s.has(v)); };
    col.cust = prune(col.cust, optCust.value);
    col.no = prune(col.no, optNo.value);
    col.unit = prune(col.unit, optUnit.value);
    col.bank = prune(col.bank, optBank.value);
    col.tgl = prune(col.tgl, optTgl.value);
    col.stat = prune(col.stat, optStat.value);
    col.tahap = prune(col.tahap, optTahap.value);
    col.plaf = prune(col.plaf, optPlaf.value);
    col.ket = prune(col.ket, optKet.value);
});

/* Saringan halaman ini murni di sisi peramban dan tidak pernah masuk URL, jadi tombol
   ekspornya yang mengirimkannya. Tanpa itu berkasnya berisi lebih banyak baris daripada
   yang sedang dilihat operator.

   BATASNYA, dan itu DIKATAKAN di layar (lihat peringatan di bawah tombol Excel): checklist
   per kolom TIDAK ikut terkirim — endpoint ekspornya hanya mengenal q & akad. Yang dipilih
   lewat checklist karena itu tetap ikut terekspor. Membuatnya ikut berarti mengubah
   controller ekspor, dan itu di luar cakupan perubahan tampilan ini; yang tidak boleh
   adalah membiarkan selisihnya tidak terlihat. */
function exportExcel() {
    window.location.href = route('bs.batal-akad.export', {
        q: f.value.q || undefined,
        akad: f.value.akad || undefined,
    });
}
const filtered = computed(() => {
    const s = f.value.q.trim().toLowerCase();
    return props.rows.filter((r) => {
        if (f.value.akad === 'ya' && ! r.sempat_akad) return false;
        if (f.value.akad === 'tidak' && r.sempat_akad) return false;

        // Checklist per kolom — dicocokkan pada NILAI TAMPILAN, bukan nilai mentahnya.
        if (col.cust.length && ! col.cust.includes(dCust(r))) return false;
        if (col.no.length && ! col.no.includes(dNo(r))) return false;
        if (col.unit.length && ! col.unit.includes(dUnit(r))) return false;
        if (col.bank.length && ! col.bank.includes(dBank(r))) return false;
        if (col.tgl.length && ! col.tgl.includes(dTgl(r))) return false;
        if (col.stat.length && ! col.stat.includes(dStat(r))) return false;
        if (col.tahap.length && ! col.tahap.includes(dTahap(r))) return false;
        if (col.plaf.length && ! col.plaf.includes(dPlaf(r))) return false;
        if (col.ket.length && ! col.ket.includes(dKet(r))) return false;

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
            <!-- Selisih antara layar dan berkas DIKATAKAN, bukan didiamkan: berkas yang berisi
                 lebih banyak baris daripada yang terlihat akan dikira salah datanya. -->
            <p v-if="adaFilterKolom" class="mt-1 text-[11px] text-warning-600 dark:text-warning-400">
                Centangan kolom belum ikut ke Excel — berkasnya mengikuti kotak pencarian &amp; saringan akad saja,
                jadi barisnya bisa lebih banyak daripada yang tampil di sini.
            </p>
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
                    <!-- Pencarian bebas tetap dipertahankan di barisnya sendiri: checklist hanya
                         bisa memilih nilai yang SUDAH ada, sedangkan mengetik potongan kata masih
                         cara tercepat menemukan satu baris di antara ratusan. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th colspan="4" class="px-3 py-1.5"><input v-model="f.q" type="text" placeholder="Cari No. KPR / customer / unit / bank…" :class="fCls" /></th>
                        <th class="px-3 py-1.5">
                            <select v-model="f.akad" :class="fCls">
                                <option value="">Semua akad</option>
                                <option value="ya">Sempat akad</option>
                                <option value="tidak">Belum akad</option>
                            </select>
                        </th>
                        <th colspan="4"></th>
                    </tr>
                    <!-- Checklist per kolom ala Excel AutoFilter — pola yang sama dengan Reject
                         Proses KPR, memakai komponen ColumnFilter yang sama.

                         Kolom Customer di-rowspan (satu customer menaungi semua pembatalannya),
                         jadi mencentang seorang customer menyaring SELURUH pembatalan miliknya,
                         bukan satu baris saja. Itu memang yang dicari saat menelusuri per orang. -->
                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.cust" :options="optCust" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.no" :options="optNo" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.unit" :options="optUnit" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.bank" :options="optBank" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tgl" :options="optTgl" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.stat" :options="optStat" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.tahap" :options="optTahap" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.plaf" :options="optPlaf" align="right" /></th>
                        <th class="px-3 pb-2"><ColumnFilter v-model="col.ket" :options="optKet" align="right" /></th>
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
