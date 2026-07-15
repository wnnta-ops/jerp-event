<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpCircle, Download, Check, ArrowRightLeft, Save, RotateCcw } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    period: { year: number; month: number };
    totals: { dpp: number; ppn: number; count: number; ar_ppn: number; prop_ppn: number; ret_ppn: number; utj_ppn: number; penc_ppn: number };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.keluaran'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

// ── Meta per baris ──
function rowKey(r: any) { return r.source + '-' + r.id; }
function isEntry(r: any) { return !!r.is_entry; }
function needsKonversi(r: any) { return r.source === 'utj_dp' && !r.converted; }
// Sumber data = modul asal entri. Untuk entri approval, sebut nama modul approval-nya
// (retensi & pencairan sama-sama disetujui di halaman Approval Retensi).
function sumberLabel(r: any) { return ({ ar: 'AR', properti: 'Properti', retensi: 'Approval Retensi', pencairan: 'Approval Pencairan', utj_dp: 'Approval UM Penjualan' } as Record<string,string>)[r.source] ?? r.source; }
function sumberVariant(r: any) { return ({ ar: 'neutral', properti: 'primary', retensi: 'info', pencairan: 'success', utj_dp: 'warning' } as Record<string,any>)[r.source] ?? 'neutral'; }

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
const totF = computed(() => ({ dpp: filtered.value.reduce((s, r) => s + Number(r.dpp || 0), 0), ppn: filtered.value.reduce((s, r) => s + Number(r.ppn || 0), 0) }));

// ── Checkbox seleksi (pojok kiri) ──
const selectedKeys = ref<string[]>([]);
function isSelected(r: any) { return selectedKeys.value.includes(rowKey(r)); }
function toggle(r: any) { const k = rowKey(r); const i = selectedKeys.value.indexOf(k); if (i >= 0) selectedKeys.value.splice(i, 1); else selectedKeys.value.push(k); }
const allSelected = computed(() => filtered.value.length > 0 && filtered.value.every(isSelected));
function toggleAll() {
    if (allSelected.value) { const keys = new Set(filtered.value.map(rowKey)); selectedKeys.value = selectedKeys.value.filter((k) => !keys.has(k)); }
    else { const cur = new Set(selectedKeys.value); filtered.value.forEach((r) => cur.add(rowKey(r))); selectedKeys.value = Array.from(cur); }
}
const selectedRows = computed(() => filtered.value.filter(isSelected));
const selectedEditable = computed(() => selectedRows.value.filter((r) => r.editable));

// ── Edit & simpan Nomor Faktur Pajak ──
const edited = ref<Record<string, string>>({});
const savedKey = ref<string | null>(null);
const bulkSaving = ref(false);
function currentNo(r: any) { const k = rowKey(r); return edited.value[k] ?? r.tax_invoice_no ?? ''; }
function saveNsfp(r: any) {
    const k = rowKey(r);
    const val = currentNo(r);
    if (val === (r.tax_invoice_no ?? '')) return;
    const url = isEntry(r) ? route('bs.tax.entry.nsfp') : route('bs.tax.nsfp');
    const payload = isEntry(r) ? { entry_id: r.id, tax_invoice_no: val } : { type: 'keluaran', invoice_id: r.id, tax_invoice_no: val };
    router.post(url, payload, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { r.tax_invoice_no = val; savedKey.value = k; setTimeout(() => { if (savedKey.value === k) savedKey.value = null; }, 1500); },
    });
}
function saveSelected() {
    const items = selectedEditable.value.map((r) => ({ type: isEntry(r) ? 'entry' : 'ar', id: r.id, tax_invoice_no: currentNo(r) }));
    if (!items.length) { alert('Pilih baris yang bisa diisi Nomor Faktur Pajak (baris UTJ & DP harus dikonversi dulu).'); return; }
    bulkSaving.value = true;
    router.post(route('bs.tax.keluaran.bulk'), { items }, { preserveScroll: true, onSuccess: () => window.location.reload(), onFinish: () => { bulkSaving.value = false; } });
}

// ── Konversi UTJ & DP → Uang Muka Penjualan ──
const converting = ref<number | null>(null);
function konversi(r: any) {
    if (!confirm(`Konversi ${r.doc_no ?? 'UTJ & DP'} (${fmtRp(r.dpp + r.ppn || 0)}) ke Uang Muka Penjualan?\nJurnal reklasifikasi (Dr Hutang Usaha / Cr Uang Muka Penjualan + PPN Keluaran) akan diposting ke GL, lalu Nomor Faktur Pajak bisa diisi.`)) return;
    converting.value = r.id;
    router.post(route('bs.tax.entry.konversi', r.id), {}, { preserveScroll: true, onSuccess: () => window.location.reload(), onFinish: () => { converting.value = null; } });
}

