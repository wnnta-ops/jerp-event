<script setup lang="ts">
import { reactive, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, ClipboardList, Plus, Trash2, Landmark, Search } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{ invoices: Array<any>; advances: Array<any>; cashAccounts: Array<any>; sip?: any; editing?: Array<any> }>();
const today = new Date().toISOString().slice(0, 10);

// #124: mode edit bila SIP dikirim dari controller.
const isEdit = computed(() => !!props.sip?.id);

const form = useForm<any>({
    sip_date: props.sip?.sip_date ?? today,
    title: props.sip?.title ?? '',
    cash_account_id: '',
    lines: [],
});

// #114: tiap "blok" = 1 bank sumber + daftar AP & Kasbon yang dibayar dari bank itu.
function makeBlock() {
    return {
        cash_account_id: '' as number | string,
        // #173: filter pencarian daftar tagihan hutang & bon sementara (nama / keterangan / nilai).
        fNama: '', fKet: '', fNilai: '',
        apRows: props.invoices.map((i: any) => ({
            sel: false, source: i, amount: i.outstanding,
            dest_bank_name: i.dest_bank_name ?? '', dest_account_no: i.dest_account_no ?? '', dest_account_name: i.dest_account_name ?? '',
            description: i.description ?? '',
        })),
        advRows: props.advances.map((a: any) => ({
            sel: false, source: a, amount: a.amount,
            dest_bank_name: '', dest_account_no: '', dest_account_name: '',
            description: a.purpose ?? '',
        })),
    };
}

// #124: bangun ulang blok dari baris SIP yang sedang diedit (grup per bank sumber).
function buildEditBlocks() {
    const lines = props.editing ?? [];
    const bankIds: any[] = [];
    lines.forEach((l: any) => { if (!bankIds.includes(l.cash_account_id)) bankIds.push(l.cash_account_id); });
    if (!bankIds.length) return [makeBlock()];
    return bankIds.map((bankId: any) => {
        const b = makeBlock();
        b.cash_account_id = bankId;
        lines.filter((l: any) => l.cash_account_id === bankId).forEach((l: any) => {
            const rows = l.source_type === 'ap' ? b.apRows : b.advRows;
            const row = rows.find((r: any) => Number(r.source.id) === Number(l.source_id));
            if (row) {
                row.sel = true;
                row.amount = l.amount;
                row.dest_bank_name = l.dest_bank_name ?? '';
                row.dest_account_no = l.dest_account_no ?? '';
                row.dest_account_name = l.dest_account_name ?? '';
                row.description = l.description ?? '';
            }
        });
        return b;
    });
}

const blocks = reactive<any[]>(props.editing?.length ? buildEditBlocks() : [makeBlock()]);
function addBlock() { blocks.push(makeBlock()); }
function removeBlock(i: number) { if (blocks.length > 1) blocks.splice(i, 1); }

// id sumber (AP/Bon) yang sudah dipilih di blok LAIN → dikunci agar tidak dobel bayar.
const apTaken = computed(() => blocks.map((_: any, idx: number) => {
    const s = new Set<number>();
    blocks.forEach((b: any, bi: number) => { if (bi !== idx) b.apRows.forEach((r: any) => { if (r.sel) s.add(r.source.id); }); });
    return s;
}));
const advTaken = computed(() => blocks.map((_: any, idx: number) => {
    const s = new Set<number>();
    blocks.forEach((b: any, bi: number) => { if (bi !== idx) b.advRows.forEach((r: any) => { if (r.sel) s.add(r.source.id); }); });
    return s;
}));

