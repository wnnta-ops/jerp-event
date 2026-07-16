<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, FileText, Link2, Users } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

interface OpenInvoice { id: number; invoice_number: string; supplier_id: number; description: string | null; outstanding: number; due_date: string | null }
const props = defineProps<{
    bankAccounts: Array<any>;
    types: Record<string, string>;
    pool?: Record<string, string[]>;
    suppliers?: Array<any>;
    openInvoices?: OpenInvoice[];
}>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    cheque_type: 'cek', cheque_purpose: 'hutang', cheque_number: '', bank_account_id: '', supplier_id: '', payee: '',
    amount: 0, cheque_date: today, signer_name: '', signer_name_2: '',
    recipient_bank: '', recipient_account_no: '', description: '',
    lines: [] as Array<{ ap_invoice_id: number; amount: number }>,
});
// Tujuan warkat: 'hutang' (penerima Supplier/Vendor + pelunasan hutang) | 'non_hutang' (penerima pihak ketiga).
const isHutang = computed(() => form.cheque_purpose === 'hutang');
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.cheque-out.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

// #70 datalist nomor warkat yang dimiliki (belum diterbitkan), difilter per bank terpilih.
const poolForBank = computed<string[]>(() => {
    const p = props.pool ?? {};
    const id = form.bank_account_id;
    return (p[String(id)] ?? (p as any)[id] ?? []) as string[];
});

// ── Referensi pelunasan hutang AP: pilih supplier → centang invoice yang dilunasi ──
// Pelunasan benar-benar terjadi saat BG/Cek DISERAHKAN ke supplier (sinkron Invoice/Kartu Hutang).
const picks = ref<Record<number, { checked: boolean; amount: number }>>({});
const supplierInvoices = computed<OpenInvoice[]>(() =>
    form.supplier_id ? (props.openInvoices ?? []).filter((i) => i.supplier_id === Number(form.supplier_id)) : []);

const selectedLines = computed(() =>
    supplierInvoices.value
        .filter((i) => picks.value[i.id]?.checked && Number(picks.value[i.id]?.amount) > 0)
        .map((i) => ({ ap_invoice_id: i.id, amount: Number(picks.value[i.id].amount) })));
const hasLines = computed(() => selectedLines.value.length > 0);
const linesTotal = computed(() => selectedLines.value.reduce((s, l) => s + l.amount, 0));
// Baris dicentang tapi nominal kosong/<=0/melebihi sisa → tandai & blokir simpan (cegah drop diam-diam).
function pickInvalid(inv: OpenInvoice): boolean {
    const p = picks.value[inv.id];
    if (!p?.checked) return false;
    const a = Number(p.amount);
    return !(a > 0 && a <= inv.outstanding + 0.01);
}
const hasInvalidPick = computed<boolean>(() => supplierInvoices.value.some((i) => pickInvalid(i)));

// Ganti tujuan → bersihkan field lawan (cegah data nyangkut antar mode).
watch(() => form.cheque_purpose, (mode) => {
    picks.value = {};
    form.lines = [];
    form.supplier_id = '';
    form.payee = '';
    if (mode === 'non_hutang') {
        form.amount = 0;
        // Keterangan tak lagi tampil di mode ini → jangan biarkan nilainya ikut tersimpan diam-diam.
        form.description = '';
    }
});
// Ganti supplier → reset pilihan invoice & isi nama penerima dari supplier (mode hutang).
watch(() => form.supplier_id, () => {
    picks.value = {};
    const sup = (props.suppliers ?? []).find((s) => Number(s.id) === Number(form.supplier_id));
    if (sup) form.payee = sup.name;
});
// Sinkronkan baris + kunci nominal warkat = total invoice yang dilunasi.
watch([selectedLines, linesTotal], () => {
    form.lines = selectedLines.value;
    if (hasLines.value) form.amount = Math.round(linesTotal.value * 100) / 100;
}, { deep: true });

