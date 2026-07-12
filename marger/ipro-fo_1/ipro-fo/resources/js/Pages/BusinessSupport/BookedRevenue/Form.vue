<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleDollarSign, Save, Search, Home, X, Trash2, Wand2, Scale } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    customers: Array<{ id: number; code: string; name: string }>;
    units: Array<{ id: number; code: string; cluster?: string; price: number; gross?: number; diskon?: number; status: string; customer_id?: number; customer_name?: string }>;
    bookedCount: number;
    accounts: Array<{ id: number; code: string; name: string; normal_balance: string }>;
    pencairan: Record<number, number>;   // { unit_id: total pencairan Proses KPR }
    preselect?: number | null;           // unit_id dari list Belum Booked Revenue (auto-follow)
    prefill: { piutang_id: number; diskon_id: number; um_id: number; ppn_keluaran_id: number; penjualan_id: number; ppn_pending_id: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtNum(v: number) { return (Number(v) || 0).toLocaleString('id-ID'); } // 3000000 → "3.000.000"

// Input diskon: tampilkan dengan pemisah ribuan, simpan sebagai angka, clamp ke Gross SPR (dasar semua hitungan).
function onDiscountInput(r: { price: number; gross?: number; discount: number }, e: Event) {
    const el = e.target as HTMLInputElement;
    const digits = el.value.replace(/\D/g, '');
    let n = digits ? Number(digits) : 0;
    const cap = Number(r.gross ?? r.price) || 0;
    if (n > cap) n = cap;
    r.discount = n;
    el.value = fmtNum(n); // paksa DOM ke format bertitik (hindari nilai basi)
}

// Baris unit (hanya yang BELUM pernah di-Booked Revenue) + state checklist & diskon (default dari SPR).
const rows = reactive(props.units.map((u) => ({ ...u, checked: false, discount: Number(u.diskon) || 0 })));
const selected = computed(() => rows.filter((r) => r.checked));
// Nilai Revenue (DPP) = (Pricelist Gross SPR − Diskon/Free DP) ÷ 1,11.
function revenueOf(r: { price: number; gross?: number; discount: number }) {
    const g = Number(r.gross ?? r.price) || 0;
    const d = Number(r.discount) || 0;
    return Math.max(0, Math.round(((g - d) / 1.11) * 100) / 100);
}
const total = computed(() => selected.value.reduce((s, r) => s + revenueOf(r), 0));

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({ booking_date: today, customer_id: '', description: '', units: [] });

// ── Jurnal manual Booked Revenue (hingga 4 baris debit + 3 baris kredit, COA dari General Ledger) ──
type JLine = { account_id: number | ''; amount: number };
const debits = reactive<JLine[]>([{ account_id: '', amount: 0 }, { account_id: '', amount: 0 }, { account_id: '', amount: 0 }, { account_id: '', amount: 0 }]);
const credits = reactive<JLine[]>([{ account_id: '', amount: 0 }, { account_id: '', amount: 0 }, { account_id: '', amount: 0 }]);
let journalTouched = false;
function markTouched() { journalTouched = true; }

// Acuan hitungan (SPR gross/diskon + pencairan Proses KPR customer terpilih).
const grossSum = computed(() => selected.value.reduce((s, r) => s + (Number(r.gross ?? r.price) || 0), 0));
const diskonSum = computed(() => selected.value.reduce((s, r) => s + (Number(r.discount) || 0), 0));
const nettSum = computed(() => grossSum.value - diskonSum.value);
const dppSum = computed(() => Math.round((nettSum.value / 1.11) * 100) / 100);
const ppnSprSum = computed(() => Math.round((dppSum.value * 0.11) * 100) / 100);
// Pencairan Proses KPR dijumlahkan per UNIT terpilih (bukan total customer) → tidak menumpuk unit lain.
const pencairanSum = computed(() => selected.value.reduce((s, r) => s + Number(props.pencairan?.[r.id] ?? 0), 0));
// Peringatan: pencairan melebihi Nett → Piutang negatif (jurnal tak bisa diposting).
const pencairanOver = computed(() => pencairanSum.value > nettSum.value + 0.005);
const r2 = (x: number) => Math.round(x * 100) / 100;

// Jurnal Booked Revenue sesuai "Alur Jurnal Booked Revenue" (balance dijamin secara aljabar).
function applyPrefill() {
    const gross = grossSum.value, diskon = diskonSum.value, penc = pencairanSum.value;
    const nett = gross - diskon;
    const um = penc / 1.11;                                  // UM Penjualan
    const piutang = r2(nett - penc);                         // Piutang Usaha
    const diskonPenjualan = r2(diskon / 1.11);              // Diskon Penjualan
    const umPenjualan = r2(um);
    const ppnKeluaranDebit = r2(um * 0.11);                 // PPN Keluaran (debit)
    const penjualan = r2(gross / 1.11);                     // Penjualan
    const ppnKeluaranCredit = r2((um / 1.11) * 0.11);      // PPN Keluaran (kredit)
    const debitTotal = piutang + diskonPenjualan + umPenjualan + ppnKeluaranDebit;
    const ppnPending = r2(debitTotal - penjualan - ppnKeluaranCredit); // PPN Keluaran Akan Diterbitkan (plug → balance persis)
    debits[0] = { account_id: props.prefill.piutang_id || '', amount: piutang };
    debits[1] = { account_id: props.prefill.diskon_id || '', amount: diskonPenjualan };
    debits[2] = { account_id: props.prefill.um_id || '', amount: umPenjualan };
    debits[3] = { account_id: props.prefill.ppn_keluaran_id || '', amount: ppnKeluaranDebit };
    credits[0] = { account_id: props.prefill.penjualan_id || '', amount: penjualan };
    credits[1] = { account_id: props.prefill.ppn_keluaran_id || '', amount: ppnKeluaranCredit };
    credits[2] = { account_id: props.prefill.ppn_pending_id || '', amount: ppnPending };
}
function fillFromCalc() { journalTouched = false; applyPrefill(); }
// Auto-sync jurnal dari hitungan selama user belum menyentuh (pencairan kini ikut unit terpilih).
watch(selected, () => { if (!journalTouched) applyPrefill(); }, { deep: true });

// Customer otomatis terisi dari unit yang dipilih (bila belum dipilih).
watch(selected, (rows) => {
    if (!form.customer_id) {
        const withCust = rows.find((r: any) => r.customer_id);
        if (withCust) form.customer_id = withCust.customer_id;
    }
}, { deep: true });

// Auto-follow: unit dari list Belum Booked Revenue (query unit_id) langsung terpilih + customer terisi.
if (props.preselect) {
    const pr = rows.find((x) => x.id === props.preselect);
    if (pr) { pr.checked = true; if (pr.customer_id) form.customer_id = pr.customer_id; }
}

// Total pakai clamp ≥0: baris negatif (mis. Piutang saat pencairan>nett) DIBUANG saat submit,
// jadi totals di layar harus mencerminkan yang benar-benar dikirim agar "Balance" tidak menyesatkan.
const totalDebit = computed(() => debits.reduce((s, r) => s + Math.max(0, Number(r.amount) || 0), 0));
const totalCredit = computed(() => credits.reduce((s, r) => s + Math.max(0, Number(r.amount) || 0), 0));
const journalBalanced = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 0.01 && totalDebit.value > 0);
const filledLines = computed(() => [...debits, ...credits].filter((r) => r.account_id && (Number(r.amount) || 0) > 0).length);

