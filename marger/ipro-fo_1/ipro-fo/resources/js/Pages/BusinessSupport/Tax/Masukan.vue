<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowDownCircle, Download, FileSpreadsheet, Check, ListChecks, X } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    rows: Array<any>;
    period: { year: number; month: number };
    totals: { dpp: number; ppn: number; count: number };
}>();

const year = ref(props.period.year);
const month = ref(props.period.month);
const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const years = Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i);
function applyPeriod() { router.get(route('bs.tax.masukan'), { year: year.value, month: month.value }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }

const editedNsfp = ref<Record<number, string>>({});
const editedNpwp = ref<Record<number, string>>({});
const savedId = ref<number | null>(null);
function saveRow(row: any) {
    const nsfp = editedNsfp.value[row.id] ?? row.tax_invoice_no ?? '';
    const npwp = editedNpwp.value[row.id] ?? row.npwp ?? '';
    const npwpClean = npwp ?? '';
    if (nsfp === (row.tax_invoice_no ?? '') && npwpClean === (row.npwp ?? '')) return;
    router.post(route('bs.tax.nsfp'), { type: 'masukan', invoice_id: row.id, tax_invoice_no: nsfp, npwp: npwpClean }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => {
            row.tax_invoice_no = nsfp;
            row.npwp = npwpClean || null;
            savedId.value = row.id;
            setTimeout(() => { if (savedId.value === row.id) savedId.value = null; }, 1500);
        },
    });
}
const inputCls = 'h-8 w-44 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';
const npwpInputCls = 'h-8 w-40 rounded-lg border border-neutral-300 bg-white px-2 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900';

/* Hapus data faktur satu baris. Yang dihapus HANYA No. Faktur Pajak & NPWP-nya — barisnya
   sendiri milik buku besar (ia mutasi akun PPN Masukan) dan tidak bisa dihapus dari sini.
   Karena itu konfirmasinya menyebutkan hal itu terang-terangan, supaya tidak ada yang mengira
   jurnalnya ikut terhapus. */
function hapusRow(row: any) {
    if (! confirm(`Hapus No. Faktur Pajak & NPWP untuk baris ini?\n\n${row.partner} · ${fmtRp(row.ppn)}\n\n`
        + 'Barisnya TETAP ada — ia mutasi akun PPN Masukan di buku besar — dan siap diisi ulang.')) return;
    router.post(route('bs.tax.baris.hapus'), { row_type: 'jurnal', journal_line_id: row.id }, {
        preserveScroll: true,
        onSuccess: () => { row.tax_invoice_no = null; row.npwp = null; delete editedNsfp.value[row.id]; delete editedNpwp.value[row.id]; },
    });
}

/* === Pilih baris untuk Input Massal ===
   Bawaannya SELURUH baris tercentang: masa pajak biasanya diisi sekaligus, dan meminta orang
   mencentang satu per satu lebih dulu hanya menambah langkah untuk perkara yang lazim. Yang
   perlu dikecualikan tinggal dilepas centangnya. */
const dipilih = ref<number[]>(props.rows.map((r: any) => Number(r.id)));
watch(() => props.rows, (rows) => { dipilih.value = (rows ?? []).map((r: any) => Number(r.id)); });
const semuaTercentang = computed<boolean>({
    get: () => props.rows.length > 0 && dipilih.value.length === props.rows.length,
    set: (v: boolean) => { dipilih.value = v ? props.rows.map((r: any) => Number(r.id)) : []; },
});
const barisTerpilih = computed(() => props.rows.filter((r: any) => dipilih.value.includes(Number(r.id))));

