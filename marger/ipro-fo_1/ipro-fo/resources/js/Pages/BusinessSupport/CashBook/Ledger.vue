<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, FileSpreadsheet, Printer, Layers, Plus, Trash2, ChevronDown, ChevronRight, Save, ExternalLink } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{
    accounts: Array<{ id: number; code: string; name: string }>;
    accountId: number | null;
    opening: number;
    entries: Array<any>;
    closing: number;
    filters: { from: string | null; to: string | null };
    cfDirect: Array<{ id: number; code: string; name: string; activity: string }>;
    cfIndirect: Array<{ id: number; code: string; name: string; activity: string }>;
    /** Kategori arus kas (modul Cash Flow) — penentu baris mana di Report Cashflow. */
    cfCategories?: Array<{ id: number; code: string; name: string; section: string; group_name: string | null }>;
}>();

// Kategori dikelompokkan per seksi agar pilihannya tidak jadi daftar panjang tanpa konteks.
const SECTION_LABEL: Record<string, string> = { inflow_ops: 'CASH IN OPS', outflow: 'CASH OUT', inflow_non_ops: 'CASH IN NON OPS' };
const cfGrouped = computed(() => {
    const out: Array<{ label: string; items: Array<{ id: number; label: string }> }> = [];
    for (const sec of ['inflow_ops', 'outflow', 'inflow_non_ops']) {
        const items = (props.cfCategories ?? []).filter(c => c.section === sec)
            .map(c => ({ id: c.id, label: (c.group_name ? `${c.group_name} · ` : '') + c.name }));
        if (items.length) out.push({ label: SECTION_LABEL[sec] ?? sec, items });
    }
    return out;
});

/**
 * Daftar rata untuk pemilih yang bisa dicari.
 *
 * SearchSelect tidak mengenal <optgroup>, jadi nama seksi (CASH IN OPS / CASH OUT / …)
 * ditempelkan ke depan label. Itu bukan sekadar penambal: karena SearchSelect mencocokkan
 * teks label, mengetik "cash out promosi" jadi ikut menemukan kategorinya — sesuatu yang
 * tidak mungkin dilakukan pada <select> ber-optgroup.
 */
const cfOptions = computed(() =>
    cfGrouped.value.flatMap((g) => g.items.map((o) => ({ id: o.id, label: `${g.label} · ${o.label}` }))));

const account = ref(props.accountId ?? '');
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');
function display() { router.get(route('bs.cashbook.ledger'), { account_id: account.value || undefined, from: from.value || undefined, to: to.value || undefined }, { preserveState: true, replace: true }); }
const exportParams = computed<Record<string, any>>(() => {
    const p: Record<string, any> = {};
    if (props.accountId) p.account_id = props.accountId;
    if (props.filters.from) p.from = props.filters.from;
    if (props.filters.to) p.to = props.filters.to;
    return p;
});
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const selCls = 'h-8 w-full min-w-[140px] rounded-md border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';

// ── Cetak bukti kas/bank massal (checkbox per mutasi) ──
// Hanya mutasi yang berasal dari transaksi kas (trx_id terisi) yang punya bukti; mutasi
// saldo awal / jurnal manual / AP / bon sementara tidak — checkbox-nya dinonaktifkan.
// Dideklarasikan SEBELUM watch di bawah: watch immediate:true berjalan saat setup.
const selected = ref<number[]>([]);

// Salinan reaktif tiap mutasi (untuk edit tag arus kas + pembagian cluster inline).
const rows = ref<any[]>([]);
watch(() => props.entries, (v) => {
    rows.value = (v ?? []).map((e: any) => ({ ...e, clusters: (e.clusters ?? []).map((c: any) => ({ ...c })) }));
    // Buang centang yang barisnya sudah tidak ada lagi — TAPI jangan dikosongkan semua:
    // saveCf/saveClusters mem-post lalu back(), sehingga props.entries ikut ter-refresh.
    // Mengosongkan di sini akan menghapus pilihan user tiap kali ia menandai arus kas.
    // (Set dibangun dari rows, bukan printableIds — computed itu dideklarasikan di bawah.)
    const ada = new Set(rows.value.filter((r) => r.trx_id).map((r) => Number(r.trx_id)));
    selected.value = selected.value.filter((id) => ada.has(id));
}, { immediate: true });

