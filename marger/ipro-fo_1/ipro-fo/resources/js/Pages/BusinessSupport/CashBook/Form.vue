<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Banknote, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    types: Record<string, string>;
    cashAccounts: Array<any>;
    counterAccounts: Array<{ id: number; code: string; name: string; type: string }>;
    defaultType: string;
    allowedTypes?: string[];
    editing?: any;
}>();

// Tanggal HARI INI menurut jam pengguna, bukan UTC.
// toISOString() mengubahnya ke UTC lebih dulu; di Jakarta (UTC+7) itu berarti setiap
// transaksi yang dibuat sebelum pukul 07.00 akan tertanggal KEMARIN.
const today = (() => {
    const d = new Date();
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
})();
const e = props.editing;

type Line = { account_id: number | string; amount: number; description: string };
const initialLines: Line[] = Array.isArray(e?.lines) && e.lines.length
    ? e.lines.map((l: any) => ({ account_id: l.account_id ?? '', amount: Number(l.amount) || 0, description: l.description ?? '' }))
    : [{ account_id: '', amount: 0, description: '' }];

// #148: jenis transaksi yang boleh dipilih (dari konteks pembuka / saat edit).
// Hanya jenis yang valid (ada di `types`) yang dipertahankan; fallback ke semua jenis.
const allTypeKeys = Object.keys(props.types);
const allowedTypes = computed<string[]>(() => {
    const allowed = (props.allowedTypes ?? []).filter((t) => allTypeKeys.includes(t));
    return allowed.length ? allowed : allTypeKeys;
});
const allowedTypeEntries = computed<[string, string][]>(() =>
    allowedTypes.value.map((val) => [val, props.types[val]]),
);
// Bila tepat satu jenis diizinkan → kunci (sembunyikan switcher, tampilkan jenis terkunci).
const typeLocked = computed(() => allowedTypes.value.length === 1);

// Pastikan jenis awal valid terhadap daftar yang diizinkan.
const initialType = e?.trx_type || props.defaultType || 'in';
const safeInitialType = allowedTypes.value.includes(initialType) ? initialType : allowedTypes.value[0];

const form = useForm<any>({
    trx_type: safeInitialType,
    trx_date: e?.trx_date ? String(e.trx_date).slice(0, 10) : today,
    cash_account_id: e?.cash_account_id ?? '',
    payee: e?.payee ?? '',
    description: e?.description ?? '',
    reference: e?.reference ?? '',
    lines: initialLines,
});

// Label & opsi menyesuaikan jenis transaksi.
const labels = computed(() => {
    switch (form.trx_type) {
        case 'out': return { cash: 'Dari Rekening Kas/Bank', counter: 'Akun Beban / Tujuan (Debit)', payee: 'Dibayarkan kepada' };
        case 'transfer': return { cash: 'Rekening Sumber', counter: 'Rekening Tujuan', payee: 'Catatan' };
        default: return { cash: 'Ke Rekening Kas/Bank', counter: 'Sumber Dana / Akun (Kredit)', payee: 'Diterima dari' };
    }
});
const counterOptions = computed(() => form.trx_type === 'transfer' ? props.cashAccounts : props.counterAccounts);

// #161: Transfer antar rekening = tepat 1 baris (rekening tujuan tunggal),
// tanpa kolom Keterangan Baris & tanpa field Catatan terpisah.
const isTransfer = computed(() => form.trx_type === 'transfer');

// Transfer antar rekening: Catatan otomatis mengikuti Keterangan.
watch([() => form.trx_type, () => form.description], ([type, desc]) => {
    if (type === 'transfer') form.payee = (desc as string) ?? '';
});

// #161: saat beralih ke transfer, paksa tepat 1 baris (buang baris berlebih, bersihkan keterangan baris).
watch(() => form.trx_type, (type) => {
    if (type === 'transfer') {
        if (form.lines.length > 1) form.lines.splice(1);
        if (!form.lines.length) form.lines.push({ account_id: '', amount: 0, description: '' });
        form.lines[0].description = '';
    }
}, { immediate: true });

const total = computed(() => form.lines.reduce((s: number, l: Line) => s + (Number(l.amount) || 0), 0));
const multi = computed(() => form.lines.length > 1);