// === Input Massal FP & NPWP ===
const showBulk = ref(false);
const bulkSaving = ref(false);
const bulk = reactive<Record<number, { tax_invoice_no: string; npwp: string }>>({});
function openBulk() {
    barisTerpilih.value.forEach((r: any) => {
        bulk[r.id] = { tax_invoice_no: r.tax_invoice_no ?? '', npwp: r.npwp ?? '' };
    });
    showBulk.value = true;
}
function saveBulk() {
    bulkSaving.value = true;
    // HANYA baris yang tercentang yang dikirim — mengirim seluruh baris akan menimpa nomor
    // faktur pada baris yang sengaja tidak ikut dipilih.
    const items = barisTerpilih.value.map((r: any) => ({
        invoice_id: r.id,
        tax_invoice_no: bulk[r.id]?.tax_invoice_no ?? '',
        npwp: bulk[r.id]?.npwp ?? '',
    }));
    router.post(route('bs.tax.masukan.bulk'), { items }, {
        preserveScroll: true,
        onSuccess: () => { showBulk.value = false; },
        onFinish: () => { bulkSaving.value = false; },
    });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ArrowDownCircle class="h-6 w-6 text-danger-600" /> Faktur Pajak Masukan <span class="text-base font-normal text-neutral-400">(PPN Pembelian)</span></h1>
            <div class="flex flex-wrap items-center gap-2">
                <select v-model.number="month" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="(m,i) in months" :key="i" :value="i+1">{{ m }}</option></select>
                <select v-model.number="year" @change="applyPeriod" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                <Button variant="primary" size="sm" :disabled="!barisTerpilih.length" @click="openBulk"><ListChecks class="h-4 w-4" /> Input Massal FP &amp; NPWP ({{ barisTerpilih.length }})</Button>
                <a :href="route('bs.tax.export.masukan', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700"><Download class="h-4 w-4" /> CSV</a>
                <a :href="route('bs.tax.export.masukan.xlsx', { year: period.year, month: period.month })" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-success-300 px-3 text-sm font-medium text-success-700 hover:bg-success-50 dark:border-success-700 dark:text-success-400"><FileSpreadsheet class="h-4 w-4" /> Excel</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total DPP</div><div class="mt-1 text-xl font-bold">{{ fmtRp(totals.dpp) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total PPN Masukan</div><div class="mt-1 text-xl font-bold text-danger-600">{{ fmtRp(totals.ppn) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Jumlah Faktur</div><div class="mt-1 text-xl font-bold">{{ totals.count }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3"><input v-model="semuaTercentang" type="checkbox" :disabled="!rows.length" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" title="Pilih semua baris untuk Input Massal" /></th>
                    <th class="px-4 py-3">No. Faktur Pajak</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">NPWP</th><th class="px-4 py-3">Nama Supplier</th><th class="px-4 py-3">No. Inv. Supplier</th><th class="px-4 py-3 text-right">DPP</th><th class="px-4 py-3 text-right">PPN</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2"><input v-model="dipilih" type="checkbox" :value="Number(r.id)" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" :title="'Ikutkan baris ini pada Input Massal'" /></td>
                        <td class="px-4 py-2"><input :value="editedNsfp[r.id] ?? r.tax_invoice_no ?? ''" @input="editedNsfp[r.id] = ($event.target as HTMLInputElement).value" @keyup.enter="saveRow(r)" placeholder="000.000-00.00000000" :class="inputCls" /></td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.invoice_date) }}</td>
                        <td class="px-4 py-2"><input :value="editedNpwp[r.id] ?? r.npwp ?? ''" @input="editedNpwp[r.id] = ($event.target as HTMLInputElement).value" @keyup.enter="saveRow(r)" placeholder="NPWP supplier" :class="npwpInputCls" /></td>
                        <td class="px-4 py-2.5">{{ r.partner }}</td>
                        <!-- Nomor invoice supplier bila ada; tautannya ke JURNALNYA, sebab baris
                             ini memang baris jurnal — sebagian di antaranya tidak berinvoice. -->
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-500">{{ r.supplier_invoice_no ?? '—' }} · <Link :href="route('bs.gl.journal.show', r.journal_id)" class="text-primary-600 hover:underline">{{ r.invoice_number }}</Link></td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.dpp) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(r.ppn) }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center justify-end gap-1">
                                <Button variant="success" size="sm" @click="saveRow(r)">Simpan</Button>
                                <!-- Hapus hanya muncul bila baris ini MEMANG punya data faktur.
                                     Menawarkan "hapus" pada baris yang belum diisi hanya tombol
                                     yang tidak mengerjakan apa pun. -->
                                <button v-if="r.tax_invoice_no || r.npwp" type="button" @click="hapusRow(r)"
                                        class="inline-flex h-8 items-center rounded-lg border border-danger-300 px-2 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700"
                                        title="Hapus No. Faktur Pajak &amp; NPWP baris ini">Hapus</button>
                                <Check v-if="savedId === r.id" class="h-4 w-4 text-success-500" />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length"><tr class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800"><td colspan="6" class="px-4 py-2.5 text-right">Total</td><td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(totals.dpp) }}</td><td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(totals.ppn) }}</td><td></td></tr></tfoot>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada faktur masukan" description="Tidak ada mutasi akun PPN Masukan (10105010101) pada masa pajak ini." />
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
                            <th class="px-2 py-2">Supplier</th><th class="px-2 py-2">No. Inv.</th><th class="px-2 py-2">No. Faktur Pajak</th><th class="px-2 py-2">NPWP</th>
                        </tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            <!-- HANYA baris yang tercentang. Kalau seluruh rows ditampilkan,
                                 bulk[r.id] baris yang tak terpilih tidak pernah dibentuk di
                                 openBulk() dan modalnya gagal saat dibuka. -->
                            <tr v-for="r in barisTerpilih" :key="r.id">
                                <td class="px-2 py-2">{{ r.partner }}</td>
                                <td class="px-2 py-2 font-mono text-xs text-neutral-500">{{ r.supplier_invoice_no ?? r.invoice_number }}</td>
                                <td class="px-2 py-2"><input v-model="bulk[r.id].tax_invoice_no" placeholder="000.000-00.00000000" :class="inputCls" /></td>
                                <td class="px-2 py-2"><input v-model="bulk[r.id].npwp" placeholder="NPWP supplier" :class="npwpInputCls" /></td>
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