// Input nominal jurnal: pemisah ribuan + simpan angka + tandai tersentuh.
function onAmtInput(row: JLine, e: Event) {
    const el = e.target as HTMLInputElement;
    const digits = el.value.replace(/\D/g, '');
    row.amount = digits ? Number(digits) : 0;
    el.value = fmtNum(row.amount);
    markTouched();
}

const statusColor: Record<string, string> = {
    available: 'text-emerald-600', hold: 'text-amber-600', booked: 'text-blue-600',
    sold: 'text-neutral-400', cancelled: 'text-neutral-400', maintenance: 'text-rose-600',
};

// ── Modal pemilih unit ──
const showPicker = ref(false);
const search = ref('');
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) => r.code.toLowerCase().includes(q) || (r.cluster ?? '').toLowerCase().includes(q) || r.status.toLowerCase().includes(q));
});
const allFilteredChecked = computed(() => filtered.value.length > 0 && filtered.value.every((r) => r.checked));
function toggleAll() { const v = !allFilteredChecked.value; filtered.value.forEach((r) => (r.checked = v)); }
function openPicker() { search.value = ''; showPicker.value = true; }

function submit() {
    form.transform((d: any) => ({
        ...d,
        units: selected.value.map((r) => ({ unit_id: r.id, discount: r.discount || 0 })),
        debits: debits.filter((r) => r.account_id && (Number(r.amount) || 0) > 0).map((r) => ({ account_id: r.account_id, amount: r.amount })),
        credits: credits.filter((r) => r.account_id && (Number(r.amount) || 0) > 0).map((r) => ({ account_id: r.account_id, amount: r.amount })),
    })).post(route('bs.booked-revenue.store'));
}