function addLine() { form.lines.push({ account_id: '', amount: 0, description: '' }); }
function removeLine(i: number) { if (form.lines.length > 1) form.lines.splice(i, 1); }
function lineError(i: number, field: string): string | undefined { return form.errors[`lines.${i}.${field}`]; }

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() {
    if (props.editing) form.put(route('bs.cashbook.update', props.editing.id));
    else form.post(route('bs.cashbook.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> Transaksi Kas / Bank</h1>
            <Link :href="route('bs.cashbook.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card p-5">
                <label class="mb-2 block text-xs font-medium text-neutral-500">Jenis Transaksi</label>
                <!-- #148: bila hanya satu jenis diizinkan (mis. ?type=in / ?type=transfer / saat edit) → terkunci. -->
                <div v-if="typeLocked" class="flex items-center justify-between gap-2 rounded-lg border border-primary-500 bg-primary-50 px-3 py-2 text-sm font-medium text-primary-700 dark:bg-primary-900/20 dark:text-primary-300">
                    <span>{{ types[form.trx_type] }}</span>
                    <span class="text-xs font-normal text-neutral-500">Jenis terkunci</span>
                </div>
                <div v-else class="grid gap-2" :class="allowedTypeEntries.length >= 3 ? 'grid-cols-3' : allowedTypeEntries.length === 2 ? 'grid-cols-2' : 'grid-cols-1'">
                    <label v-for="[val, label] in allowedTypeEntries" :key="val" class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2 text-sm font-medium" :class="form.trx_type === val ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/20 dark:text-primary-300' : 'border-neutral-300 dark:border-neutral-700'">
                        <input v-model="form.trx_type" type="radio" :value="val" class="sr-only" /> {{ label }}
                    </label>
                </div>
            </div>

            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Referensi / No. Bukti</label><input v-model="form.reference" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">{{ labels.cash }} *</label><SearchSelect v-model="form.cash_account_id" :options="cashAccounts" placeholder="— pilih rekening —" /><p v-if="form.errors.cash_account_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cash_account_id }}</p></div>
                <div v-if="!isTransfer"><label class="mb-1 block text-xs font-medium text-neutral-500">{{ labels.payee }}</label><input v-model="form.payee" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.description" required placeholder="mis. Pembayaran operasional kantor" :class="inputCls" /><p v-if="form.errors.description" class="mt-1 text-xs text-danger-500">{{ form.errors.description }}</p></div>
            </div>

            <!-- #15: Rincian akun lawan — 1 transaksi bisa dipecah ke beberapa akun. -->
            <div class="card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold">{{ labels.counter }}</h2>
                        <p class="text-xs text-neutral-500">{{ isTransfer ? 'Pilih satu rekening tujuan dan nominal transfer.' : 'Satu transaksi kas boleh dipecah ke beberapa akun. Tambah baris bila perlu.' }}</p>
                    </div>
                    <button v-if="!isTransfer" type="button" @click="addLine" class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300"><Plus class="h-4 w-4" /> Tambah Baris</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700">
                                <th class="pb-2 pl-1" :style="{ width: isTransfer ? '60%' : '38%' }">{{ isTransfer ? 'Rekening Tujuan *' : 'Akun *' }}</th>
                                <th v-if="!isTransfer" class="pb-2" style="width:32%">Keterangan Baris</th>
                                <th class="pb-2 text-right" :style="{ width: isTransfer ? '40%' : '24%' }">Nominal *</th>
                                <th v-if="!isTransfer" class="pb-2" style="width:6%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, i) in form.lines" :key="i" class="border-b border-neutral-100 align-top dark:border-neutral-800">
                                <td class="py-2 pl-1 pr-2">
                                    <SearchSelect v-model="line.account_id" :options="counterOptions" placeholder="— pilih akun —" />
                                    <p v-if="lineError(i, 'account_id')" class="mt-1 text-xs text-danger-500">{{ lineError(i, 'account_id') }}</p>
                                </td>
                                <td v-if="!isTransfer" class="py-2 pr-2"><input v-model="line.description" placeholder="(opsional)" :class="inputCls" /></td>
                                <td class="py-2 pr-1"><input v-model.number="line.amount" type="number" step="1" min="1" :class="[inputCls, 'text-right font-mono']" /><p v-if="lineError(i, 'amount')" class="mt-1 text-right text-xs text-danger-500">{{ lineError(i, 'amount') }}</p></td>
                                <td v-if="!isTransfer" class="py-2 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length === 1" class="rounded-lg p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-danger-900/20"><Trash2 class="h-4 w-4" /></button></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td :colspan="isTransfer ? 1 : 2" class="pt-3 text-right font-semibold">Total {{ types[form.trx_type] }}</td>
                                <td class="pt-3 text-right"><span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(total) }}</span></td>
                                <td v-if="!isTransfer"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-if="typeof form.errors.lines === 'string'" class="mt-2 text-xs text-danger-500">{{ form.errors.lines }}</p>
                <p v-if="multi" class="mt-2 text-xs text-neutral-500">Jurnal otomatis: 1 baris kas (total) + {{ form.lines.length }} baris akun lawan — tetap balance.</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || total <= 0"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Buat Jurnal</Button>
                <Link :href="route('bs.cashbook.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