// Ganti rekening/periode → centang lama tidak lagi relevan. Dipicu oleh PERUBAHAN filter,
// bukan oleh refresh props, agar simpan arus kas/cluster tidak ikut menghapus pilihan.
watch(() => [props.accountId, props.filters.from, props.filters.to].join('|'), () => { selected.value = []; });

const printableIds = computed<number[]>(() => Array.from(new Set(rows.value.filter((r) => r.trx_id).map((r) => Number(r.trx_id)))));
const noBuktiCount = computed(() => rows.value.filter((r) => !r.trx_id).length);
const allChecked = computed<boolean>({
    get: () => printableIds.value.length > 0 && printableIds.value.every((id) => selected.value.includes(id)),
    set: (v: boolean) => { selected.value = v ? [...printableIds.value] : []; },
});
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';

// #1 — Simpan tag kategori arus kas (Direct/Indirect) saat dipilih.
function saveCf(row: any) {
    router.post(route('bs.cashbook.ledger.cf'), {
        journal_line_id: row.id,
        cf_direct_category_id: row.cf_direct_category_id || null,
        cf_indirect_category_id: row.cf_indirect_category_id || null,
    }, { preserveState: true, preserveScroll: true });
}

// #2 — Pembagian cash flow: expand/collapse + edit baris + simpan.
const expanded = ref<Set<number>>(new Set());
function toggle(id: number) { const s = new Set(expanded.value); s.has(id) ? s.delete(id) : s.add(id); expanded.value = s; }
// Cluster tidak lagi diminta — pembagian ditentukan oleh kategori cash flow saja.
function addSplit(row: any) { row.clusters.push({ cf_category_id: null, amount: 0, note: '' }); }
function removeSplit(row: any, i: number) { row.clusters.splice(i, 1); }
function sumSplits(row: any) { return (row.clusters ?? []).reduce((s: number, c: any) => s + (Number(c.amount) || 0), 0); }
function mutAmount(row: any) { return Number(row.debit) || Number(row.credit) || 0; }

/**
 * Pembagian cash flow WAJIB pas dengan nilai mutasi jurnalnya (debit ATAU kredit baris itu).
 * Boleh dikosongkan sama sekali (berarti pembagian dihapus); tetapi bila ada isinya, jumlahnya
 * harus persis sama — kalau tidak, angka arus kas per kategori tidak akan pernah cocok dengan
 * mutasi kas yang sebenarnya. Server memvalidasi hal yang sama (CashBookController::ledgerClusters).
 *
 * Baris yang dikirim = yang sudah punya KATEGORI (dulu: yang sudah punya cluster).
 */
function splitRows(row: any) { return (row.clusters ?? []).filter((c: any) => c.cf_category_id); }
function splitBalanced(row: any) {
    const n = (row.clusters ?? []).length;
    return n === 0 || Math.abs(sumSplits(row) - mutAmount(row)) < 1;
}
function splitDiff(row: any) { return sumSplits(row) - mutAmount(row); }
// Kategori kini wajib: baris tanpa kategori tidak boleh ikut tersimpan diam-diam.
function splitComplete(row: any) { return (row.clusters ?? []).every((c: any) => c.cf_category_id); }
function canSave(row: any) { return splitBalanced(row) && splitComplete(row); }