const inputCls = 'h-10 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="mx-auto max-w-4xl space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><CircleDollarSign class="h-6 w-6 text-primary-600" /> Booking Pendapatan Baru <span class="text-base font-normal text-neutral-400">(massal)</span></h1>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Header -->
            <div class="card space-y-4 p-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Booking *</label>
                        <input v-model="form.booking_date" type="date" required :class="inputCls" />
                        <p v-if="form.errors.booking_date" class="mt-1 text-xs text-danger-500">{{ form.errors.booking_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label>
                        <input v-model="form.description" placeholder="opsional" :class="inputCls" />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Customer * <span class="text-neutral-400">(otomatis dari unit yang dipilih · bisa diubah)</span></label>
                    <select v-model="form.customer_id" required :class="inputCls">
                        <option value="" disabled>— pilih customer —</option>
                        <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <p v-if="form.errors.customer_id" class="mt-1 text-xs text-danger-500">{{ form.errors.customer_id }}</p>
                </div>
            </div>

            <!-- Unit Rumah -->
            <div class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <div>
                        <div class="font-semibold">Unit Rumah</div>
                        <div class="text-xs text-neutral-400">{{ props.units.length }} unit tersedia (belum di-Booked)<span v-if="bookedCount"> · {{ bookedCount }} unit sudah di-Booked, disembunyikan</span></div>
                    </div>
                    <button type="button" @click="openPicker" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-medium text-white hover:bg-primary-700"><Home class="h-4 w-4" /> Pilih Unit Rumah</button>
                </div>
                <p v-if="form.errors.units" class="px-4 pt-2 text-xs text-danger-500">{{ form.errors.units }}</p>

                <table v-if="selected.length" class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-2.5">Unit</th><th class="px-4 py-2.5">Cluster</th><th class="px-4 py-2.5">Status</th><th class="px-4 py-2.5 text-right">Harga (Gross SPR)</th><th class="px-4 py-2.5 text-right">Diskon/Free DP</th><th class="px-4 py-2.5 text-right">Nilai Revenue</th><th class="px-4 py-2.5 w-10"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="r in selected" :key="r.id">
                            <td class="px-4 py-2 font-mono font-medium">{{ r.code }}</td>
                            <td class="px-4 py-2 text-neutral-500">{{ r.cluster ?? '—' }}</td>
                            <td class="px-4 py-2"><span :class="statusColor[r.status] ?? 'text-neutral-500'">{{ r.status }}</span></td>
                            <td class="px-4 py-2 text-right tabular-nums text-neutral-400">{{ fmtRp(r.gross ?? r.price) }}</td>
                            <td class="px-4 py-2 text-right"><input :value="fmtNum(r.discount)" @input="onDiscountInput(r, $event)" type="text" inputmode="numeric" class="h-8 w-36 rounded border border-neutral-300 bg-white px-2 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900" /></td>
                            <td class="px-4 py-2 text-right tabular-nums font-semibold text-primary-600">{{ fmtRp(revenueOf(r)) }}</td>
                            <td class="px-4 py-2 text-center"><button type="button" @click="r.checked = false" class="text-neutral-400 hover:text-danger-500"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-neutral-200 dark:border-neutral-700"><tr class="font-semibold">
                        <td class="px-4 py-2.5" colspan="5">Total Nilai Revenue ({{ selected.length }} unit)</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-primary-600">{{ fmtRp(total) }}</td><td></td>
                    </tr></tfoot>
                </table>
                <div v-else class="px-4 py-8 text-center text-sm text-neutral-400">
                    Belum ada unit dipilih. Klik <b>Pilih Unit Rumah</b> untuk menampilkan unit yang belum pernah di-Booked Revenue.
                </div>
            </div>

            <!-- Jurnal Manual (3 debit + 3 kredit, COA dari General Ledger) -->
            <div class="card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <div>
                        <div class="font-semibold">Jurnal Penambahan (Manual)</div>
                        <div class="text-xs text-neutral-400">COA terhubung ke General Ledger · hingga 4 baris debit + 3 baris kredit · harus balance.</div>
                    </div>
                    <button type="button" @click="fillFromCalc" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-3 text-sm font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-700/50 dark:bg-primary-900/20 dark:text-primary-300"><Wand2 class="h-4 w-4" /> Isi dari hitungan</button>
                </div>

                <div class="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-2 text-xs text-neutral-500">
                    <span>Gross SPR <b class="tabular-nums">{{ fmtRp(grossSum) }}</b></span>
                    <span>Diskon <b class="tabular-nums">{{ fmtRp(diskonSum) }}</b></span>
                    <span>Nett <b class="tabular-nums">{{ fmtRp(nettSum) }}</b></span>
                    <span>DPP <b class="tabular-nums">{{ fmtRp(dppSum) }}</b></span>
                    <span>PPN SPR <b class="tabular-nums">{{ fmtRp(ppnSprSum) }}</b></span>
                    <span>Pencairan KPR <b class="tabular-nums">{{ fmtRp(pencairanSum) }}</b></span>
                </div>
                <p v-if="pencairanOver" class="px-4 pt-1 text-xs text-amber-600 dark:text-amber-400">
                    ⚠ Pencairan KPR ({{ fmtRp(pencairanSum) }}) melebihi Nett ({{ fmtRp(nettSum) }}) → Piutang Usaha negatif; jurnal tidak akan balance / tidak bisa diposting. Periksa data pencairan Proses KPR unit ini.
                </p>

                <div class="grid gap-5 p-4 md:grid-cols-2">
                    <!-- DEBIT -->
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase text-emerald-600">Debit</div>
                        <div v-for="(row, i) in debits" :key="'d' + i" class="mb-2 flex items-center gap-2">
                            <SearchSelect :model-value="row.account_id" :options="accounts" placeholder="— pilih / cari akun —" class="flex-1" @update:model-value="(v) => { row.account_id = v; markTouched(); }" />
                            <input :value="fmtNum(row.amount)" @input="onAmtInput(row, $event)" type="text" inputmode="numeric" placeholder="0" class="h-9 w-32 shrink-0 rounded-lg border border-neutral-300 bg-white px-2 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                        <div class="mt-1 flex justify-between border-t border-neutral-200 pt-1.5 text-sm dark:border-neutral-700"><span class="text-neutral-500">Total Debit</span><span class="font-semibold tabular-nums">{{ fmtRp(totalDebit) }}</span></div>
                    </div>
                    <!-- KREDIT -->
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase text-rose-600">Kredit</div>
                        <div v-for="(row, i) in credits" :key="'c' + i" class="mb-2 flex items-center gap-2">
                            <SearchSelect :model-value="row.account_id" :options="accounts" placeholder="— pilih / cari akun —" class="flex-1" @update:model-value="(v) => { row.account_id = v; markTouched(); }" />
                            <input :value="fmtNum(row.amount)" @input="onAmtInput(row, $event)" type="text" inputmode="numeric" placeholder="0" class="h-9 w-32 shrink-0 rounded-lg border border-neutral-300 bg-white px-2 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                        <div class="mt-1 flex justify-between border-t border-neutral-200 pt-1.5 text-sm dark:border-neutral-700"><span class="text-neutral-500">Total Kredit</span><span class="font-semibold tabular-nums">{{ fmtRp(totalCredit) }}</span></div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                    <span v-if="journalBalanced" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300"><Scale class="h-4 w-4" /> Balance</span>
                    <span v-else class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 dark:bg-rose-900/20 dark:text-rose-300"><Scale class="h-4 w-4" /> Belum balance · selisih {{ fmtRp(Math.abs(totalDebit - totalCredit)) }}</span>
                    <span class="text-xs text-neutral-400">{{ filledLines }} baris terisi (min. 2)</span>
                </div>
            </div>

            <!-- Submit -->
            <div class="card flex flex-wrap items-center justify-between gap-4 p-5">
                <div>
                    <div class="text-sm text-neutral-500">{{ selected.length }} unit dipilih · total revenue</div>
                    <div class="text-2xl font-bold tabular-nums text-primary-600">{{ fmtRp(total) }}</div>
                </div>
                <Button type="submit" :disabled="form.processing || !selected.length || !journalBalanced || filledLines < 2"><template #icon-left><Save class="h-4 w-4" /></template>Booking {{ selected.length || '' }} Unit &amp; Posting Jurnal</Button>
            </div>
        </form>

        <!-- Modal: Pilih Unit Rumah -->
        <Teleport to="body">
            <div v-if="showPicker" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showPicker = false">
                <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <h2 class="flex items-center gap-2 text-lg font-bold"><Home class="h-5 w-5 text-primary-600" /> Pilih Unit Rumah</h2>
                        <button type="button" @click="showPicker = false" class="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"><X class="h-5 w-5" /></button>
                    </div>
                    <div class="border-b border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <div class="relative">
                            <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-neutral-400" />
                            <input v-model="search" placeholder="Cari unit / cluster / status…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto">
                        <table v-if="rows.length" class="w-full text-sm">
                            <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                                <th class="px-5 py-2.5 w-10"><input type="checkbox" :checked="allFilteredChecked" @change="toggleAll" class="h-4 w-4 rounded border-neutral-300" /></th>
                                <th class="px-3 py-2.5">Unit</th><th class="px-3 py-2.5">Cluster</th><th class="px-3 py-2.5">Customer</th><th class="px-3 py-2.5">Status</th><th class="px-3 py-2.5 text-right">Harga (Gross SPR)</th>
                            </tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                <tr v-for="r in filtered" :key="r.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/40" :class="r.checked ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''" @click="r.checked = !r.checked">
                                    <td class="px-5 py-2"><input v-model="r.checked" type="checkbox" class="h-4 w-4 rounded border-neutral-300" @click.stop /></td>
                                    <td class="px-3 py-2 font-mono font-medium">{{ r.code }}</td>
                                    <td class="px-3 py-2 text-neutral-500">{{ r.cluster ?? '—' }}</td>
                                    <td class="px-3 py-2 text-neutral-500">{{ r.customer_name ?? '—' }}</td>
                                    <td class="px-3 py-2"><span :class="statusColor[r.status] ?? 'text-neutral-500'">{{ r.status }}</span></td>
                                    <td class="px-3 py-2 text-right tabular-nums text-neutral-400">{{ fmtRp(r.gross ?? r.price) }}</td>
                                </tr>
                                <tr v-if="!filtered.length"><td colspan="6" class="px-4 py-8 text-center text-neutral-400">Tidak ada unit cocok pencarian.</td></tr>
                            </tbody>
                        </table>
                        <EmptyState v-else title="Tidak ada unit tersedia" description="Semua unit sudah pernah di-Booked Revenue." />
                    </div>
                    <div class="flex items-center justify-between border-t border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <div class="text-sm text-neutral-500"><b class="text-primary-600">{{ selected.length }}</b> unit dipilih · {{ fmtRp(total) }}</div>
                        <Button type="button" @click="showPicker = false">Selesai</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
