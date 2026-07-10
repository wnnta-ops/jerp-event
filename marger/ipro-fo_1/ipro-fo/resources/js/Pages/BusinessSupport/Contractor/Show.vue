<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, HardHat, Plus, Wallet, Unlock, FilePlus2, Percent, PackageMinus, Ban, Shuffle, Printer } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import Stepper from '@/Components/UI/Stepper.vue';

const props = defineProps<{ spk: any; remaining: number; cashAccounts: Array<any>; inventoryAccounts: Array<any>; costCenters: Array<any>; defaultCash: number | null; defaultInventory: number | null }>();

const SPK_STAGES = [
    { label: 'SPK Terbit' }, { label: 'Progress' }, { label: 'Retensi' }, { label: 'Selesai' },
];
const spkStep = computed(() => {
    const r = props.spk;
    if (r.status === 'closed') return 5;
    const cv = Number(r.contract_value) || 0;
    const pv = Number(r.progress_value) || 0;
    if (cv > 0 && pv >= cv) return 3;
    if (pv > 0) return 2;
    return 1;
});

const today = new Date().toISOString().slice(0, 10);
const panel = ref<'' | 'progress' | 'retensi' | 'addendum' | 'adjustppn' | 'material' | 'cancel' | 'mutprog'>('');
const payingId = ref<number | null>(null);

const progForm = useForm<any>({ progress_date: today, description: '', progress_value: 0 });
const payForm = useForm<any>({ payment_date: today, cash_account_id: props.defaultCash ?? '' });
const retForm = useForm<any>({ settled_date: today, cash_account_id: props.defaultCash ?? '' });
const addForm = useForm<any>({ addendum_date: today, value_delta: 0, note: '' });
const ppnForm = useForm<any>({ addendum_date: today, new_ppn_pct: Number(props.spk.ppn_pct), note: '' });
const matForm = useForm<any>({ usage_date: today, description: '', amount: 0, inventory_account_id: props.defaultInventory ?? '' });
const cancelForm = useForm<any>({ cancel_date: today, reason: '' });
const mutForm = useForm<any>({ mutation_date: today, amount: 0, to_account_id: '', to_cost_center_id: '', note: '' });

const mutAvailable = computed(() => Number(props.spk.progress_value) - Number(props.spk.progress_mutated ?? 0));
function submitMutProg() { mutForm.post(route('bs.spk.mutate-progress', props.spk.id), { onSuccess: () => { panel.value = ''; mutForm.reset(); mutForm.mutation_date = today; } }); }

const statusBadge: Record<string, any> = { active: 'info', closed: 'success', cancelled: 'danger' };
const statusLabel: Record<string, string> = { active: 'Aktif', closed: 'Selesai', cancelled: 'Dibatalkan' };
const jenisLabel: Record<string, string> = { bangunan: 'Bangunan', sarana_prasarana: 'Sarana Prasarana', lain_lain: 'Lain-lain' };
const newContract = computed(() => Number(props.spk.contract_value) + (Number(addForm.value_delta) || 0));
function submitAddendum() { addForm.post(route('bs.spk.addendum', props.spk.id), { onSuccess: () => { panel.value = ''; addForm.reset(); addForm.addendum_date = today; } }); }
function submitPpn() { ppnForm.post(route('bs.spk.adjust-ppn', props.spk.id), { onSuccess: () => { panel.value = ''; } }); }
function submitMaterial() { matForm.post(route('bs.spk.material', props.spk.id), { onSuccess: () => { panel.value = ''; matForm.reset(); matForm.usage_date = today; matForm.inventory_account_id = props.defaultInventory ?? ''; } }); }
function submitCancel() { if (confirm('Batalkan SPK ini?')) cancelForm.post(route('bs.spk.cancel', props.spk.id), { onSuccess: () => { panel.value = ''; } }); }

const ret = computed(() => Math.round((Number(progForm.progress_value) || 0) * Number(props.spk.retention_pct) ) / 100);
const ppn = computed(() => Math.round((Number(progForm.progress_value) || 0) * Number(props.spk.ppn_pct)) / 100);
const net = computed(() => (Number(progForm.progress_value) || 0) + ppn.value - ret.value);