function blockTotal(b: any) {
    return b.apRows.filter((r: any) => r.sel).reduce((s: number, r: any) => s + (Number(r.amount) || 0), 0)
        + b.advRows.filter((r: any) => r.sel).reduce((s: number, r: any) => s + (Number(r.amount) || 0), 0);
}
// #173: filter daftar (display-only — TIDAK mengubah pilihan; baris terpilih yang tersembunyi tetap ikut tersubmit).
function onlyDigits(s: string) { return String(s ?? '').replace(/[^0-9]/g, ''); }
// Nilai: cocokkan DIGIT (tanpa pembulatan/desimal) → ketik sebagian nominal utk menemukan baris.
function apVisible(b: any) {
    const nama = b.fNama.trim().toLowerCase(), ket = b.fKet.trim().toLowerCase(), nil = onlyDigits(b.fNilai);
    return b.apRows.filter((r: any) =>
        (!nama || `${r.source.invoice_number ?? ''} ${r.source.supplier_name ?? ''}`.toLowerCase().includes(nama))
        && (!ket || String(r.description ?? '').toLowerCase().includes(ket))
        && (!nil || onlyDigits(String(r.source.outstanding ?? '')).includes(nil)));
}
function advVisible(b: any) {
    const nama = b.fNama.trim().toLowerCase(), ket = b.fKet.trim().toLowerCase(), nil = onlyDigits(b.fNilai);
    return b.advRows.filter((r: any) =>
        (!nama || `${r.source.advance_number ?? ''} ${r.source.holder ?? ''}`.toLowerCase().includes(nama))
        && (!ket || String(r.description ?? '').toLowerCase().includes(ket))
        && (!nil || onlyDigits(String(r.source.amount ?? '')).includes(nil)));
}
function blockSelectedCount(b: any) {
    return b.apRows.filter((r: any) => r.sel).length + b.advRows.filter((r: any) => r.sel).length;
}
function blockHasFilter(b: any) { return !!(b.fNama || b.fKet || b.fNilai); }
function clearBlockFilter(b: any) { b.fNama = ''; b.fKet = ''; b.fNilai = ''; }
const total = computed(() => blocks.reduce((s: number, b: any) => s + blockTotal(b), 0));
// Setiap blok yang punya pilihan WAJIB punya bank sumber.
const allBlocksHaveBank = computed(() => blocks.every((b: any) => !blockTotal(b) || !!b.cash_account_id));

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

