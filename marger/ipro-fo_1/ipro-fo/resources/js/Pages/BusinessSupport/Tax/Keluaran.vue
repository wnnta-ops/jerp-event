<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpCircle, Download, Check, ArrowRightLeft, RotateCcw, FileSpreadsheet, ListChecks, Upload, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    period: { year: number; month: number };
    sources: Record<string, string>;
    totals: { dpp: number; ppn: number; count: number; tanpa_fp_count: number; tanpa_fp_ppn: number; jurnal_count: number; entry_count: number };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.keluaran'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

// ── Meta per baris ──
// Kuncinya memakai row_type: id baris jurnal dan id entri approval berasal dari dua tabel
// berbeda dan bisa bertabrakan angkanya.
function rowKey(r: any) { return r.row_type + '-' + r.id; }
function isJurnal(r: any) { return r.row_type === 'jurnal'; }
function sumberLabel(r: any) { return props.sources[r.source] ?? r.source; }
function sumberVariant(r: any) {
    return ({ cash: 'neutral', ar: 'primary', ap: 'neutral', memorial: 'info', saldo_awal: 'neutral', import: 'neutral',
        retensi: 'info', retensi_release: 'info', dajam: 'info', pencairan: 'success', utj_dp: 'warning' } as Record<string,any>)[r.source] ?? 'neutral';
}

// ── Filter per-kolom (ketik untuk mencari) ──
const f = reactive({ fp: '', sumber: '', date: '', npwp: '', nama: '', dok: '', dpp: '', ppn: '' });
function inc(hay: any, needle: string) { return String(hay ?? '').toLowerCase().includes(needle.toLowerCase()); }
function resetFilter() { f.fp = ''; f.sumber = ''; f.date = ''; f.npwp = ''; f.nama = ''; f.dok = ''; f.dpp = ''; f.ppn = ''; }
const filtered = computed(() => props.rows.filter((r) =>
    (!f.fp || inc(r.tax_invoice_no, f.fp)) &&
    (!f.sumber || inc(sumberLabel(r), f.sumber)) &&
    (!f.date || inc(fmtDate(r.invoice_date), f.date) || inc(r.invoice_date, f.date)) &&
    (!f.npwp || inc(r.npwp, f.npwp)) &&
    (!f.nama || inc(r.partner, f.nama)) &&
    (!f.dok || inc(r.doc_no, f.dok)) &&
    (!f.dpp || inc(r.dpp, f.dpp) || inc(fmtRp(r.dpp), f.dpp)) &&
    (!f.ppn || inc(r.ppn, f.ppn) || inc(fmtRp(r.ppn), f.ppn))
));
// Unduhan mengikuti saringan kolom: bila ada saringan, kirim kunci baris yang tampil.
const adaSaringan = computed(() => Object.values(f).some((v) => v !== ''));
const unduhParam = computed(() => ({
    year: props.period.year, month: props.period.month,
    keys: adaSaringan.value ? (filtered.value.map(rowKey).join(',') || '0') : undefined,
}));
const totF = computed(() => ({ dpp: filtered.value.reduce((s, r) => s + Number(r.dpp || 0), 0), ppn: filtered.value.reduce((s, r) => s + Number(r.ppn || 0), 0) }));

/* ── Checkbox seleksi ──
   Bawaannya SELURUH baris tercentang: masa pajak biasanya diisi sekaligus, dan meminta orang
   mencentang satu per satu lebih dulu hanya menambah langkah untuk perkara yang lazim. Yang
   perlu dikecualikan tinggal dilepas centangnya. */
