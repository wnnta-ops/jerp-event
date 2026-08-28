<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Wallet, Search, FilterX, ArrowRightLeft, BookText, TriangleAlert } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';
import ColumnFilter from '@/Components/UI/ColumnFilter.vue';
import SortableTh from '@/Components/UI/SortableTh.vue';
import { useTableColumns } from '@/Composables/useTableColumns';

type Row = {
    row_key: string; source_type: string; source_label: string; source_id: number;
    booking_id: number | null; customer_id: number | null; customer: string | null;
    spr: string | null; unit: string | null; doc_date: string | null;
    journal_id: number | null; journal_number: string | null; kpr_number: string | null; note: string | null;
    dpp: number; ppn: number; ppn_pasti: boolean; total: number;
    // Nilai bersih baris ini pada akun Uang Muka di buku besar. gl_ada = false berarti
    // jurnalnya sama sekali tidak menyentuh akun itu.
    gl_net: number | null; gl_ada: boolean;
};
type GlRow = {
    line_id: number; journal_id: number; journal_number: string | null; journal_date: string | null;
    journal_status: string; status_label: string; journal_source: string; reference: string | null;
    account_code: string | null; account_name: string | null; description: string | null;
    debit: number; credit: number; net: number; arah: 'D' | 'K';
    doc_type: string | null; doc_label: string | null; doc_number: string | null;
    customer_id: number | null; customer: string | null; spr: string | null; unit: string | null;
    queue_key: string | null;
};
const props = defineProps<{
    rows: Row[];
    summary: { count: number; total: number };
    gl: {
        accounts: Array<{ id: number; code: string; name: string }>;
        rows: GlRow[];
        periode: { from: string | null; to: string | null };
        filters: { date_from: string | null; date_to: string | null };
        tab: 'antrean' | 'buku';
    };
    rekon: {
        gl_kredit: number; gl_debit: number; gl_saldo: number; gl_belum_efektif: number;
        antrean_total: number; antrean_count: number; ppn_antrean: number;
        tanpa_gl_count: number; tanpa_gl_total: number;
        lawan_count: number; lawan_total: number; lawan_gl: number;
        yatim_count: number; yatim_net: number;
        kolom_hilang: string[];
    };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// ── Tautan ke Detail Transaksi Jurnal ──
// Layar itu berbawaan "bulan berjalan". Tanpa membawa rentang tanggal yang SEBENARNYA, tautan
// ini akan mendarat di layar kosong untuk baris mana pun di luar bulan ini — dan itu terbaca
// sebagai "jurnalnya tidak ada", bukan sebagai "tanggalnya tersaring".
const akunUm = computed(() => props.gl?.accounts ?? []);
function linkAkun(accountId?: number) {
    const p: Record<string, any> = {};
    const id = accountId ?? akunUm.value[0]?.id;
    if (id) p.f_account = id;
    if (props.gl?.periode?.from) p.date_from = props.gl.periode.from;
    if (props.gl?.periode?.to) p.date_to = props.gl.periode.to;

    return route('bs.gl.journal.detail', p);
}

// ── Tab ──
// Dua daftar dengan makna berbeda, jadi tidak digabung jadi satu tabel: antrean adalah
// pekerjaan yang menunggu, buku besar adalah catatan akuntansinya. Yang disamakan adalah
// RINCIANNYA — tab Buku Besar berisi persis baris yang tampil di Detail Transaksi Jurnal.
//
// Tab awal datang dari server (?tab=buku) supaya bisa ditaut langsung — tanpa itu, tautan
// ke tab Buku Besar selalu mendarat di tab Antrean dan orang harus mengklik lagi.
const tab = ref<'antrean' | 'buku'>(props.gl?.tab === 'buku' ? 'buku' : 'antrean');

// ── Tabel antrean ──
const semua = computed(() => props.rows ?? []);
const T = useTableColumns<Row>(semua, [
    { key: 'sumber',   value: (r) => r.source_label ?? '' },
    { key: 'tgl',      value: (r) => fmtDate(r.doc_date), sort: (r) => r.doc_date ?? '' },
    { key: 'customer', value: (r) => r.customer ?? '—' },
    { key: 'spr',      value: (r) => r.spr ?? '—' },
    { key: 'unit',     value: (r) => r.unit ?? '—' },
    { key: 'ket',      value: (r) => r.note ?? '—' },
    { key: 'jurnal',   value: (r) => r.journal_number ?? '—' },
    { key: 'dpp',      value: (r) => fmtRp(r.dpp),   sort: (r) => Number(r.dpp) || 0 },
    { key: 'ppn',      value: (r) => fmtRp(r.ppn),   sort: (r) => Number(r.ppn) || 0 },
    { key: 'total',    value: (r) => fmtRp(r.total), sort: (r) => Number(r.total) || 0 },
]);
const rows = T.result;

// ── Tabel buku besar ──
const semuaGl = computed(() => props.gl?.rows ?? []);
const G = useTableColumns<GlRow>(semuaGl, [
    { key: 'tgl',      value: (r) => fmtDate(r.journal_date), sort: (r) => r.journal_date ?? '' },
    { key: 'jurnal',   value: (r) => r.journal_number ?? '—' },
    { key: 'status',   value: (r) => r.status_label ?? '—' },
    { key: 'akun',     value: (r) => (r.account_code ?? '') + ' ' + (r.account_name ?? '') },
    { key: 'dok',      value: (r) => r.doc_label ?? '—' },
    { key: 'customer', value: (r) => r.customer ?? '—' },
    { key: 'ket',      value: (r) => r.description ?? '—' },
    { key: 'debit',    value: (r) => fmtRp(r.debit),  sort: (r) => Number(r.debit) || 0 },
    { key: 'kredit',   value: (r) => fmtRp(r.credit), sort: (r) => Number(r.credit) || 0 },
]);
const glRows = G.result;

// ── Pilih massal untuk kompensasi ──
// Kunci baris memakai row_key (mis. "retensi-12"), BUKAN id: ketiga sumber punya id sendiri-sendiri
// sehingga id 12 bisa muncul di lebih dari satu sumber.
const selected = ref<string[]>([]);
// Hanya baris yang SEDANG TAMPIL yang ikut "pilih semua" — kalau memakai seluruh data,
// mencentang kotak kepala akan diam-diam ikut memilih baris yang tersaring keluar.
const pageKeys = computed<string[]>(() => rows.value.map((r) => r.row_key));
const allChecked = computed<boolean>({
    get: () => pageKeys.value.length > 0 && pageKeys.value.every((k) => selected.value.includes(k)),
    set: (v: boolean) => { selected.value = v ? [...pageKeys.value] : []; },
});
const selectedTotal = computed(() => semua.value.filter((r) => selected.value.includes(r.row_key)).reduce((s, r) => s + Number(r.total || 0), 0));
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';

const submitting = ref(false);
function kompensasi() {
    if (!selected.value.length || submitting.value) return;
    submitting.value = true;
    router.post(route('bs.ar.um.compensate'), { keys: selected.value }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = []; },
        onFinish: () => { submitting.value = false; },
    });
}

