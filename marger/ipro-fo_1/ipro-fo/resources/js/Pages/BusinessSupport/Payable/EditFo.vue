<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

/*
 * Edit invoice hasil Posting Invoice dari FO (Refund / Komisi / BLT / Gimmick). Semua isian bisa
 * diubah; terkunci (oleh server) begitu jurnalnya di-approve Accounting atau sudah diproses FAT.
 */
type Acc = { id: number; code: string; name: string; cost_center_optional?: boolean; cost_center_id?: number | null };
const props = defineProps<{
    invoice: { id: number; invoice_number: string; supplier_id: number | null; supplier_invoice_no: string | null; invoice_date: string; due_date: string; description: string; pph_amount: number; source_type: string };
    lines: Array<{ description: string; amount: number }>;
    journalLines: Array<{ account_id: number; cost_center_id: number | null; description: string | null; debit: number; credit: number }>;
    journalNumber: string | null;
    suppliers: Array<{ id: number; label: string }>;
    accounts: Acc[];
    costCenters: Array<{ id: number; code: string; name: string }>;
    coaTanpaAnggaran: string[];
    ccPengguna: number | null;
    bisaUbahCc: boolean;
}>();

const form = useForm({
    supplier_id: props.invoice.supplier_id,
    supplier_invoice_no: props.invoice.supplier_invoice_no ?? '',
    invoice_date: props.invoice.invoice_date,
    due_date: props.invoice.due_date ?? props.invoice.invoice_date,
    description: props.invoice.description ?? '',
    lines: props.lines.map((l) => ({ ...l })),
    journal_lines: props.journalLines.map((l) => ({ ...l, debit: Number(l.debit) || 0, credit: Number(l.credit) || 0 })),
});

const ccOpsi = computed(() => props.costCenters.map((c) => ({ id: c.id, label: `${c.code} - ${c.name}` })));
const akunOpsi = computed(() => props.accounts.map((a) => ({ id: a.id, label: `${a.code} — ${a.name}` })));
function rp(v: number) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }

function ccOptional(id: any) {
    const a = props.accounts.find((x) => x.id === Number(id));
    return !!a && (!!a.cost_center_optional || props.coaTanpaAnggaran.includes(String(a.code)));
}
/** Cost center ikut akun yang dipilih — sama dengan Struktur Jurnal Invoice Hutang. */
function pilihAkun(l: any, v: any) {
    l.account_id = v;
    const a = props.accounts.find((x) => x.id === Number(v));
    if (!v || !a) return;
    if (ccOptional(v)) { l.cost_center_id = null; return; }
    if (a.cost_center_id) { l.cost_center_id = Number(a.cost_center_id); return; }
    if (!l.cost_center_id) l.cost_center_id = props.ccPengguna ?? null;
}
const ccKurang = (l: any) => !!l.account_id && !l.cost_center_id && !ccOptional(l.account_id);

