<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Plus, ArrowLeft, Banknote, Search, ArrowDownCircle, ArrowUpCircle, Repeat, Landmark, Scale, ReceiptText, Download, Printer, Filter } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import TableTopScroll from '@/Components/UI/TableTopScroll.vue';

const props = defineProps<{
    transactions: any;
    types: Record<string, string>;
    filters: { type?: string; search?: string; account_id?: string; from?: string; to?: string };
    summary: { in: number; out: number };
    cashAccounts: Array<any>;
    glMode?: boolean; // true bila 1 rekening dipilih → baris = baris jurnal (GL), bukan transaksi kas
}>();

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');
const accountId = ref<any>(props.filters.account_id ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
function applyFilter() {
    // Centang WAJIB dikosongkan: id berganti makna antar mode (transaksi kas ⇄ baris jurnal),
    // dan baris terpilih bisa hilang dari hasil filter baru.
    selected.value = [];
    router.get(route('bs.cashbook.index'), {
        search: search.value || undefined, type: type.value || undefined,
        account_id: accountId.value || undefined, from: from.value || undefined, to: to.value || undefined,
    }, { preserveState: true, replace: true });
}

// ── Pilih massal (checkbox per baris) ──
const selected = ref<number[]>([]);
const rowId = (r: any) => Number(r.trx_id ?? r.id);
// Pelunasan hutang (AP) tampil sebagai baris sintetis — bukan transaksi kas, jadi tidak punya
// bukti kas/bank & tidak bisa dibuka. WAJIB dikecualikan dari checkbox: id-nya milik
// bs_ap_payments dan akan bertabrakan dgn id BsCashTransaction → bisa mencetak bukti yang SALAH.
const isCashTrx = (r: any) => !r.is_ap_payment;
// Transfer tampil 2 baris (sisi keluar + masuk) dari 1 transaksi → id-nya sama, dihitung sekali.
const pageIds = computed<number[]>(() => Array.from(new Set((props.transactions.data ?? []).filter(isCashTrx).map((r: any) => rowId(r)))));
const allChecked = computed<boolean>({
    get: () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
    set: (v: boolean) => { selected.value = v ? [...pageIds.value] : []; },
});
const filterParams = computed<Record<string, any>>(() => ({
    account_id: props.filters.account_id || undefined, type: props.filters.type || undefined,
    search: props.filters.search || undefined, from: props.filters.from || undefined, to: props.filters.to || undefined,
}));
const selectedParams = computed(() => ({ ...filterParams.value, ids: selected.value.join(',') }));
const anyFilter = computed(() => !!(search.value || type.value || accountId.value || from.value || to.value));
function resetFilters() {
    search.value = ''; type.value = ''; accountId.value = ''; from.value = ''; to.value = '';
    applyFilter();
}
const cbCls = 'h-4 w-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const typeMeta: Record<string, any> = {
    in: { icon: ArrowDownCircle, color: 'text-success-600', badge: 'success' },
    out: { icon: ArrowUpCircle, color: 'text-danger-600', badge: 'danger' },
    transfer: { icon: Repeat, color: 'text-primary-600', badge: 'info' },
};
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Business Support</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> Cash Book <span class="text-base font-normal text-neutral-400">(Kas / Bank)</span></h1>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('bs.cashbook.balances')"><Button variant="secondary"><template #icon-left><Landmark class="h-4 w-4" /></template>Saldo Rekening</Button></Link>
                <!-- #129: unduh mutasi sesuai filter layar (tanpa ids → seluruh hasil filter) -->
                <a :href="route('bs.cashbook.export', filterParams)"><Button variant="secondary"><template #icon-left><Download class="h-4 w-4" /></template>Download Mutasi</Button></a>
                <!-- #9: cetak mutasi sesuai filter layar -->
                <a :href="route('bs.cashbook.export.pdf', filterParams)" target="_blank" rel="noopener"><Button variant="secondary"><template #icon-left><Printer class="h-4 w-4" /></template>PDF</Button></a>
                <Link :href="route('bs.bankrec.index')" title="Rekonsiliasi Bank: mencocokkan catatan kas/bank di sistem dengan rekening koran bank, untuk menemukan selisih (transaksi belum tercatat / belum kliring)." class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Scale class="h-4 w-4" /> Rekonsiliasi</Link>
                <!-- #77: bayar beberapa invoice 1 supplier sekaligus (Kuitansi Hutang) -->
                <Link :href="route('bs.ap.kuitansi.create')" title="Bayar beberapa invoice untuk 1 supplier dalam 1 tanggal pembayaran (Kuitansi Hutang)."><Button variant="secondary"><template #icon-left><ReceiptText class="h-4 w-4" /></template>Bayar Hutang (Multi-Invoice)</Button></Link>
                <Link :href="route('bs.cashbook.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Transaksi Kas</Button></Link>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Kas Masuk<span v-if="anyFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(summary.in) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Kas Keluar<span v-if="anyFilter" class="ml-1 normal-case text-[10px] text-primary-500">(sesuai filter)</span></div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(summary.out) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Arus Kas Bersih</div><div class="mt-1 text-xl font-bold" :class="(summary.in - summary.out) >= 0 ? 'text-success-700' : 'text-danger-600'">{{ fmtRp(summary.in - summary.out) }}</div></div>
        </div>

        <!-- #6: Toolbar filter (server-side; ringkasan & saldo ikut ter-filter) — dipindah dari dalam <thead> agar tabel tidak meluber. -->
        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="flex items-center gap-1.5 text-xs font-medium text-neutral-500"><Filter class="h-4 w-4" /> Filter</div>
            <div class="min-w-[180px]">
                <label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Cari</label>
                <div class="relative"><Search class="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" @change="applyFilter" placeholder="no / ket / pihak" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-7 pr-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            </div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Dari Tanggal</label><input v-model="from" @change="applyFilter" type="date" title="Dari tanggal" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Sampai Tanggal</label><input v-model="to" @change="applyFilter" type="date" title="Sampai tanggal" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Jenis</label><select v-model="type" @change="applyFilter" class="h-9 w-auto min-w-[8.5rem] rounded-lg border border-neutral-300 bg-white px-2 pr-6 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua</option><option v-for="(label, val) in types" :key="val" :value="val">{{ label }}</option></select></div>
            <div><label class="mb-1 block text-[11px] font-medium uppercase text-neutral-400">Rekening</label><select v-model="accountId" @change="applyFilter" class="h-9 w-auto min-w-[11rem] rounded-lg border border-neutral-300 bg-white px-2 pr-6 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua rekening</option><option v-for="a in cashAccounts" :key="a.id" :value="String(a.id)">{{ a.name }}</option></select></div>
            <button v-if="anyFilter" type="button" @click="resetFilters" class="ml-auto rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Reset filter</button>
        </div>

        <!-- Aksi massal atas baris yang dicentang. Sticky agar tetap terjangkau saat menggulir daftar panjang. -->
        <div v-if="selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> {{ glMode ? 'mutasi' : 'transaksi' }} dipilih</span>
            <span v-if="glMode" class="text-xs text-neutral-400">Cetak bukti hanya tersedia pada tampilan "Semua rekening" — baris di sini adalah mutasi buku besar, bukan transaksi kas.</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a v-if="!glMode" :href="route('bs.cashbook.print-bulk', { ids: selected.join(',') })" target="_blank" rel="noopener" title="Cetak bukti kas/bank untuk semua transaksi terpilih — 1 bukti per halaman."><Button variant="secondary"><template #icon-left><Printer class="h-4 w-4" /></template>Cetak Bukti ({{ selected.length }})</Button></a>
                <a :href="route('bs.cashbook.export', selectedParams)" title="Unduh baris terpilih ke Excel."><Button variant="secondary"><template #icon-left><Download class="h-4 w-4" /></template>Download Excel</Button></a>
                <a :href="route('bs.cashbook.export.pdf', selectedParams)" target="_blank" rel="noopener" title="Cetak daftar mutasi berisi baris terpilih."><Button variant="secondary"><template #icon-left><Printer class="h-4 w-4" /></template>PDF Mutasi</Button></a>
                <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
            </div>
        </div>

        <!-- #6: fit-to-page — TableTopScroll menyediakan card + scrollbar atas tersinkron -->
        <TableTopScroll>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="w-10 px-3 py-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua baris di halaman ini'" /></th>
                        <th class="whitespace-nowrap px-4 py-3">No.</th><th class="whitespace-nowrap px-4 py-3">Tanggal</th><th class="whitespace-nowrap px-4 py-3">Jenis</th><th class="px-4 py-3">Rekening</th><th class="px-4 py-3">Keterangan / Pihak</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">Nominal Masuk</th><th class="whitespace-nowrap px-4 py-3 text-right">Nominal Keluar</th>
                        <th class="whitespace-nowrap px-4 py-3 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in transactions.data" :key="r.row_key ?? r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="[(glMode || r.is_ap_payment) ? '' : 'cursor-pointer', isCashTrx(r) && selected.includes(rowId(r)) ? 'bg-primary-500/10' : '']" @click="!glMode && !r.is_ap_payment && router.get(route('bs.cashbook.show', r.trx_id ?? r.id))">
                        <!-- .stop: mencentang tidak boleh ikut membuka detail transaksi -->
                        <td class="px-3 py-2.5" @click.stop>
                            <input v-if="isCashTrx(r)" v-model="selected" type="checkbox" :value="rowId(r)" :class="cbCls" />
                            <span v-else class="cursor-not-allowed text-neutral-300 dark:text-neutral-600" title="Pelunasan hutang (AP) hanya berjurnal — tidak punya Bukti Kas/Bank yang bisa dicetak. Buka lewat modul Account Payable.">—</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 font-mono font-semibold">{{ r.trx_number }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">{{ fmtDate(r.trx_date) }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5">
                            <Badge v-if="r.is_ap_payment" variant="warning" size="sm" title="Pelunasan hutang dari modul Account Payable — hanya berjurnal, bukan transaksi kas.">Pelunasan Hutang (AP)</Badge>
                            <Badge v-else :variant="typeMeta[r.trx_type]?.badge ?? 'neutral'" size="sm">{{ types[r.trx_type] }}</Badge>
                        </td>
                        <td class="px-4 py-2.5"><span class="block max-w-[200px] truncate" :title="r.rekening_label ?? r.cash_account?.name ?? ''">{{ r.rekening_label ?? r.cash_account?.name ?? '—' }}</span></td>
                        <td class="px-4 py-2.5 text-neutral-500"><span class="block max-w-[200px] truncate" :title="(r.description ?? '') + (r.payee ? ' · ' + r.payee : '')">{{ r.description }}<span v-if="r.payee" class="text-neutral-400"> · {{ r.payee }}</span></span></td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-success-600">{{ r.masuk > 0 ? fmtRp(r.masuk) : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-danger-600">{{ r.keluar > 0 ? fmtRp(r.keluar) : '—' }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono" :class="r.running_saldo != null ? ((r.running_saldo >= 0) ? 'text-neutral-700 dark:text-neutral-200' : 'text-danger-600') : 'text-neutral-300'">{{ r.running_saldo != null ? fmtRp(r.running_saldo) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!transactions.data?.length" title="Belum ada transaksi" description="Klik Transaksi Kas untuk mencatat Kas Masuk, Kas Keluar, atau Transfer." />
            <p v-else-if="glMode" class="border-t border-neutral-100 px-4 py-2 text-xs text-neutral-400 dark:border-neutral-800">Menampilkan <b>seluruh mutasi buku besar</b> rekening ini (termasuk pembayaran hutang/giro/payroll), bukan hanya transaksi kas. Saldo = saldo buku besar (cocok dengan menu Saldo Rekening).</p>
            <p v-else-if="!filters.account_id" class="border-t border-neutral-100 px-4 py-2 text-xs text-neutral-400 dark:border-neutral-800">Pilih satu rekening pada filter "Semua rekening" untuk menampilkan seluruh mutasi &amp; Saldo berjalan per baris.</p>
        </TableTopScroll>
    </div>
</template>