const sourceVariant: Record<string, string> = { retensi: 'warning', pencairan: 'info', utj_dp: 'success' };
const statusVariant: Record<string, string> = { posted: 'success', final: 'info', draft: 'neutral' };

// Selisih antara antrean dan saldo buku besar. Ditampilkan apa adanya, termasuk saat nol.
const selisih = computed(() => Math.round(((props.rekon?.antrean_total ?? 0) - (props.rekon?.gl_saldo ?? 0)) * 100) / 100);
const linkCls = 'font-mono text-xs text-primary-600 hover:underline dark:text-primary-400';
</script>

<template>
    <div class="space-y-4">
        <div>
            <Link :href="route('bs.ar.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Invoice Piutang</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-primary-600" /> UM Penjualan</h1>
            <p class="text-sm text-neutral-500">
                Uang muka penjualan yang <strong>sudah disetujui &amp; diposting</strong>, berasal dari
                <strong>Approval Retensi</strong> (DAJAM &amp; Pencairan KPR) dan <strong>Approval UM Penjualan</strong> (UTJ/DP).
                Centang barisnya lalu klik <strong>Kompensasi dengan AR</strong> — baris akan berpindah ke
                <strong>Rekonsiliasi Piutang</strong> sebagai pengurang tagihan customer.
            </p>
            <p class="text-sm text-neutral-500">Kompensasi di sini <strong>tidak memposting jurnal</strong>; jurnal kompensasi tetap dibuat terpisah oleh Accounting.</p>
            <p v-if="akunUm.length" class="mt-1 text-sm text-neutral-500">
                Terhubung ke buku besar akun
                <template v-for="(a, i) in akunUm" :key="a.id"><span v-if="i"> · </span><a :href="linkAkun(a.id)" :class="linkCls">{{ a.code }} — {{ a.name }}</a></template>
                — tab <button type="button" class="font-semibold text-primary-600 hover:underline dark:text-primary-400" @click="tab = 'buku'">Buku Besar</button> berisi persis baris yang tampil di Detail Transaksi Jurnal untuk akun tersebut.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Belum Dikompensasi</p><p class="mt-1 text-2xl font-bold">{{ summary.count }}<span class="ml-1 text-sm font-normal text-neutral-400">baris</span></p></div>
            <div class="card p-4"><p class="text-xs uppercase text-neutral-400">Total Nilai UM</p><p class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(summary.total) }}</p></div>
            <div class="card p-4">
                <p class="text-xs uppercase text-neutral-400">Saldo Buku Besar Akun UM</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ fmtRp(rekon.gl_saldo) }}</p>
                <p class="text-[11px] text-neutral-400">{{ gl.rows.length }} baris jurnal · kredit {{ fmtRp(rekon.gl_kredit) }} − debit {{ fmtRp(rekon.gl_debit) }}</p>
            </div>
        </div>

        <!-- Jembatan antara kedua angka. Tanpa ini keduanya hanya "berbeda", dan tidak ada cara
             membedakan selisih yang wajar dari salah posting. -->
        <details class="card p-4" :open="selisih !== 0">
            <summary class="cursor-pointer text-sm font-semibold">
                Jembatan antrean ↔ buku besar
                <span class="ml-1 font-normal text-neutral-500">— selisih <b class="tabular-nums" :class="selisih === 0 ? 'text-success-600' : 'text-warning-600'">{{ fmtRp(selisih) }}</b></span>
            </summary>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr><td class="py-1.5 pr-4">Antrean menunggu kompensasi ({{ rekon.antrean_count }} baris)</td><td class="py-1.5 text-right font-mono tabular-nums">{{ fmtRp(rekon.antrean_total) }}</td></tr>
                        <tr><td class="py-1.5 pr-4">Saldo akun Uang Muka di buku besar</td><td class="py-1.5 text-right font-mono tabular-nums">{{ fmtRp(rekon.gl_saldo) }}</td></tr>
                        <tr class="font-semibold"><td class="py-1.5 pr-4">Selisih</td><td class="py-1.5 text-right font-mono tabular-nums">{{ fmtRp(selisih) }}</td></tr>
                    </tbody>
                </table>
                <p class="mt-3 text-xs font-semibold uppercase text-neutral-400">Penyebab yang sudah teridentifikasi</p>
                <ul class="mt-1 space-y-1 text-xs text-neutral-500">
                    <li>
                        <b class="tabular-nums">{{ fmtRp(rekon.ppn_antrean) }}</b> — PPN pada nilai antrean.
                        Nilai antrean adalah total termasuk PPN, sedangkan yang masuk akun Uang Muka hanya DPP-nya; PPN-nya duduk di akun PPN Keluaran.
                    </li>
                    <li v-if="rekon.tanpa_gl_count">
                        <b>{{ rekon.tanpa_gl_count }} baris</b> antrean senilai <b class="tabular-nums">{{ fmtRp(rekon.tanpa_gl_total) }}</b> yang jurnalnya
                        <b>tidak menyentuh akun Uang Muka sama sekali</b> — umumnya karena unitnya sudah Booked Revenue sehingga kreditnya diarahkan ke Piutang Usaha.
                    </li>
                    <li v-if="rekon.lawan_count">
                        <b>{{ rekon.lawan_count }} baris</b> antrean senilai <b class="tabular-nums">{{ fmtRp(rekon.lawan_total) }}</b> yang jurnalnya justru
                        <b>mengurangi</b> akun Uang Muka sebesar <b class="tabular-nums">{{ fmtRp(Math.abs(rekon.lawan_gl)) }}</b> (didebit) — lazim pada retensi DAJAM yang uangnya ditahan.
                        Arahnya berlawanan dengan nilai antrean, jadi selisihnya kira-kira dua kali nilai itu.
                    </li>
                    <li v-if="rekon.yatim_count">
                        <b>{{ rekon.yatim_count }} baris</b> buku besar bernilai bersih <b class="tabular-nums">{{ fmtRp(rekon.yatim_net) }}</b> yang
                        <b>tidak punya wakil di antrean</b> — mis. pembalikan uang muka saat Booked Revenue, top-up kas kecil, atau jurnal memorial manual.
                        Semuanya tetap tampil di tab Buku Besar.
                    </li>
                    <li v-if="rekon.gl_belum_efektif">
                        <b class="tabular-nums">{{ fmtRp(rekon.gl_belum_efektif) }}</b> di antaranya berasal dari jurnal yang <b>belum Approved</b>, jadi belum efektif di buku besar.
                    </li>
                </ul>
                <p v-if="rekon.kolom_hilang.length" class="mt-3 flex items-start gap-1.5 text-xs text-danger-600 dark:text-danger-400">
                    <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span>Penaut dokumen tidak lengkap — kolom berikut tidak ada di basis data: <b>{{ rekon.kolom_hilang.join(', ') }}</b>. Sebagian baris buku besar akan kehilangan nomor dokumen &amp; customernya.</span>
                </p>
            </div>
        </details>

        <div class="flex flex-wrap gap-2 border-b border-neutral-200 dark:border-neutral-800">
            <button type="button" @click="tab = 'antrean'"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                    :class="tab === 'antrean' ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                Antrean Kompensasi <span class="ml-1 text-xs text-neutral-400">{{ semua.length }}</span>
            </button>
            <button type="button" @click="tab = 'buku'"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                    :class="tab === 'buku' ? 'border-primary-600 text-primary-700 dark:text-primary-300' : 'border-transparent text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200'">
                Buku Besar Akun UM <span class="ml-1 text-xs text-neutral-400">{{ semuaGl.length }}</span>
            </button>
        </div>

        <!-- ══ TAB ANTREAN ══ -->
        <template v-if="tab === 'antrean'">
            <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
                <span class="text-sm"><b>{{ selected.length }}</b> baris dipilih · <b class="tabular-nums">{{ fmtRp(selectedTotal) }}</b></span>
                <div class="ml-auto flex flex-wrap gap-2">
                    <button type="button" @click="kompensasi" :disabled="submitting"
                            title="Tandai baris terpilih sebagai dikompensasi dengan piutang — baris pindah ke Rekonsiliasi Piutang."
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                        <ArrowRightLeft class="h-4 w-4" /> {{ submitting ? 'Memproses…' : `Kompensasi dengan AR (${selected.length})` }}
                    </button>
                    <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
                </div>
            </div>

            <div class="card flex flex-wrap items-center gap-3 p-4">
                <div class="relative min-w-[220px] flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="T.search.value" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <button v-if="T.hasFilter.value" type="button" @click="T.reset()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
            </div>

            <TableTopScroll v-if="rows.length">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <!-- Baris 1: label kolom — diklik untuk mengurutkan. -->
                        <tr class="text-left">
                            <th class="w-10 px-3 pb-1 pt-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :disabled="!pageKeys.length" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua baris yang tampil'" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Sumber" :state="T.sortState('sumber')" @sort="T.toggleSort('sumber')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Tanggal" :state="T.sortState('tgl')" @sort="T.toggleSort('tgl')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Customer" :state="T.sortState('customer')" @sort="T.toggleSort('customer')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. SPR" :state="T.sortState('spr')" @sort="T.toggleSort('spr')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Unit" :state="T.sortState('unit')" @sort="T.toggleSort('unit')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Keterangan" :state="T.sortState('ket')" @sort="T.toggleSort('ket')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. Jurnal" :state="T.sortState('jurnal')" @sort="T.toggleSort('jurnal')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="DPP" align="right" :state="T.sortState('dpp')" @sort="T.toggleSort('dpp')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="PPN" align="right" :state="T.sortState('ppn')" @sort="T.toggleSort('ppn')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Total UM" align="right" :state="T.sortState('total')" @sort="T.toggleSort('total')" /></th>
                        </tr>
                        <!-- Baris 2: filter per kolom — ketik untuk mencari, panah untuk memilih dari daftar. -->
                        <tr>
                            <th class="px-3 pb-2"></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.sumber" v-model:text="T.txt.sumber" :options="T.options.value.sumber" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.tgl" v-model:text="T.txt.tgl" :options="T.options.value.tgl" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.customer" v-model:text="T.txt.customer" :options="T.options.value.customer" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.spr" v-model:text="T.txt.spr" :options="T.options.value.spr" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.unit" v-model:text="T.txt.unit" :options="T.options.value.unit" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.ket" v-model:text="T.txt.ket" :options="T.options.value.ket" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.jurnal" v-model:text="T.txt.jurnal" :options="T.options.value.jurnal" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.dpp" v-model:text="T.txt.dpp" :options="T.options.value.dpp" align="right" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.ppn" v-model:text="T.txt.ppn" :options="T.options.value.ppn" align="right" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="T.col.total" v-model:text="T.txt.total" :options="T.options.value.total" align="right" /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="r in rows" :key="r.row_key" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="selected.includes(r.row_key) ? 'bg-primary-500/10' : ''">
                            <td class="px-3 py-2.5"><input v-model="selected" type="checkbox" :value="r.row_key" :class="cbCls" title="Pilih untuk dikompensasi dengan AR" /></td>
                            <td class="px-4 py-2.5"><Badge :variant="(sourceVariant[r.source_type] as any) ?? 'neutral'" size="sm">{{ r.source_label }}</Badge></td>
                            <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.doc_date) }}</td>
                            <td class="px-4 py-2.5">{{ r.customer ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.spr ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5">{{ r.unit ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">
                                <span class="block max-w-[260px] truncate" :title="r.note ?? ''">{{ r.note ?? '—' }}</span>
                                <span v-if="r.kpr_number" class="block text-xs text-neutral-400">KPR {{ r.kpr_number }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <!-- Nomornya dulu teks mati; sekarang membuka jurnalnya langsung. -->
                                <Link v-if="r.journal_id" :href="route('bs.gl.journal.show', r.journal_id)" :class="linkCls" :title="'Buka jurnal ' + (r.journal_number ?? '#' + r.journal_id)">{{ r.journal_number ?? ('#' + r.journal_id) }}</Link>
                                <span v-else class="font-mono text-xs text-neutral-400">—</span>
                                <span v-if="r.journal_id && !r.gl_ada" class="ml-1 cursor-help text-warning-600 dark:text-warning-400"
                                      title="Jurnal ini tidak punya baris pada akun Uang Muka Penjualan — umumnya karena unitnya sudah Booked Revenue sehingga kreditnya diarahkan ke Piutang Usaha. Baris ini tidak akan muncul di tab Buku Besar.">⚠</span>
                                <!-- Retensi DAJAM dijurnal Dr Uang Muka / Cr Bank: uang DITAHAN, jadi di buku
                                     besar ia MENGURANGI saldo UM. Di antrean ia tetap tampil sebagai nilai
                                     positif yang siap dikompensasi — arahnya berlawanan, dan itu perlu terlihat. -->
                                <span v-else-if="r.gl_net !== null && r.gl_net < 0" class="ml-1 cursor-help text-danger-600 dark:text-danger-400"
                                      :title="'Di buku besar jurnal ini MENGURANGI akun Uang Muka sebesar ' + fmtRp(Math.abs(r.gl_net)) + ' (didebit), berlawanan arah dengan nilai antrean di baris ini. Lazim pada retensi DAJAM yang uangnya ditahan.'">↓</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">{{ fmtRp(r.dpp) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-neutral-500">
                                {{ fmtRp(r.ppn) }}
                                <span v-if="!r.ppn_pasti" class="ml-0.5 cursor-help text-neutral-400" title="Perkiraan PPN inklusif 11% — sumbernya (retensi/pencairan) tidak menyimpan pecahan DPP/PPN tersendiri.">≈</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(r.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </TableTopScroll>

            <p v-if="rows.length" class="text-xs text-neutral-500">Menampilkan {{ rows.length }} dari {{ semua.length }} baris<span v-if="T.hasFilter.value"> (tersaring)</span>.</p>

            <EmptyState v-else-if="T.hasFilter.value" title="Tidak ada hasil" description="Tidak ada UM Penjualan yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
            <EmptyState v-else title="Tidak ada UM Penjualan menunggu kompensasi"
                description="Semua uang muka yang sudah diposting telah dikompensasi — lihat hasilnya di Rekonsiliasi Piutang. Buku besar akun UM tetap bisa dilihat di tab sebelah.">
                <template #icon><Wallet class="h-12 w-12" /></template>
            </EmptyState>
        </template>

        <!-- ══ TAB BUKU BESAR ══ -->
        <template v-else>
            <div class="card flex flex-wrap items-center gap-3 p-4">
                <div class="relative min-w-[220px] flex-1">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                    <input v-model="G.search.value" placeholder="Cari semua kolom…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <button v-if="G.hasFilter.value" type="button" @click="G.reset()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><FilterX class="h-4 w-4" /> Reset filter</button>
                <a :href="linkAkun()" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><BookText class="h-4 w-4" /> Buka di Detail Transaksi Jurnal</a>
            </div>

            <TableTopScroll v-if="glRows.length">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left">
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Tanggal" :state="G.sortState('tgl')" @sort="G.toggleSort('tgl')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="No. Jurnal" :state="G.sortState('jurnal')" @sort="G.toggleSort('jurnal')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Status" :state="G.sortState('status')" @sort="G.toggleSort('status')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Akun" :state="G.sortState('akun')" @sort="G.toggleSort('akun')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Dokumen Sumber" :state="G.sortState('dok')" @sort="G.toggleSort('dok')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Customer" :state="G.sortState('customer')" @sort="G.toggleSort('customer')" /></th>
                            <th class="px-4 pb-1 pt-3"><SortableTh label="Keterangan Baris" :state="G.sortState('ket')" @sort="G.toggleSort('ket')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Debit" align="right" :state="G.sortState('debit')" @sort="G.toggleSort('debit')" /></th>
                            <th class="whitespace-nowrap px-4 pb-1 pt-3"><SortableTh label="Kredit" align="right" :state="G.sortState('kredit')" @sort="G.toggleSort('kredit')" /></th>
                        </tr>
                        <tr>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.tgl" v-model:text="G.txt.tgl" :options="G.options.value.tgl" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.jurnal" v-model:text="G.txt.jurnal" :options="G.options.value.jurnal" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.status" v-model:text="G.txt.status" :options="G.options.value.status" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.akun" v-model:text="G.txt.akun" :options="G.options.value.akun" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.dok" v-model:text="G.txt.dok" :options="G.options.value.dok" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.customer" v-model:text="G.txt.customer" :options="G.options.value.customer" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.ket" v-model:text="G.txt.ket" :options="G.options.value.ket" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.debit" v-model:text="G.txt.debit" :options="G.options.value.debit" align="right" /></th>
                            <th class="px-2 pb-2"><ColumnFilter v-model="G.col.kredit" v-model:text="G.txt.kredit" :options="G.options.value.kredit" align="right" /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="g in glRows" :key="g.line_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                            <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(g.journal_date) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <Link :href="route('bs.gl.journal.show', g.journal_id)" :class="linkCls">{{ g.journal_number ?? ('#' + g.journal_id) }}</Link>
                                <span v-if="g.reference" class="block text-[10.5px] text-neutral-400">{{ g.reference }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5"><Badge :variant="(statusVariant[g.journal_status] as any) ?? 'neutral'" size="sm">{{ g.status_label }}</Badge></td>
                            <td class="whitespace-nowrap px-4 py-2.5"><span class="font-mono text-xs">{{ g.account_code }}</span><span class="ml-1 text-xs text-neutral-500">{{ g.account_name }}</span></td>
                            <td class="px-4 py-2.5">
                                <span v-if="g.doc_label">{{ g.doc_label }}</span>
                                <span v-else class="text-xs italic text-neutral-400">— jurnal manual / tak terlacak —</span>
                                <span v-if="g.doc_number" class="block font-mono text-[10.5px] text-neutral-400">{{ g.doc_number }}</span>
                                <span v-if="g.queue_key" class="mt-0.5 inline-block rounded bg-primary-100 px-1.5 py-0.5 text-[10px] font-semibold text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"
                                      title="Baris ini punya wakilnya di tab Antrean Kompensasi.">ada di antrean</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span>{{ g.customer ?? '—' }}</span>
                                <span v-if="g.spr || g.unit" class="block font-mono text-[10.5px] text-neutral-400">{{ [g.spr, g.unit].filter(Boolean).join(' · ') }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[280px] truncate" :title="g.description ?? ''">{{ g.description ?? '—' }}</span></td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums" :class="g.debit ? 'font-semibold' : 'text-neutral-300 dark:text-neutral-600'">{{ fmtRp(g.debit) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums" :class="g.credit ? 'font-semibold' : 'text-neutral-300 dark:text-neutral-600'">{{ fmtRp(g.credit) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td colspan="7" class="px-4 py-2.5 text-right">Jumlah {{ glRows.length }} baris yang tampil</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums">{{ fmtRp(glRows.reduce((s, g) => s + Number(g.debit || 0), 0)) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums">{{ fmtRp(glRows.reduce((s, g) => s + Number(g.credit || 0), 0)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </TableTopScroll>

            <p v-if="glRows.length" class="text-xs text-neutral-500">Menampilkan {{ glRows.length }} dari {{ semuaGl.length }} baris jurnal<span v-if="G.hasFilter.value"> (tersaring)</span>.</p>

            <EmptyState v-else-if="G.hasFilter.value" title="Tidak ada hasil" description="Tidak ada baris jurnal yang cocok dengan filter. Klik Reset filter untuk menampilkan semua." />
            <EmptyState v-else title="Belum ada jurnal pada akun Uang Muka Penjualan"
                description="Belum ada satu pun baris jurnal yang menyentuh akun ini. Begitu ada, barisnya muncul di sini secara otomatis — persis seperti yang tampil di Detail Transaksi Jurnal.">
                <template #icon><BookText class="h-12 w-12" /></template>
            </EmptyState>
        </template>
    </div>
</template>