function toggle(inv: OpenInvoice) {
    const cur = picks.value[inv.id];
    if (cur?.checked) { picks.value[inv.id] = { checked: false, amount: 0 }; }
    else { picks.value[inv.id] = { checked: true, amount: inv.outstanding }; }
}
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> Tambah BG/Cek Keluar</h1>
            <Link :href="route('bs.cheque-out.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <!-- Tujuan warkat: atas pelunasan hutang (penerima Supplier/Vendor) vs tidak berhubungan hutang (pihak ketiga) -->
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tujuan BG/Cek *</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button type="button" @click="form.cheque_purpose = 'hutang'"
                            :class="['flex items-center gap-2 rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition', isHutang ? 'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-500 dark:bg-primary-900/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800']">
                            <Link2 class="h-4 w-4 shrink-0" /> BG/Cek atas Pelunasan Hutang
                        </button>
                        <button type="button" @click="form.cheque_purpose = 'non_hutang'"
                            :class="['flex items-center gap-2 rounded-lg border px-3 py-2.5 text-left text-sm font-medium transition', !isHutang ? 'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-500 dark:bg-primary-900/30 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800']">
                            <Users class="h-4 w-4 shrink-0" /> BG/Cek tidak berhubungan Hutang
                        </button>
                    </div>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jenis *</label><select v-model="form.cheque_type" :class="inputCls"><option v-for="(label,val) in types" :key="val" :value="val">{{ label }}</option></select></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Rekening Bank Perusahaan (Penerbit) *</label><SearchSelect v-model="form.bank_account_id" :options="bankAccounts" placeholder="— pilih rekening bank —" /><p v-if="form.errors.bank_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.bank_account_id }}</p></div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">No. BG/Cek *</label>
                    <input v-model="form.cheque_number" list="cheque-pool" required placeholder="ketik / pilih no. warkat" :class="[inputCls,'font-mono']" />
                    <datalist id="cheque-pool"><option v-for="n in poolForBank" :key="n" :value="n" /></datalist>
                    <p class="mt-1 text-xs text-neutral-400">Pilih dari nomor warkat dimiliki (sesuai bank) atau ketik nomor baru.</p>
                    <p v-if="form.errors.cheque_number" class="mt-1 text-xs text-danger-500">{{ form.errors.cheque_number }}</p>
                </div>
                <!-- Opsional: warkat sering disiapkan dulu, tanggalnya ditulis saat diserahkan. -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal BG/Cek</label>
                    <input v-model="form.cheque_date" type="date" :class="inputCls" />
                    <p v-if="form.errors.cheque_date" class="mt-1 text-xs text-danger-500">{{ form.errors.cheque_date }}</p>
                    <p v-else-if="!form.cheque_date" class="mt-1 text-xs text-neutral-400">Boleh dikosongkan — tapi warkat tanpa tanggal tidak muncul di Kalender Cek Giro.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label>
                    <input v-model.number="form.amount" type="number" step="1" min="1" required :readonly="hasLines" :class="[inputCls,'text-right font-mono', hasLines ? 'bg-neutral-100 dark:bg-neutral-800' : '']" />
                    <p v-if="hasLines" class="mt-1 text-xs text-neutral-400">Terkunci = total invoice yang dilunasi.</p>
                    <p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p>
                </div>
                <!-- Penerima: pop up Supplier/Vendor (mode hutang) atau Pihak Ketiga bebas (mode non-hutang) -->
                <div v-if="isHutang">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Penerima (Supplier/Vendor)</label>
                    <SearchSelect v-model="form.supplier_id" :options="suppliers ?? []" placeholder="— pilih supplier/vendor —" />
                    <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p>
                    <p v-else-if="!form.supplier_id" class="mt-1 text-xs text-neutral-400">Boleh dikosongkan — penerima dicetak blank & diisi tangan.</p>
                </div>
                <div v-else>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Penerima (Pihak Ketiga)</label>
                    <input v-model="form.payee" placeholder="nama pihak ketiga" :class="inputCls" />
                    <p v-if="form.errors.payee" class="mt-1 text-xs text-danger-500">{{ form.errors.payee }}</p>
                </div>

                <!-- #72 khusus Bilyet Giro: bank + no rekening penerima -->
                <template v-if="form.cheque_type === 'bg'">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank Penerima *</label><input v-model="form.recipient_bank" placeholder="mis. BCA / Mandiri" :class="inputCls" /><p v-if="form.errors.recipient_bank" class="mt-1 text-xs text-danger-500">{{ form.errors.recipient_bank }}</p></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening Penerima *</label><input v-model="form.recipient_account_no" placeholder="no. rekening tujuan" :class="[inputCls,'font-mono']" /><p v-if="form.errors.recipient_account_no" class="mt-1 text-xs text-danger-500">{{ form.errors.recipient_account_no }}</p></div>
                </template>

                <!-- #67 dua pejabat penandatangan (wajib) -->
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pejabat Penandatangan 1 *</label><input v-model="form.signer_name" required placeholder="nama pejabat ttd 1" :class="inputCls" /><p v-if="form.errors.signer_name" class="mt-1 text-xs text-danger-500">{{ form.errors.signer_name }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pejabat Penandatangan 2 *</label><input v-model="form.signer_name_2" required placeholder="nama pejabat ttd 2" :class="inputCls" /><p v-if="form.errors.signer_name_2" class="mt-1 text-xs text-danger-500">{{ form.errors.signer_name_2 }}</p></div>

                <!-- Keterangan hanya untuk warkat atas pelunasan hutang; disembunyikan di mode non-hutang. -->
                <div v-if="isHutang" class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.description" :class="inputCls" /></div>
                <div class="md:col-span-2 flex items-center justify-between rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-900/20"><span class="font-semibold">Nominal BG/Cek</span><span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(form.amount) }}</span></div>
            </div>

            <!-- Pelunasan hutang AP — centang invoice supplier (penerima) yang akan dilunasi (mode hutang saja) -->
            <div v-if="isHutang" class="card p-5 space-y-3">
                <div class="flex items-center gap-2"><Link2 class="h-5 w-5 text-primary-600" /><h2 class="font-semibold">Pelunasan Hutang</h2></div>
                <p class="text-xs text-neutral-500">Centang invoice/tagihan dari supplier penerima yang akan dilunasi warkat ini. Hutang dilunasi saat BG/Cek <b>diserahkan</b> ke supplier — tersinkron dengan Invoice Hutang, Kartu Hutang &amp; seluruh modul Account Payable. Nominal warkat otomatis = total yang dilunasi. Boleh dikosongkan (pelunasan dipilih saat penyerahan).</p>

                <div v-if="!form.supplier_id" class="rounded-lg border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-400 dark:border-neutral-700">Pilih <b>Penerima (Supplier/Vendor)</b> di atas terlebih dahulu.</div>

                <div v-else-if="supplierInvoices.length === 0" class="rounded-lg border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-400 dark:border-neutral-700">Tidak ada invoice hutang terbuka untuk supplier ini.</div>

                <div v-else class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-800/60">
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                <th class="px-3 py-2">No. Invoice</th>
                                <th class="px-3 py-2">Keterangan</th>
                                <th class="px-3 py-2 text-right">Sisa Hutang</th>
                                <th class="px-3 py-2 text-right">Dilunasi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="inv in supplierInvoices" :key="inv.id" :class="picks[inv.id]?.checked ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
                                <td class="px-3 py-2"><input type="checkbox" :checked="picks[inv.id]?.checked" @change="toggle(inv)" class="h-4 w-4 rounded border-neutral-300" /></td>
                                <td class="px-3 py-2 font-mono text-xs">{{ inv.invoice_number }}</td>
                                <td class="px-3 py-2 text-neutral-500">{{ inv.description ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ fmtRp(inv.outstanding) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <input v-if="picks[inv.id]?.checked" v-model.number="picks[inv.id].amount" type="number" step="1" min="1" :max="inv.outstanding"
                                           :class="[inputCls,'h-8 w-36 text-right font-mono', pickInvalid(inv) ? 'border-danger-400 ring-1 ring-danger-300' : '']" />
                                    <span v-else class="text-neutral-300">—</span>
                                    <p v-if="pickInvalid(inv)" class="mt-1 text-[11px] text-danger-500">Nominal harus &gt; 0 &amp; ≤ sisa hutang.</p>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="hasLines" class="border-t border-neutral-200 bg-neutral-50 font-semibold dark:border-neutral-700 dark:bg-neutral-800/60">
                            <tr>
                                <td colspan="4" class="px-3 py-2 text-right">Total Dilunasi</td>
                                <td class="px-3 py-2 text-right font-mono text-primary-700 dark:text-primary-300">{{ fmtRp(linesTotal) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p v-if="form.errors.lines" class="px-3 py-2 text-xs text-danger-500">{{ form.errors.lines }}</p>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || hasInvalidPick"><template #icon-left><Save class="h-4 w-4" /></template>Simpan</Button>
                <Link :href="route('bs.cheque-out.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