const dipilih = ref<string[]>(props.rows.map(rowKey));
watch(() => props.rows, (rows) => { dipilih.value = (rows ?? []).map(rowKey); });
function isSelected(r: any) { return dipilih.value.includes(rowKey(r)); }
function toggle(r: any) { const k = rowKey(r); const i = dipilih.value.indexOf(k); if (i >= 0) dipilih.value.splice(i, 1); else dipilih.value.push(k); }
const allSelected = computed(() => filtered.value.length > 0 && filtered.value.every(isSelected));
function toggleAll() {
    if (allSelected.value) { const keys = new Set(filtered.value.map(rowKey)); dipilih.value = dipilih.value.filter((k) => !keys.has(k)); }
    else { const cur = new Set(dipilih.value); filtered.value.forEach((r) => cur.add(rowKey(r))); dipilih.value = Array.from(cur); }
}
// Baris UTJ & DP yang belum dikonversi memang belum boleh bernomor faktur — dikeluarkan dari
// kiriman massal supaya jumlah yang dilaporkan tersimpan tidak menghitung baris yang dilewati.
const barisTerpilih = computed(() => filtered.value.filter((r) => isSelected(r) && r.editable));

// ── Edit & simpan satu baris ──
const editedNsfp = ref<Record<string, string>>({});
const editedNpwp = ref<Record<string, string>>({});
const savedKey = ref<string | null>(null);
function currentNo(r: any) { const k = rowKey(r); return editedNsfp.value[k] ?? r.tax_invoice_no ?? ''; }
function currentNpwp(r: any) { const k = rowKey(r); return editedNpwp.value[k] ?? r.npwp ?? ''; }
function saveRow(r: any) {
    const k = rowKey(r);
    const no = currentNo(r);
    const npwp = currentNpwp(r);
    if (no === (r.tax_invoice_no ?? '') && npwp === (r.npwp ?? '')) return;

    // Baris jurnal → nomornya menempel pada baris jurnalnya. Entri approval belum berjurnal,
    // jadi nomornya tersimpan pada entrinya sendiri (dan entri belum punya kolom NPWP).
    const url = isJurnal(r) ? route('bs.tax.nsfp') : route('bs.tax.entry.nsfp');
    const payload: Record<string, any> = isJurnal(r)
        ? { type: 'keluaran', invoice_id: r.id, tax_invoice_no: no, npwp }
        : { entry_id: r.id, tax_invoice_no: no };
    router.post(url, payload, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => {
            r.tax_invoice_no = no || null;
            if (isJurnal(r)) r.npwp = npwp || null;
            savedKey.value = k;
            setTimeout(() => { if (savedKey.value === k) savedKey.value = null; }, 1500);
        },
    });
}

/* Hapus data faktur satu baris. Yang dihapus HANYA No. Faktur Pajak & NPWP-nya — barisnya
   sendiri milik buku besar (ia mutasi akun PPN Keluaran) dan tidak bisa dihapus dari sini.
   Konfirmasinya menyebut hal itu terang-terangan supaya tidak ada yang mengira jurnalnya
   ikut terhapus. */
function hapusRow(r: any) {
    if (! confirm(`Hapus No. Faktur Pajak${isJurnal(r) ? ' & NPWP' : ''} untuk baris ini?\n\n${r.partner} · ${fmtRp(r.ppn)}\n\n`
        + 'Barisnya TETAP ada — ia mutasi akun PPN Keluaran di buku besar — dan siap diisi ulang.')) return;
    router.post(route('bs.tax.baris.hapus'), { row_type: r.row_type, journal_line_id: r.id }, {
        preserveScroll: true,
        onSuccess: () => { r.tax_invoice_no = null; r.npwp = null; delete editedNsfp.value[rowKey(r)]; delete editedNpwp.value[rowKey(r)]; },
    });
}

/* ── Konversi UTJ & DP → Uang Muka Penjualan ──
   Satu-satunya pintu konversi ada di layar ini. Karena itu entri UTJ & DP yang belum berjurnal
   tetap ditampilkan walaupun daftarnya sudah dibentuk dari mutasi akun PPN Keluaran — sesudah
   dikonversi, ia muncul sebagai baris jurnal biasa. */