function submitProgress() { progForm.post(route('bs.spk.progress', props.spk.id), { onSuccess: () => { panel.value = ''; progForm.reset(); progForm.progress_date = today; } }); }
function submitPay(id: number) { payForm.post(route('bs.spk.progress.pay', id), { onSuccess: () => { payingId.value = null; } }); }
function submitRetensi() { retForm.post(route('bs.spk.retensi', props.spk.id), { onSuccess: () => { panel.value = ''; } }); }

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
function fmtPct(v: number) { return (Math.round((Number(v) || 0) * 100) / 100).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %'; }

// Detil Progress (gaya legacy): Progress Lama / Mutasi Progresi / Progress Terakhir (kumulatif) per termin KPL.
const progressRows = computed(() => {
    const cv = Number(props.spk.contract_value) || 0;
    let cumRaw = 0, prevRounded = 0;
    return (props.spk.progress ?? []).map((p: any, i: number) => {
        cumRaw += cv > 0 ? (Number(p.progress_value) || 0) / cv * 100 : 0;
        const terakhir = Math.round(cumRaw * 100) / 100;                    // kumulatif dibulatkan sekali
        const lama = prevRounded;
        const mut = Math.round((terakhir - prevRounded) * 100) / 100;       // mutasi = selisih → Lama + Mutasi = Terakhir
        prevRounded = terakhir;
        return { ...p, no: i + 1, mutasi_pct: mut, lama_pct: lama, terakhir_pct: terakhir };
    });
});
const catLabel: Record<string, string> = { housing: 'Housing', infra: 'Infra', lain_lain: 'Lain-lain' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> <span class="font-mono">{{ spk.spk_number }}</span></h1>
            <div class="flex items-center gap-2">
                <Link :href="route('bs.spk.print', spk.id)" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak SPK</Link>
                <Badge :variant="statusBadge[spk.status] ?? 'neutral'">{{ statusLabel[spk.status] ?? spk.status }}</Badge>
            </div>
        </div>

        <div v-if="spk.status === 'cancelled'" class="card border-l-4 border-l-danger-500 p-4 text-sm">
            <span class="font-semibold text-danger-600">SPK dibatalkan</span> · {{ fmtDate(spk.cancelled_at) }}<template v-if="spk.cancel_reason"> — {{ spk.cancel_reason }}</template>
        </div>

        <!-- Progres tahapan SPK -->
        <div class="card px-5 py-4">
            <Stepper :stages="SPK_STAGES" :current="spkStep" :cancelled="spk.status === 'cancelled'" />
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-3">
            <div><div class="text-neutral-500">Kontraktor</div><div class="font-medium">{{ spk.supplier?.name }}</div></div>
            <div><div class="text-neutral-500">Tgl SPK</div><div class="font-medium">{{ fmtDate(spk.spk_date) }}</div></div>
            <div><div class="text-neutral-500">Project</div><div class="font-medium">{{ spk.project?.name ?? spk.cost_center?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Jenis</div><div class="font-medium">{{ jenisLabel[spk.jenis] ?? spk.jenis ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Estimasi</div><div class="font-medium">{{ fmtDate(spk.est_start) }} – {{ fmtDate(spk.est_end) }}</div></div>
            <div><div class="text-neutral-500">PKP / No. SPK</div><div class="font-medium">{{ spk.is_pkp ? 'PKP' : 'Non-PKP' }}<span v-if="spk.no_manual"> · {{ spk.no_manual }}</span></div></div>
            <div><div class="text-neutral-500">Releaser</div><div class="font-medium">{{ spk.releaser ?? '—' }}</div></div>
            <div><div class="text-neutral-500">PIC Project</div><div class="font-medium">{{ spk.pic_project ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Cost Center</div><div class="font-medium">{{ spk.cost_center?.name ?? '—' }}</div></div>
            <div><div class="text-neutral-500">Nominal Profit</div><div class="font-medium">{{ Number(spk.nominal_profit) > 0 ? fmtRp(spk.nominal_profit) : '—' }}</div></div>
            <div class="md:col-span-3"><div class="text-neutral-500">Pekerjaan</div><div class="font-medium">{{ spk.title }}</div></div>
        </div>

        <!-- Detil Pekerjaan (BoQ) -->
        <div v-if="spk.items?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Detil Pekerjaan (BoQ)</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5">Satuan</th><th class="px-4 py-2.5 text-right">Qty</th><th class="px-4 py-2.5 text-right">Harga</th><th class="px-4 py-2.5 text-right">Jumlah</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="it in spk.items" :key="it.id"><td class="px-4 py-2">{{ it.description }}</td><td class="px-4 py-2 text-neutral-500">{{ it.unit ?? '—' }}</td><td class="px-4 py-2 text-right font-mono">{{ Number(it.qty).toLocaleString('id-ID') }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(it.unit_price) }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(it.amount) }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Detil Pekerjaan per Unit (Alokasi Porsi Kontrak) -->
        <div v-if="spk.units?.length" class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">
                <span>Detil Pekerjaan per Unit — Porsi Kontrak</span>
                <span class="text-xs font-normal text-neutral-400">{{ spk.units.length }} unit · Total {{ fmtRp(spk.units.reduce((s, u) => s + Number(u.unit_price || 0), 0)) }}</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5 w-12">No</th><th class="px-4 py-2.5">Unit Rumah</th><th class="px-4 py-2.5 text-right">Harga Satuan (Porsi Kontrak)</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(u, i) in spk.units" :key="u.id"><td class="px-4 py-2 tabular-nums text-neutral-500">{{ i + 1 }}</td><td class="px-4 py-2 font-mono font-medium">{{ u.unit_code }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(u.unit_price) }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Detil DP (Termin Pembayaran) -->
        <div v-if="spk.terms?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Detil DP (Termin Pembayaran)</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Keterangan</th><th class="px-4 py-2.5">Jatuh Tempo</th><th class="px-4 py-2.5 text-right">Progress / %</th><th class="px-4 py-2.5 text-right">Nominal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="t in spk.terms" :key="t.id"><td class="px-4 py-2">{{ t.description ?? '—' }}</td><td class="px-4 py-2">{{ fmtDate(t.due_date) }}</td><td class="px-4 py-2 text-right">{{ t.percentage }}%</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(t.amount) }}</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Detil Progress (dari Kontrol Progress Lapangan) -->
        <div v-if="progressRows.length" class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Detil Progress</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-2.5 w-12">No</th><th class="px-3 py-2.5">Tgl Progress</th><th class="px-3 py-2.5">No. Manual / KPL</th><th class="px-3 py-2.5">Kategori</th>
                    <th class="px-3 py-2.5 text-right">Progress Lama</th><th class="px-3 py-2.5 text-right">Mutasi Progresi</th><th class="px-3 py-2.5 text-right">Progress Terakhir</th>
                    <th class="px-3 py-2.5 text-right">Realisasi Biaya</th><th class="px-3 py-2.5">Keterangan</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in progressRows" :key="p.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2 tabular-nums text-neutral-500">{{ p.no }}</td>
                        <td class="px-3 py-2">{{ fmtDate(p.progress_date) }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ p.no_manual || p.kpl_number || '—' }}</td>
                        <td class="px-3 py-2">{{ catLabel[p.category] ?? p.category ?? 'Housing' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-neutral-500">{{ fmtPct(p.lama_pct) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-semibold text-primary-600">{{ fmtPct(p.mutasi_pct) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ fmtPct(p.terakhir_pct) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ fmtRp(p.progress_value) }}</td>
                        <td class="px-3 py-2 text-neutral-500">{{ p.description }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Nilai Kontrak</div><div class="mt-1 text-base font-bold">{{ fmtRp(spk.contract_value) }}</div><div v-if="Number(spk.addendum_value) !== 0" class="text-xs text-primary-600">incl. addendum {{ fmtRp(spk.addendum_value) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Progress</div><div class="mt-1 text-base font-bold text-primary-600">{{ fmtRp(spk.progress_value) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Pemakaian Barang</div><div class="mt-1 text-base font-bold text-teal-600">{{ fmtRp(spk.material_used) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sisa Kontrak</div><div class="mt-1 text-base font-bold">{{ fmtRp(remaining) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Retensi Ditahan</div><div class="mt-1 text-base font-bold text-amber-600">{{ fmtRp(spk.retention_held) }}</div></div>
        </div>

        <!-- Aksi -->
        <div v-if="spk.status === 'active'" class="card p-5">
            <div v-if="panel === ''" class="flex flex-wrap gap-2">
                <Button @click="panel = 'progress'" :disabled="remaining <= 0"><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Progress</Button>
                <Button v-if="Number(spk.retention_held) > 0" variant="secondary" @click="panel = 'retensi'"><template #icon-left><Unlock class="h-4 w-4" /></template>Cairkan Retensi</Button>
                <button @click="panel = 'addendum'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><FilePlus2 class="h-4 w-4" /> Addendum</button>
                <button @click="panel = 'adjustppn'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><Percent class="h-4 w-4" /> Adjust PPN</button>
                <button @click="panel = 'material'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><PackageMinus class="h-4 w-4" /> Pemakaian Barang</button>
                <button @click="panel = 'mutprog'" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium dark:border-neutral-700"><Shuffle class="h-4 w-4" /> Mutasi Progress</button>
                <button @click="panel = 'cancel'" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 px-3 py-2 text-sm font-medium text-danger-600 dark:border-danger-800"><Ban class="h-4 w-4" /> Batalkan</button>
            </div>
            <form v-else-if="panel === 'progress'" @submit.prevent="submitProgress" class="space-y-3">
                <h2 class="font-semibold">Progress Termin</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="progForm.progress_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Progress (DPP) *</label><input v-model.number="progForm.progress_value" type="number" step="1" min="1" :max="remaining" required :class="[inputCls,'text-right font-mono']" /></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Uraian *</label><input v-model="progForm.description" required placeholder="mis. Termin 1 — pondasi 30%" :class="inputCls" /></div>
                </div>
                <div class="grid grid-cols-3 gap-2 rounded-lg bg-neutral-50 p-3 text-sm dark:bg-neutral-800">
                    <div><div class="text-xs text-neutral-400">Retensi ({{ spk.retention_pct }}%)</div><div class="font-mono">{{ fmtRp(ret) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPN ({{ spk.ppn_pct }}%)</div><div class="font-mono">{{ fmtRp(ppn) }}</div></div>
                    <div><div class="text-xs text-neutral-400">Dibayarkan (net)</div><div class="font-mono font-semibold">{{ fmtRp(net) }}</div></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="progForm.processing">Simpan &amp; Jurnal</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
            <form v-else-if="panel === 'retensi'" @submit.prevent="submitRetensi" class="space-y-3">
                <h2 class="font-semibold">Cairkan Retensi — {{ fmtRp(spk.retention_held) }}</h2>
                <p class="text-sm text-neutral-500">Jurnal: Dr Hutang Retensi · Cr Kas/Bank. SPK akan ditutup.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Cair *</label><input v-model="retForm.settled_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Kas/Bank *</label><select v-model="retForm.cash_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="retForm.processing">Cairkan &amp; Tutup SPK</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Addendum nilai kontrak -->
            <form v-else-if="panel === 'addendum'" @submit.prevent="submitAddendum" class="space-y-3">
                <h2 class="font-semibold">Addendum Nilai Kontrak</h2>
                <p class="text-sm text-neutral-500">Menambah/mengurangi nilai kontrak (komitmen, tanpa jurnal). Untuk pengurangan, isi nilai negatif.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="addForm.addendum_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Perubahan Nilai (±) *</label><input v-model.number="addForm.value_delta" type="number" step="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="addForm.errors.value_delta" class="mt-1 text-xs text-danger-500">{{ addForm.errors.value_delta }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="addForm.note" :class="inputCls" placeholder="alasan addendum" /></div>
                </div>
                <div class="flex items-center justify-between rounded-lg bg-neutral-50 px-3 py-2 text-sm font-semibold dark:bg-neutral-800"><span>Nilai Kontrak Baru</span><span class="font-mono" :class="newContract < Number(spk.progress_value) ? 'text-danger-600' : 'text-primary-700'">{{ fmtRp(newContract) }}</span></div>
                <div class="flex gap-2"><Button type="submit" :disabled="addForm.processing">Simpan Addendum</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Adjust PPN -->
            <form v-else-if="panel === 'adjustppn'" @submit.prevent="submitPpn" class="space-y-3">
                <h2 class="font-semibold">Adjust PPN SPK</h2>
                <p class="text-sm text-neutral-500">Mengubah % PPN untuk progress berikutnya. Tidak mengubah jurnal yang sudah terbentuk. PPN saat ini: {{ spk.ppn_pct }}%.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="ppnForm.addendum_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN Baru (%) *</label><input v-model.number="ppnForm.new_ppn_pct" type="number" step="0.01" min="0" max="100" required :class="[inputCls,'text-right font-mono']" /><p v-if="ppnForm.errors.new_ppn_pct" class="mt-1 text-xs text-danger-500">{{ ppnForm.errors.new_ppn_pct }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="ppnForm.note" :class="inputCls" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="ppnForm.processing">Simpan PPN</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Pemakaian Barang -->
            <form v-else-if="panel === 'material'" @submit.prevent="submitMaterial" class="space-y-3">
                <h2 class="font-semibold">Pemakaian Barang (Material)</h2>
                <p class="text-sm text-neutral-500">Material dari gudang ke proyek. Jurnal: Dr Konstruksi/CIP · Cr Persediaan.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="matForm.usage_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai *</label><input v-model.number="matForm.amount" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="matForm.errors.amount" class="mt-1 text-xs text-danger-500">{{ matForm.errors.amount }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Persediaan (Kredit) *</label><SearchSelect v-model="matForm.inventory_account_id" :options="inventoryAccounts" placeholder="— cari persediaan —" /><p v-if="matForm.errors.inventory_account_id" class="mt-1 text-xs text-danger-500">{{ matForm.errors.inventory_account_id }}</p></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Uraian *</label><input v-model="matForm.description" required placeholder="mis. besi, semen, pasir" :class="inputCls" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="matForm.processing">Simpan &amp; Jurnal</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Mutasi Progress -->
            <form v-else-if="panel === 'mutprog'" @submit.prevent="submitMutProg" class="space-y-3">
                <h2 class="font-semibold">Mutasi Progress (Reklasifikasi CIP)</h2>
                <p class="text-sm text-neutral-500">Pindahkan CIP yang sudah ter-progress ke akun/cost-center lain. Jurnal: Dr CIP tujuan · Cr CIP asal ({{ spk.cost_center?.name ?? 'tanpa CC' }}). Sisa dapat dimutasi: <b>{{ fmtRp(mutAvailable) }}</b>.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="mutForm.mutation_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Mutasi *</label><input v-model.number="mutForm.amount" type="number" step="1" min="1" :max="mutAvailable" required :class="[inputCls,'text-right font-mono']" /><p v-if="mutForm.errors.amount" class="mt-1 text-xs text-danger-500">{{ mutForm.errors.amount }}</p></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun CIP Tujuan (Debit) *</label><SearchSelect v-model="mutForm.to_account_id" :options="inventoryAccounts" placeholder="— cari CIP tujuan —" /><p v-if="mutForm.errors.to_account_id" class="mt-1 text-xs text-danger-500">{{ mutForm.errors.to_account_id }}</p></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center Tujuan</label><select v-model="mutForm.to_cost_center_id" :class="inputCls"><option value="">— tanpa cost center —</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="mutForm.note" :class="inputCls" placeholder="alasan reklasifikasi" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="mutForm.processing">Simpan &amp; Jurnal</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>

            <!-- Pembatalan -->
            <form v-else-if="panel === 'cancel'" @submit.prevent="submitCancel" class="space-y-3">
                <h2 class="font-semibold text-danger-600">Batalkan SPK</h2>
                <p class="text-sm text-neutral-500">Hanya bila tak ada termin belum-bayar &amp; retensi tertahan. SPK ditandai dibatalkan.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal *</label><input v-model="cancelForm.cancel_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Alasan</label><input v-model="cancelForm.reason" :class="inputCls" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" variant="danger" :disabled="cancelForm.processing">Batalkan SPK</Button><button type="button" @click="panel = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
        </div>

        <!-- Termin -->
        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Progress Termin</div>
            <table v-if="spk.progress?.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">DPP</th><th class="px-4 py-2.5 text-right">Retensi</th><th class="px-4 py-2.5 text-right">Net Bayar</th><th class="px-4 py-2.5 text-center">Status</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="p in spk.progress" :key="p.id">
                        <tr>
                            <td class="px-4 py-2.5">{{ fmtDate(p.progress_date) }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ p.description }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(p.progress_value) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(p.retention_amount) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(p.net_payable) }}</td>
                            <td class="px-4 py-2.5 text-center"><Badge :variant="p.status === 'paid' ? 'success' : 'warning'" size="sm">{{ p.status === 'paid' ? 'Dibayar' : 'Belum' }}</Badge></td>
                            <td class="px-4 py-2.5 text-right"><button v-if="p.status === 'unpaid'" @click="payingId = payingId === p.id ? null : p.id" class="text-xs text-primary-600 hover:underline">Bayar</button></td>
                        </tr>
                        <tr v-if="payingId === p.id">
                            <td colspan="7" class="bg-neutral-50 px-4 py-3 dark:bg-neutral-800/50">
                                <form @submit.prevent="submitPay(p.id)" class="flex flex-wrap items-end gap-3">
                                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Bayar</label><input v-model="payForm.payment_date" type="date" required :class="[inputCls,'w-40']" /></div>
                                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Dari Kas/Bank</label><select v-model="payForm.cash_account_id" required :class="[inputCls,'w-56']"><option value="">— pilih —</option><option v-for="a in cashAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                                    <Button type="submit" size="sm" :disabled="payForm.processing"><template #icon-left><Wallet class="h-4 w-4" /></template>Bayar {{ fmtRp(p.net_payable) }}</Button>
                                    <button type="button" @click="payingId = null" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">Batal</button>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div v-else class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada progress termin.</div>
        </div>

        <!-- Pemakaian Barang -->
        <div v-if="spk.materials?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Pemakaian Barang</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5">Akun Persediaan</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5 text-center">Jurnal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in spk.materials" :key="m.id">
                        <td class="px-4 py-2.5">{{ fmtDate(m.usage_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ m.description }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs text-neutral-400">{{ m.inventory_account?.code }} {{ m.inventory_account?.name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-teal-600">{{ fmtRp(m.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><span v-if="m.journal_id" class="text-xs text-neutral-400">#{{ m.journal_id }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pengembalian Sisa Pemakaian -->
        <div v-if="spk.material_returns?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Pengembalian Sisa Pemakaian (Retur Material)</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Uraian</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5 text-center">Jurnal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in spk.material_returns" :key="r.id">
                        <td class="px-4 py-2.5">{{ fmtDate(r.return_date) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-emerald-600">{{ fmtRp(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><span v-if="r.journal_id" class="text-xs text-neutral-400">#{{ r.journal_id }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Riwayat Addendum -->
        <div v-if="spk.addendums?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Addendum</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Jenis</th><th class="px-4 py-2.5 text-right">Perubahan</th><th class="px-4 py-2.5 text-right">Nilai Kontrak</th><th class="px-4 py-2.5">Catatan</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="a in spk.addendums" :key="a.id">
                        <td class="px-4 py-2.5">{{ fmtDate(a.addendum_date) }}</td>
                        <td class="px-4 py-2.5"><Badge :variant="a.kind === 'ppn' ? 'info' : 'primary'" size="sm">{{ a.kind === 'ppn' ? 'PPN' : 'Nilai' }}</Badge></td>
                        <td class="px-4 py-2.5 text-right font-mono">
                            <span v-if="a.kind === 'value'" :class="Number(a.value_delta) >= 0 ? 'text-success-600' : 'text-danger-600'">{{ Number(a.value_delta) >= 0 ? '+' : '' }}{{ fmtRp(a.value_delta) }}</span>
                            <span v-else>{{ a.old_ppn_pct }}% → {{ a.new_ppn_pct }}%</span>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(a.new_contract_value) }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ a.note ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Riwayat Mutasi Progress -->
        <div v-if="spk.progress_mutations?.length" class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Mutasi Progress (Reklasifikasi CIP)</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5">Tgl</th><th class="px-4 py-2.5">Ke Akun CIP</th><th class="px-4 py-2.5">Cost Center</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5 text-center">Jurnal</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in spk.progress_mutations" :key="m.id">
                        <td class="px-4 py-2.5">{{ fmtDate(m.mutation_date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ m.to_account?.code }} {{ m.to_account?.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ m.to_cost_center?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                        <td class="px-4 py-2.5 text-center"><span v-if="m.journal_id" class="text-xs text-neutral-400">#{{ m.journal_id }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