function submit() {
    const lines: any[] = [];
    blocks.forEach((b: any) => {
        if (!b.cash_account_id) return;
        b.apRows.filter((r: any) => r.sel).forEach((r: any) => lines.push({
            source_type: 'ap', source_id: r.source.id, cash_account_id: b.cash_account_id, amount: r.amount,
            dest_bank_name: r.dest_bank_name, dest_account_no: r.dest_account_no, dest_account_name: r.dest_account_name, description: r.description,
        }));
        b.advRows.filter((r: any) => r.sel).forEach((r: any) => lines.push({
            source_type: 'advance', source_id: r.source.id, cash_account_id: b.cash_account_id, amount: r.amount,
            dest_bank_name: r.dest_bank_name, dest_account_no: r.dest_account_no, dest_account_name: r.dest_account_name, description: r.description,
        }));
    });
    form.lines = lines;
    form.cash_account_id = lines[0]?.cash_account_id ?? ''; // header = bank sumber baris pertama (kompatibilitas)
    if (isEdit.value) form.put(route('bs.sip.update', props.sip.id));
    else form.post(route('bs.sip.store'));
}
const inputCls = 'h-8 w-full rounded-lg border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-5xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> {{ isEdit ? 'Edit SIP' : 'Buat SIP' }}<span v-if="isEdit" class="font-mono text-base font-normal text-neutral-400">{{ sip.sip_number }}</span></h1>
            <Link :href="route('bs.sip.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal SIP *</label><input v-model="form.sip_date" type="date" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Judul</label><input v-model="form.title" placeholder="mis. SIP Pembayaran Minggu I" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            </div>

            <!-- #114: blok per bank sumber -->
            <div v-for="(b, bi) in blocks" :key="bi" class="card space-y-3 p-5">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div class="flex-1 min-w-64">
                        <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-neutral-500"><Landmark class="h-3.5 w-3.5" /> Bank Sumber Pembayaran #{{ bi + 1 }} *</label>
                        <SearchSelect v-model="b.cash_account_id" :options="cashAccounts" placeholder="— pilih bank sumber —" />
                    </div>
                    <button v-if="blocks.length > 1" type="button" @click="removeBlock(bi)" class="inline-flex items-center gap-1 rounded-lg border border-danger-300 px-2.5 py-1.5 text-xs text-danger-600 hover:bg-danger-50 dark:border-danger-700"><Trash2 class="h-3.5 w-3.5" /> Hapus sumber</button>
                </div>

                <!-- #173: filter pencarian daftar tagihan hutang & bon sementara (nama / keterangan / nilai) -->
                <div class="flex flex-wrap items-end gap-2 rounded-lg bg-neutral-50 p-2.5 dark:bg-neutral-800/50">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase text-neutral-400"><Search class="h-3.5 w-3.5" /> Filter</div>
                    <div class="min-w-40 flex-1"><label class="mb-0.5 block text-[10px] uppercase text-neutral-400">Nama</label><input v-model="b.fNama" placeholder="supplier / pemegang / no." :class="inputCls" /></div>
                    <div class="min-w-40 flex-1"><label class="mb-0.5 block text-[10px] uppercase text-neutral-400">Keterangan</label><input v-model="b.fKet" placeholder="keterangan…" :class="inputCls" /></div>
                    <div class="min-w-28"><label class="mb-0.5 block text-[10px] uppercase text-neutral-400">Nilai</label><input v-model="b.fNilai" inputmode="numeric" placeholder="cari nominal…" :class="inputCls" /></div>
                    <button v-if="blockHasFilter(b)" type="button" @click="clearBlockFilter(b)" class="rounded-lg border border-neutral-300 px-2.5 py-1.5 text-[11px] text-neutral-600 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-300">Reset</button>
                    <span v-if="blockSelectedCount(b)" :title="blockHasFilter(b) ? 'Total terpilih pada sumber ini, termasuk baris yang sedang disembunyikan filter — semuanya tetap ikut disimpan.' : 'Total terpilih pada sumber ini.'" class="text-[11px] text-primary-600 dark:text-primary-300">{{ blockSelectedCount(b) }} dipilih{{ blockHasFilter(b) ? ' (semua, termasuk tersembunyi)' : '' }}</span>
                </div>

                <!-- Tagihan Hutang (AP) -->
                <div class="overflow-x-auto">
                    <h3 class="mb-1 text-sm font-medium">Tagihan Hutang (AP) <span class="text-xs font-normal text-neutral-400">— pilih yang dibayar dari bank ini</span></h3>
                    <table class="w-full text-xs">
                        <thead class="text-left uppercase text-neutral-500"><tr><th class="w-8 py-1"></th><th class="py-1">No / Supplier</th><th class="py-1">Bank Tujuan</th><th class="py-1">No. Rekening</th><th class="py-1">Keterangan</th><th class="w-32 py-1 text-right">Sisa</th><th class="w-36 py-1 text-right">Nominal Bayar</th></tr></thead>
                        <tbody>
                            <tr v-for="r in apVisible(b)" :key="r.source.id" class="border-t border-neutral-100 dark:border-neutral-800" :class="apTaken[bi].has(r.source.id) ? 'opacity-40' : ''">
                                <td class="py-1"><input v-model="r.sel" type="checkbox" :disabled="apTaken[bi].has(r.source.id)" class="rounded" /></td>
                                <td class="py-1"><div class="font-mono font-semibold">{{ r.source.invoice_number }}</div><div class="text-neutral-500">{{ r.source.supplier_name }}<span v-if="apTaken[bi].has(r.source.id)" class="ml-1 text-[10px] text-amber-600">(di sumber lain)</span></div></td>
                                <td class="py-1 pr-1"><input v-model="r.dest_bank_name" :disabled="!r.sel" :class="inputCls" /></td>
                                <td class="py-1 pr-1"><input v-model="r.dest_account_no" :disabled="!r.sel" :class="[inputCls,'font-mono']" /></td>
                                <td class="py-1 pr-1"><input v-model="r.description" :disabled="!r.sel" :class="inputCls" /></td>
                                <td class="py-1 text-right font-mono text-neutral-500">{{ fmtRp(r.source.outstanding) }}</td>
                                <td class="py-1 pl-1"><MoneyInput v-if="r.sel" v-model="r.amount" /><span v-else class="block text-right text-neutral-300">—</span></td>
                            </tr>
                            <tr v-if="!apVisible(b).length"><td colspan="7" class="py-3 text-center text-neutral-400">{{ b.apRows.length ? 'Tidak ada tagihan AP cocok filter.' : 'Tidak ada tagihan AP diajukan.' }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Bon Sementara -->
                <div class="overflow-x-auto">
                    <h3 class="mb-1 text-sm font-medium">Bon Sementara (Kasbon) <span class="text-xs font-normal text-neutral-400">— isi bank tujuan manual</span></h3>
                    <table class="w-full text-xs">
                        <thead class="text-left uppercase text-neutral-500"><tr><th class="w-8 py-1"></th><th class="py-1">No / Pemegang</th><th class="py-1">Bank Tujuan</th><th class="py-1">No. Rekening</th><th class="py-1">Keterangan</th><th class="w-32 py-1 text-right">Nilai</th><th class="w-36 py-1 text-right">Nominal Bayar</th></tr></thead>
                        <tbody>
                            <tr v-for="r in advVisible(b)" :key="r.source.id" class="border-t border-neutral-100 dark:border-neutral-800" :class="advTaken[bi].has(r.source.id) ? 'opacity-40' : ''">
                                <td class="py-1"><input v-model="r.sel" type="checkbox" :disabled="advTaken[bi].has(r.source.id)" class="rounded" /></td>
                                <td class="py-1"><div class="font-mono font-semibold">{{ r.source.advance_number }}</div><div class="text-neutral-500">{{ r.source.holder }}<span v-if="advTaken[bi].has(r.source.id)" class="ml-1 text-[10px] text-amber-600">(di sumber lain)</span></div></td>
                                <td class="py-1 pr-1"><input v-model="r.dest_bank_name" :disabled="!r.sel" placeholder="bank tujuan" :class="inputCls" /></td>
                                <td class="py-1 pr-1"><input v-model="r.dest_account_no" :disabled="!r.sel" placeholder="no rekening" :class="[inputCls,'font-mono']" /></td>
                                <td class="py-1 pr-1"><input v-model="r.description" :disabled="!r.sel" :class="inputCls" /></td>
                                <td class="py-1 text-right font-mono text-neutral-500">{{ fmtRp(r.source.amount) }}</td>
                                <td class="py-1 pl-1"><MoneyInput v-if="r.sel" v-model="r.amount" /><span v-else class="block text-right text-neutral-300">—</span></td>
                            </tr>
                            <tr v-if="!advVisible(b).length"><td colspan="7" class="py-3 text-center text-neutral-400">{{ b.advRows.length ? 'Tidak ada bon sementara cocok filter.' : 'Tidak ada bon sementara diajukan.' }}</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-neutral-100 pt-2 text-sm dark:border-neutral-800">
                    <span class="text-neutral-500">Total sumber #{{ bi + 1 }}:</span> <b class="font-mono">{{ fmtRp(blockTotal(b)) }}</b>
                </div>
            </div>

            <button type="button" @click="addBlock" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-primary-300 px-4 py-2 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:border-primary-700"><Plus class="h-4 w-4" /> Tambah Bank Sumber</button>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="rounded-lg bg-primary-50 px-4 py-2 text-sm dark:bg-primary-900/20">Total seluruh sumber ({{ blocks.length }}): <span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(total) }}</span></div>
                <div class="flex gap-2">
                    <Link :href="route('bs.sip.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
                    <Button type="submit" :disabled="form.processing || total <= 0 || !allBlocksHaveBank"><template #icon-left><Save class="h-4 w-4" /></template>{{ isEdit ? 'Simpan Perubahan' : 'Simpan SIP' }}</Button>
                </div>
            </div>
            <p v-if="!allBlocksHaveBank" class="text-right text-xs text-danger-500">Setiap sumber yang punya tagihan/bon terpilih wajib memilih bank sumber.</p>
            <p v-if="form.errors.lines" class="text-xs text-danger-500">{{ form.errors.lines }}</p>
            <p v-if="form.errors.cash_account_id" class="text-xs text-danger-500">{{ form.errors.cash_account_id }}</p>
        </form>
    </div>
</template>