const converting = ref<number | null>(null);
function konversi(r: any) {
    if (!confirm(`Konversi ${r.doc_no ?? 'UTJ & DP'} (${fmtRp((Number(r.dpp) || 0) + (Number(r.ppn) || 0))}) ke Uang Muka Penjualan?\nJurnal reklasifikasi (Dr Hutang Usaha / Cr Uang Muka Penjualan + PPN Keluaran) akan diposting ke GL, lalu Nomor Faktur Pajak bisa diisi.`)) return;
    converting.value = r.id;
    router.post(route('bs.tax.entry.konversi', r.id), {}, { preserveScroll: true, onFinish: () => { converting.value = null; } });
}

// ── Input Massal FP & NPWP ──
const showBulk = ref(false);
const bulkSaving = ref(false);
const bulk = reactive<Record<string, { tax_invoice_no: string; npwp: string }>>({});
function openBulk() {
    barisTerpilih.value.forEach((r: any) => { bulk[rowKey(r)] = { tax_invoice_no: r.tax_invoice_no ?? '', npwp: r.npwp ?? '' }; });
    showBulk.value = true;
}
function saveBulk() {
    // HANYA baris yang tercentang yang dikirim — mengirim seluruh baris akan menimpa nomor
    // faktur pada baris yang sengaja tidak ikut dipilih.
    const items = barisTerpilih.value.map((r: any) => ({
        row_type: r.row_type, id: r.id,
        tax_invoice_no: bulk[rowKey(r)]?.tax_invoice_no ?? '',
        npwp: bulk[rowKey(r)]?.npwp ?? '',
    }));
    if (!items.length) return;
    bulkSaving.value = true;
    router.post(route('bs.tax.keluaran.bulk'), { items }, {
        preserveScroll: true,
        onSuccess: () => { showBulk.value = false; },
        onFinish: () => { bulkSaving.value = false; },
    });
}

/* ── Impor FP & NPWP dari berkas ──
   Formatnya diunduh dari tombol di sebelahnya dan SUDAH berisi baris masa pajak ini beserta
   kolom JENIS_BARIS & ID_BARIS. Kedua kolom itulah yang mengikat baris di berkas dengan baris
   di sistem, jadi jangan dihapus saat mengisi. */
const berkasImpor = ref<HTMLInputElement | null>(null);
const mengimpor = ref(false);
function pilihBerkas() { berkasImpor.value?.click(); }
function unggahImpor(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    mengimpor.value = true;
    router.post(route('bs.tax.keluaran.impor'), { file }, {
        forceFormData: true, preserveScroll: true,
        onFinish: () => { mengimpor.value = false; input.value = ''; },
    });
}