const totalTagihan = computed(() => form.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0));
const totalDebit = computed(() => form.journal_lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const totalKredit = computed(() => form.journal_lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const balance = computed(() => Math.round(totalDebit.value * 100) === Math.round(totalKredit.value * 100) && totalDebit.value > 0);
const bolehSimpan = computed(() => balance.value && form.lines.length > 0 && !form.journal_lines.some(ccKurang) && !!form.description.trim());

function tambahBaris() { form.lines.push({ description: form.description, amount: 0 }); }
function tambahJurnal() { form.journal_lines.push({ account_id: null as any, cost_center_id: props.ccPengguna ?? null, description: form.description, debit: 0, credit: 0 }); }
function simpan() {
    if (!bolehSimpan.value) return;
    form.put(route('bs.ap.update-fo', props.invoice.id));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-6xl space-y-4">
        <Link :href="route('bs.ap.show', invoice.id)" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali ke invoice</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Pencil class="h-6 w-6 text-primary-600" /> Edit <span class="font-mono">{{ invoice.invoice_number }}</span></h1>
            <p class="text-sm text-neutral-500">Invoice hasil Posting Invoice dari FO ({{ invoice.source_type }}). Semua isian bisa diubah; jurnal <span class="font-mono">{{ journalNumber ?? '—' }}</span> ikut diperbarui. Terkunci begitu jurnalnya di-approve Accounting.</p>
        </div>

        <!-- 1. Data invoice -->
        <div class="card grid gap-4 p-5 md:grid-cols-2">
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Supplier *</label><SearchSelect v-model="form.supplier_id" :options="suppliers" :clearable="false" placeholder="— pilih supplier —" /><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Invoice Supplier</label><input v-model="form.supplier_invoice_no" :class="inputCls" /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Invoice *</label><input v-model="form.invoice_date" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jatuh Tempo *</label><input v-model="form.due_date" type="date" :class="inputCls" /><p v-if="form.errors.due_date" class="mt-1 text-xs text-danger-500">{{ form.errors.due_date }}</p></div>
            </div>
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" maxlength="255" :class="inputCls" /></div>
        </div>

        <!-- 2. Detil Tagihan -->
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Detil Tagihan</h2>
                <button type="button" @click="tambahBaris" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-3 py-2">Uraian</th><th class="w-56 px-3 py-2 text-right">Jumlah</th><th class="w-10"></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(l, i) in form.lines" :key="i">
                        <td class="px-3 py-2"><input v-model="l.description" :class="inputCls" /></td>
                        <td class="px-3 py-2"><MoneyInput v-model="l.amount" /></td>
                        <td class="px-2"><button type="button" :disabled="form.lines.length <= 1" @click="form.lines.splice(i, 1)" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                    </tr>
                </tbody>
                <tfoot class="bg-neutral-50 font-semibold dark:bg-neutral-800"><tr><td class="px-3 py-2 text-right">Total tagihan</td><td class="px-3 py-2 text-right font-mono">{{ rp(totalTagihan) }}</td><td></td></tr></tfoot>
            </table>
            <p v-if="invoice.pph_amount > 0" class="px-4 py-2 text-xs text-neutral-500">PPh dipotong {{ rp(invoice.pph_amount) }} — nett dibayar {{ rp(totalTagihan - invoice.pph_amount) }}.</p>
        </div>

        <!-- 3. Struktur Jurnal -->
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Struktur Jurnal</h2>
                <div class="flex items-center gap-3">
                    <span class="rounded px-2 py-0.5 text-xs font-semibold" :class="balance ? 'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-300' : 'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-300'">{{ balance ? 'Balance' : 'Tidak balance' }}</span>
                    <button type="button" @click="tambahJurnal" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-sm">
                    <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="w-72 px-3 py-2">Akun</th><th class="w-56 px-3 py-2">Cost Center</th><th class="px-3 py-2">Keterangan</th><th class="w-40 px-3 py-2 text-right">Debit</th><th class="w-40 px-3 py-2 text-right">Kredit</th><th class="w-10"></th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 align-top dark:divide-neutral-800">
                        <tr v-for="(l, i) in form.journal_lines" :key="i">
                            <td class="px-3 py-2"><SearchSelect :model-value="l.account_id" :options="akunOpsi" placeholder="— pilih akun —" @update:model-value="(v) => pilihAkun(l, v)" /></td>
                            <td class="px-3 py-2">
                                <div :class="ccKurang(l) ? 'rounded-lg ring-1 ring-danger-400' : ''"><SearchSelect v-model="l.cost_center_id" :options="ccOpsi" :disabled="!bisaUbahCc" :placeholder="ccOptional(l.account_id) ? '— tanpa cost center —' : '— cost center —'" /></div>
                                <p v-if="ccKurang(l)" class="mt-0.5 text-[10.5px] font-medium text-danger-500">Wajib diisi</p>
                                <p v-else-if="l.account_id && ccOptional(l.account_id)" class="mt-0.5 text-[10.5px] text-neutral-400">Boleh dikosongkan</p>
                            </td>
                            <td class="px-3 py-2"><textarea v-model="l.description" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea></td>
                            <td class="px-3 py-2"><MoneyInput v-model="l.debit" /></td>
                            <td class="px-3 py-2"><MoneyInput v-model="l.credit" /></td>
                            <td class="px-2 pt-3"><button type="button" :disabled="form.journal_lines.length <= 2" @click="form.journal_lines.splice(i, 1)" class="text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-neutral-50 font-semibold dark:bg-neutral-800"><tr><td colspan="3" class="px-3 py-2 text-right">Total</td><td class="px-3 py-2 text-right font-mono">{{ rp(totalDebit) }}</td><td class="px-3 py-2 text-right font-mono">{{ rp(totalKredit) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            <p v-if="balance && Math.round(totalDebit) !== Math.round(totalTagihan)" class="px-4 py-2 text-xs text-amber-600">Catatan: total jurnal ({{ rp(totalDebit) }}) berbeda dari total Detil Tagihan ({{ rp(totalTagihan) }}).</p>
        </div>

        <div class="flex items-center gap-2">
            <Button :disabled="form.processing || !bolehSimpan" @click="simpan"><template #icon-left><Save class="h-4 w-4" /></template>Simpan Perubahan</Button>
            <span v-if="!balance" class="text-xs text-danger-500">Jurnal belum balance.</span>
            <span v-else-if="form.journal_lines.some(ccKurang)" class="text-xs text-danger-500">Masih ada baris jurnal tanpa cost center.</span>
            <Link :href="route('bs.ap.show', invoice.id)" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
        </div>
    </div>
</template>