const inputCls = 'h-8 w-44 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';
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
                <a :href="route('bs.tax.export.keluaran', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> CSV</a>
                <a :href="route('bs.tax.efaktur.csv', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur CSV</a>
                <a :href="route('bs.tax.efaktur.xml', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-accent-300 px-3 text-sm font-medium text-accent-700 hover:bg-accent-50 dark:border-accent-700 dark:text-accent-300"><Download class="h-4 w-4" /> e-Faktur XML</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total DPP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(totals.dpp) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total PPN Keluaran</div><div class="mt-1 text-xl font-bold text-success-600">{{ fmtRp(totals.ppn) }}</div><div class="mt-1 text-[11px] text-neutral-400">AR {{ fmtRp(totals.ar_ppn) }} · Properti {{ fmtRp(totals.prop_ppn) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">PPN Retensi / Pencairan / UTJ &amp; DP</div><div class="mt-1 text-xl font-bold text-info-600">{{ fmtRp(totals.ret_ppn + (totals.penc_ppn || 0) + totals.utj_ppn) }}</div><div class="mt-1 text-[11px] text-neutral-400">Retensi {{ fmtRp(totals.ret_ppn) }} · Pencairan {{ fmtRp(totals.penc_ppn || 0) }} · UTJ&amp;DP {{ fmtRp(totals.utj_ppn) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Faktur</div><div class="mt-1 text-xl font-bold">{{ totals.count }}</div></div>
        </div>

        <!-- Toolbar seleksi -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-neutral-500">{{ filtered.length }} dari {{ rows.length }} faktur<span v-if="selectedKeys.length"> · <span class="font-semibold text-primary-600">{{ selectedKeys.length }} dipilih</span></span></p>
            <div class="flex gap-2">
                <button @click="resetFilter" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset Filter</button>
                <Button v-if="selectedKeys.length" size="sm" variant="primary" :loading="bulkSaving" @click="saveSelected"><template #icon-left><Save class="h-4 w-4" /></template>Simpan No. FP Terpilih ({{ selectedEditable.length }})</Button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="w-10 px-3 py-3 text-center"><input type="checkbox" :checked="allSelected" @change="toggleAll" class="h-4 w-4 rounded border-neutral-300" /></th>
                        <th class="px-4 py-3">No. Faktur Pajak</th><th class="px-4 py-3">Sumber</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">NPWP</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">No. Dokumen / Unit</th><th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th><th class="px-4 py-3 text-center">Aksi</th>
                    </tr>
                    <!-- Filter per-kolom (ketik untuk mencari) -->
                    <tr class="border-t border-neutral-100 dark:border-neutral-800/60">
                        <th class="px-2 py-2"></th>
                        <th class="px-2 py-2"><input v-model="f.fp" :class="fCls" placeholder="Cari no. FP…" /></th>
                        <th class="px-2 py-2"><input v-model="f.sumber" :class="fCls" placeholder="AR / Retensi…" /></th>
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
                        <td class="px-3 py-2 text-center"><input type="checkbox" :checked="isSelected(r)" @change="toggle(r)" class="h-4 w-4 rounded border-neutral-300" /></td>
                        <td class="px-4 py-2">
                            <div v-if="r.editable" class="flex items-center gap-1"><input :value="currentNo(r)" @input="edited[rowKey(r)] = ($event.target as HTMLInputElement).value" @blur="saveNsfp(r)" @keyup.enter="saveNsfp(r)" placeholder="000.000-00.00000000" :class="inputCls" /><Check v-if="savedKey === rowKey(r)" class="h-4 w-4 text-success-500" /></div>
                            <span v-else-if="needsKonversi(r)" class="text-xs italic text-amber-600 dark:text-amber-400">— konversi dulu —</span>
                            <span v-else class="font-mono text-xs text-neutral-600 dark:text-neutral-300">{{ r.tax_invoice_no ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-2.5"><Badge :variant="sumberVariant(r)" size="sm">{{ sumberLabel(r) }}</Badge></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.npwp }}</td>
                        <td class="px-4 py-2.5">{{ r.partner }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500"><Link v-if="r.source === 'ar'" :href="route('bs.ar.show', r.id)" class="text-primary-600 hover:underline">{{ r.doc_no }}</Link><span v-else>{{ r.doc_no }}</span></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.dpp) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(r.ppn) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            <Button v-if="needsKonversi(r)" size="sm" variant="warning" :loading="converting === r.id" @click="konversi(r)"><template #icon-left><ArrowRightLeft class="h-4 w-4" /></template>Konversi ke Uang Muka</Button>
                            <span v-else-if="r.source === 'utj_dp' && r.converted" class="text-[11px] text-success-600">✓ Dikonversi</span>
                            <span v-else class="text-neutral-300">—</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="filtered.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800"><td colspan="7" class="px-4 py-2.5 text-right">Total ({{ filtered.length }} baris)</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totF.dpp) }}</td><td class="px-4 py-2.5 text-right font-mono text-success-600">{{ fmtRp(totF.ppn) }}</td><td></td></tr></tfoot>
            </table>
            <EmptyState v-if="!filtered.length" title="Belum ada faktur keluaran" description="Tidak ada faktur ber-PPN atau entri Retensi/UTJ & DP pada masa pajak & filter ini." />
        </div>
    </div>
</template>