const inputCls = 'h-8 w-44 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';
const npwpInputCls = 'h-8 w-40 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';
const fCls = 'h-7 w-full rounded border border-neutral-300 bg-white px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.tax.rekap', { year: period.year, month: period.month })" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Rekap PPN</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ArrowUpCircle class="h-6 w-6 text-success-600" /> Faktur Pajak Keluaran <span class="text-base font-normal text-neutral-400">(PPN Penjualan)</span></h1>
            <div class="flex flex-wrap gap-2">
                <select v-model.number="month" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select>
                <select v-model.number="year" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <a :href="route('bs.tax.export.keluaran.xlsx', unduhParam)" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
                <a :href="route('bs.tax.export.keluaran', unduhParam)" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> CSV</a>
                <a :href="route('bs.tax.efaktur.csv', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur CSV</a>
                <a :href="route('bs.tax.efaktur.xml', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur XML</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total DPP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(totals.dpp) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total PPN Keluaran</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(totals.ppn) }}</div><div class="mt-1 text-[11px] text-neutral-400">Mutasi akun 20104040406</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum ber-No. Faktur</div><div class="mt-1 text-xl font-bold text-warning-600">{{ totals.tanpa_fp_count }}</div><div class="mt-1 text-[11px] text-neutral-400">PPN {{ fmtRp(totals.tanpa_fp_ppn) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Baris</div><div class="mt-1 text-xl font-bold">{{ totals.count }}</div><div class="mt-1 text-[11px] text-neutral-400">{{ totals.jurnal_count }} baris jurnal · {{ totals.entry_count }} entri approval</div></div>
        </div>

        <!-- Toolbar seleksi & impor -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ filtered.length }} dari {{ rows.length }} baris<span v-if="barisTerpilih.length"> · <span class="font-semibold text-primary-600">{{ barisTerpilih.length }} dipilih</span></span></p>
            <div class="flex flex-wrap gap-2">
                <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
                <a :href="route('bs.tax.keluaran.format', { year: period.year, month: period.month })" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><Download class="h-3.5 w-3.5" /> Format Impor</a>
                <input ref="berkasImpor" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" @change="unggahImpor" />
                <Button size="sm" variant="ghost" :loading="mengimpor" @click="pilihBerkas"><template #icon-left><Upload class="h-4 w-4" /></template>Impor FP &amp; NPWP</Button>
                <Button size="sm" variant="primary" :disabled="!barisTerpilih.length" @click="openBulk"><template #icon-left><ListChecks class="h-4 w-4" /></template>Input Massal FP &amp; NPWP ({{ barisTerpilih.length }})</Button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="w-10 px-3 py-3 text-center"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="h-4 w-4 rounded border-neutral-300" title="Pilih semua baris untuk Input Massal" /></th>
                        <th class="px-4 py-3">No. Faktur Pajak</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">NPWP</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">No. Dokumen / Unit</th><th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th><th class="px-4 py-3 text-center">Aksi</th>
                    </tr>
                    <!-- Filter per-kolom (ketik untuk mencari) -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><input v-model="f.fp" :class="fCls" placeholder="Cari no. FP…" /></th>
                        <th class="px-2 py-2"><input v-model="f.sumber" :class="fCls" placeholder="Kas/Bank / Memorial…" /></th>
                        <th class="px-2 py-2"><input v-model="f.date" :class="fCls" placeholder="Tanggal…" /></th>
                        <th class="px-2 py-2"><input v-model="f.npwp" :class="fCls" placeholder="NPWP…" /></th>
                        <th class="px-2 py-2"><input v-model="f.nama" :class="fCls" placeholder="Nama…" /></th>
                        <th class="px-2 py-2"><input v-model="f.dok" :class="fCls" placeholder="No. dok / unit…" /></th>
                        <th class="px-2 py-2"><input v-model="f.dpp" :class="[fCls, 'text-right']" placeholder="DPP…" /></th>
                        <th class="px-2 py-2"><input v-model="f.ppn" :class="[fCls, 'text-right']" placeholder="PPN…" /></th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="rowKey(r)" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50" :class="isSelected(r) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''">
                        <td class="px-3 py-2 text-center"><input type="checkbox" :checked="isSelected(r)" @change="toggle(r)" class="h-4 w-4 rounded border-neutral-300" title="Ikutkan baris ini pada Input Massal" /></td>
                        <td class="px-4 py-2">
                            <div v-if="r.editable" class="flex items-center gap-1"><input :value="currentNo(r)" @input="editedNsfp[rowKey(r)] = ($event.target as HTMLInputElement).value" @keyup.enter="saveRow(r)" placeholder="000.000-00.00000000" :class="inputCls" /><Check v-if="savedKey === rowKey(r)" class="h-4 w-4 text-success-500" /></div>
                            <span v-else class="text-xs italic text-amber-600 dark:text-amber-400">— konversi dulu —</span>
                        </td>
                        <td class="px-4 py-2.5"><Badge :variant="sumberVariant(r)" size="sm">{{ sumberLabel(r) }}</Badge></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <!-- NPWP hanya bisa diisi pada baris jurnal: entri approval yang belum
                             berjurnal tidak punya tempat menyimpannya. -->
                        <td class="px-4 py-2">
                            <input v-if="isJurnal(r)" :value="currentNpwp(r)" @input="editedNpwp[rowKey(r)] = ($event.target as HTMLInputElement).value" @keyup.enter="saveRow(r)" placeholder="NPWP lawan transaksi" :class="npwpInputCls" />
                            <span v-else class="font-mono text-xs text-neutral-400">—</span>
                        </td>
                        <td class="px-4 py-2.5">{{ r.partner }}</td>
                        <!-- Nomor dokumen bertaut ke JURNALNYA, sebab baris ini memang baris jurnal. -->
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500"><Link v-if="r.journal_id" :href="route('bs.gl.journal.show', r.journal_id)" class="text-primary-600 hover:underline">{{ r.doc_no }}</Link><span v-else>{{ r.doc_no }}</span></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.dpp) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(r.ppn) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center justify-end gap-1">
                                <Button v-if="r.needs_konversi" size="sm" variant="warning" :loading="converting === r.id" @click="konversi(r)"><template #icon-left><ArrowRightLeft class="h-4 w-4" /></template>Konversi ke Uang Muka</Button>
                                <Button v-if="r.editable" variant="success" size="sm" @click="saveRow(r)">Simpan</Button>
                                <!-- Hapus hanya muncul bila baris ini MEMANG punya data faktur.
                                     Menawarkan "hapus" pada baris yang belum diisi hanya tombol
                                     yang tidak mengerjakan apa pun. -->
                                <button v-if="r.tax_invoice_no || r.npwp" type="button" @click="hapusRow(r)"
                                        class="inline-flex h-8 items-center rounded-lg border border-danger-300 px-2 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700"
                                        title="Hapus No. Faktur Pajak &amp; NPWP baris ini">Hapus</button>
                                <span v-if="!r.needs_konversi && !r.editable && !r.tax_invoice_no" class="text-neutral-300">—</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="filtered.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800"><td colspan="7" class="px-4 py-2.5 text-right">Total ({{ filtered.length }} baris)</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totF.dpp) }}</td><td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(totF.ppn) }}</td><td></td></tr></tfoot>
            </table>
            <EmptyState v-if="!filtered.length" title="Belum ada faktur keluaran" description="Tidak ada mutasi akun PPN Keluaran (20104040406) pada masa pajak & filter ini." />
        </div>

        <!-- Modal Input Massal FP & NPWP -->
        <div v-if="showBulk" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showBulk = false">
            <div class="flex max-h-[85vh] w-full max-w-4xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <h2 class="flex items-center gap-2 text-lg font-bold"><ListChecks class="h-5 w-5 text-primary-600" /> Input Massal FP &amp; NPWP <span class="text-sm font-normal text-neutral-400">{{ months[period.month - 1] }} {{ period.year }}</span></h2>
                    <button class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200" @click="showBulk = false"><X class="h-5 w-5" /></button>
                </div>
                <div class="flex-1 overflow-y-auto px-5 py-3">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-2 py-2">Nama</th><th class="px-2 py-2">No. Dok / Unit</th><th class="px-2 py-2">No. Faktur Pajak</th><th class="px-2 py-2">NPWP</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <tr v-for="r in barisTerpilih" :key="rowKey(r)">
                                <td class="px-2 py-2">{{ r.partner }}</td>
                                <td class="px-2 py-2 font-mono text-xs text-neutral-500">{{ r.doc_no }}</td>
                                <td class="px-2 py-2"><input v-model="bulk[rowKey(r)].tax_invoice_no" placeholder="000.000-00.00000000" :class="inputCls" /></td>
                                <td class="px-2 py-2">
                                    <input v-if="isJurnal(r)" v-model="bulk[rowKey(r)].npwp" placeholder="NPWP lawan transaksi" :class="npwpInputCls" />
                                    <span v-else class="text-xs text-neutral-400">— entri approval —</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <Button variant="ghost" @click="showBulk = false">Batal</Button>
                    <Button variant="primary" :disabled="bulkSaving" @click="saveBulk"><Check class="h-4 w-4" /> {{ bulkSaving ? 'Menyimpan…' : 'Simpan Semua' }}</Button>
                </div>
            </div>
        </div>
    </div>
</template>