const savingCluster = ref<number | null>(null);
function saveClusters(row: any) {
    if (!canSave(row)) return;   // gerbang klien; server tetap menjaga
    savingCluster.value = row.id;
    router.post(route('bs.cashbook.ledger.clusters'), {
        journal_line_id: row.id,
        splits: splitRows(row).map((c: any) => ({ cf_category_id: c.cf_category_id, amount: Number(c.amount) || 0, note: c.note || '' })),
    }, { preserveState: true, preserveScroll: true, onFinish: () => (savingCluster.value = null) });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.cashbook.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Cash Book</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><BookOpen class="h-6 w-6 text-primary-600" /> Rekening Ledger</h1>
            <div v-if="accountId" class="flex flex-wrap items-center gap-2">
                <a :href="route('bs.cashbook.ledger.excel', exportParams)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.cashbook.ledger.pdf', exportParams)" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> PDF</a>
            </div>
        </div>

        <div class="card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[260px] flex-1"><label class="mb-1 block text-xs font-medium text-neutral-500">Rekening Kas/Bank</label><SearchSelect v-model="account" :options="accounts" placeholder="— pilih rekening —" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari</label><input v-model="from" type="date" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sampai</label><input v-model="to" type="date" :class="inputCls" /></div>
            <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Tampilkan</button>
            <div v-if="accountId" class="ml-auto text-right"><div class="text-xs uppercase text-neutral-400">Saldo Akhir</div><div class="font-mono text-lg font-bold">Rp {{ fmtRp(closing) }}</div></div>
        </div>

        <!-- Cetak bukti kas/bank untuk mutasi yang dicentang. Sticky agar tetap terjangkau saat menggulir. -->
        <div v-if="accountId && selected.length" class="card sticky top-2 z-10 flex flex-wrap items-center gap-3 border-l-4 border-l-primary-500 p-3">
            <span class="text-sm"><b>{{ selected.length }}</b> transaksi dipilih</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a :href="route('bs.cashbook.print-bulk', { ids: selected.join(',') })" target="_blank" rel="noopener"
                   title="Cetak Bukti Penerimaan/Pengeluaran Kas & Bank untuk semua transaksi terpilih — 1 bukti per halaman."
                   class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak Bukti ({{ selected.length }})</a>
                <button type="button" @click="selected = []" class="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300">Batal pilih</button>
            </div>
        </div>

        <div v-if="accountId" class="card overflow-x-auto">
            <p v-if="rows.length && noBuktiCount" class="border-b border-neutral-100 px-3 py-2 text-xs text-neutral-400 dark:border-neutral-800">
                {{ noBuktiCount }} dari {{ rows.length }} mutasi tidak bisa dicentang karena bukan transaksi kas
                (saldo awal / jurnal manual / hutang-piutang / bon sementara) sehingga tidak punya Bukti Kas &amp; Bank.
            </p>
            <table class="w-full min-w-[1100px] text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="w-10 px-3 py-3"><input v-model="allChecked" type="checkbox" :class="cbCls" :disabled="!printableIds.length" :title="allChecked ? 'Kosongkan pilihan' : 'Pilih semua mutasi yang punya bukti kas/bank'" /></th>
                    <th class="px-3 py-3">Tanggal</th><th class="px-3 py-3">Referensi</th><th class="px-3 py-3">Keterangan</th>
                    <th class="px-3 py-3 text-right">Debit</th><th class="px-3 py-3 text-right">Kredit</th><th class="px-3 py-3 text-right">Saldo</th>
                    <th class="px-3 py-3">Cash Flow Direct</th><th class="px-3 py-3">Cash Flow Indirect</th><th class="px-3 py-3 text-center">Pembagian</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr class="bg-neutral-50/60 dark:bg-neutral-800/40"><td class="px-3 py-2 font-medium" colspan="6">Saldo Awal</td><td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtRp(opening) }}</td><td colspan="3"></td></tr>
                    <template v-for="row in rows" :key="row.id">
                        <tr :class="[expanded.has(row.id) ? 'bg-primary-50/30 dark:bg-primary-900/10' : '', row.trx_id && selected.includes(Number(row.trx_id)) ? 'bg-primary-500/10' : '']">
                            <td class="px-3 py-2">
                                <input v-if="row.trx_id" v-model="selected" type="checkbox" :value="Number(row.trx_id)" :class="cbCls" title="Pilih untuk cetak Bukti Kas & Bank" />
                                <span v-else class="cursor-not-allowed text-neutral-300 dark:text-neutral-600" title="Mutasi ini bukan transaksi kas (saldo awal / jurnal manual / hutang-piutang / bon sementara) — tidak ada Bukti Kas & Bank yang bisa dicetak.">—</span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ fmtDate(row.date) }}</td>
                            <td class="px-3 py-2 font-mono text-xs">
                                <!-- Dibuka di TAB BARU: menelusuri satu jurnal tidak boleh membuang
                                     posisi kerja di layar ini — filter rekening/periode, centang
                                     bukti kas, dan rincian pembagian yang sedang dibuka. -->
                                <a v-if="row.journal_id" :href="route('bs.gl.journal.show', row.journal_id)"
                                   target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 rounded text-primary-700 underline decoration-dotted underline-offset-2 hover:text-primary-900 hover:decoration-solid dark:text-primary-300 dark:hover:text-primary-200"
                                   :title="`Buka detail jurnal ${row.ref} di tab baru`">
                                    {{ row.ref }}<ExternalLink class="h-3 w-3 shrink-0 opacity-60" />
                                </a>
                                <span v-else>{{ row.ref }}</span>
                            </td>
                            <td class="px-3 py-2 text-neutral-500">
                                {{ row.desc }}
                                <!-- Keterangan kepala jurnal: baris jurnal sering hanya menyebut
                                     nomor dokumen, sedangkan perkaranya ada di keterangan jurnal. -->
                                <span v-if="row.journal_desc" class="mt-0.5 block text-xs text-neutral-400">{{ row.journal_desc }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-mono">{{ row.debit ? fmtRp(row.debit) : '' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ row.credit ? fmtRp(row.credit) : '' }}</td>
                            <td class="px-3 py-2 text-right font-mono font-semibold">{{ fmtRp(row.balance) }}</td>
                            <td class="px-3 py-2"><select v-model="row.cf_direct_category_id" @change="saveCf(row)" :class="selCls"><option :value="null">—</option><option v-for="c in cfDirect" :key="c.id" :value="c.id">{{ c.name }}</option></select></td>
                            <td class="px-3 py-2"><select v-model="row.cf_indirect_category_id" @change="saveCf(row)" :class="selCls"><option :value="null">—</option><option v-for="c in cfIndirect" :key="c.id" :value="c.id">{{ c.name }}</option></select></td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" @click="toggle(row.id)"
                                        class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition"
                                        :class="expanded.has(row.id) ? 'border-primary-500 bg-primary-500 text-white' : (row.clusters.length ? 'border-primary-300 text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300')">
                                    <component :is="expanded.has(row.id) ? ChevronDown : ChevronRight" class="h-3.5 w-3.5" /><Layers class="h-3.5 w-3.5" /> PEMBAGIAN CASH FLOW
                                    <span v-if="row.clusters.length" class="ml-0.5 rounded-full bg-white/25 px-1.5 text-[10px]">{{ row.clusters.length }}</span>
                                </button>
                            </td>
                        </tr>
                        <!-- Rincian pembagian cash flow (bisa hide/tampil) -->
                        <tr v-if="expanded.has(row.id)">
                            <td colspan="10" class="bg-neutral-50 px-4 py-3 dark:bg-neutral-800/40">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="flex items-center gap-2 text-sm font-semibold"><Layers class="h-4 w-4 text-primary-600" /> Pembagian Biaya per Cash Flow <span class="text-xs font-normal text-neutral-500">— nilai mutasi Rp {{ fmtRp(mutAmount(row)) }}</span></p>
                                    <button type="button" @click="toggle(row.id)" class="text-xs text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Sembunyikan ▲</button>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(c, i) in row.clusters" :key="i" class="flex flex-wrap items-center gap-2">
                                        <!-- Kategori Cash Flow: penentu baris mana angka ini muncul di Report Cashflow.
                                             Dapat DICARI dengan mengetik — daftarnya panjang, dan nama seksi
                                             ikut dijadikan bagian label sehingga "cash out promosi" pun mencocok. -->
                                        <div :class="['min-w-[240px] flex-[2] rounded-md',
                                                c.cf_category_id ? 'ring-1 ring-emerald-300 dark:ring-emerald-700' : 'ring-1 ring-amber-300 dark:ring-amber-700']"
                                            title="Kategori Cash Flow — menentukan baris pada Report Cashflow. Ketik untuk mencari.">
                                            <SearchSelect v-model="c.cf_category_id" :options="cfOptions"
                                                placeholder="— kategori cash flow —" no-truncate />
                                        </div>
                                        <!-- Nominal dengan pemisah ribuan (1.000.000). -->
                                        <MoneyInput v-model="c.amount" placeholder="Nominal" class="!h-8 !w-36 !px-2 !text-xs" />
                                        <input v-model="c.note" placeholder="Keterangan (opsional)" class="h-8 min-w-[140px] flex-1 rounded-md border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                                        <button type="button" @click="removeSplit(row, i)" class="rounded-md border border-danger-300 p-1.5 text-danger-600 hover:bg-danger-50 dark:border-danger-700"><Trash2 class="h-3.5 w-3.5" /></button>
                                    </div>
                                    <p v-if="row.clusters.length && row.clusters.some((c: any) => !c.cf_category_id)" class="text-[11px] text-amber-700 dark:text-amber-400">
                                        Masih ada baris tanpa <b>kategori cash flow</b>. Pilih kategorinya dulu — tanpa itu baris tersebut tidak menggolongkan apa pun dan tidak bisa disimpan.
                                    </p>
                                    <p v-if="!row.clusters.length" class="text-xs italic text-neutral-400">Belum ada pembagian. Klik "Tambah Cash Flow" untuk membagi biaya mutasi ini ke beberapa kategori cash flow.</p>
                                    <!-- Penjaga: total pembagian harus persis sama dengan nilai mutasi jurnalnya. -->
                                    <p v-if="!splitBalanced(row)" class="rounded-md border border-danger-300 bg-danger-50 px-2.5 py-1.5 text-xs text-danger-700 dark:border-danger-700 dark:bg-danger-900/25 dark:text-danger-300">
                                        Total pembagian <b class="font-mono">Rp {{ fmtRp(sumSplits(row)) }}</b> belum sama dengan nilai mutasi <b class="font-mono">Rp {{ fmtRp(mutAmount(row)) }}</b>
                                        (selisih <b class="font-mono">Rp {{ fmtRp(Math.abs(splitDiff(row))) }}</b> {{ splitDiff(row) > 0 ? 'kelebihan' : 'kurang' }}). Samakan dulu agar bisa disimpan.
                                    </p>
                                </div>
                                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                    <button type="button" @click="addSplit(row)" class="inline-flex items-center gap-1 rounded-lg border border-dashed border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300"><Plus class="h-3.5 w-3.5" /> Tambah Cash Flow</button>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-neutral-500">Total pembagian: <b class="font-mono" :class="splitBalanced(row) ? 'text-success-600' : 'text-danger-600'">Rp {{ fmtRp(sumSplits(row)) }}</b> / Rp {{ fmtRp(mutAmount(row)) }}</span>
                                        <button type="button" @click="saveClusters(row)" :disabled="savingCluster === row.id || !canSave(row)"
                                                :title="!splitComplete(row) ? 'Setiap baris harus punya kategori cash flow' : (splitBalanced(row) ? 'Simpan pembagian cash flow' : 'Total pembagian harus sama dengan nilai mutasi jurnal')"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"><Save class="h-3.5 w-3.5" /> {{ savingCluster === row.id ? 'Menyimpan…' : 'Simpan Pembagian' }}</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="border-t border-neutral-200 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800"><tr><td class="px-3 py-2.5" colspan="6">Saldo Akhir</td><td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(closing) }}</td><td colspan="3"></td></tr></tfoot>
            </table>
            <EmptyState v-if="!rows?.length" title="Tidak ada mutasi" description="Rekening ini belum punya mutasi pada rentang tsb." />
        </div>
        <EmptyState v-else title="Pilih rekening" description="Pilih rekening kas/bank untuk melihat buku besarnya." />
    </div>
</template>
