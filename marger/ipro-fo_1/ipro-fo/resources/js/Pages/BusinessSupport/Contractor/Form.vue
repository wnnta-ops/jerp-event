<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, HardHat, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    projects: Array<any>;
    jenisOpts: Record<string, string>;
    suppliers: Array<any>;
    costCenters: Array<any>;
    constructionAccounts: Array<any>;
    defaultConstruction: number | null;
    salesProjects: Array<any>;
}>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    spk_date: today, no_manual: '', est_start: '', est_end: '',
    project_id: '', jenis: '', supplier_id: '', cost_center_id: '',
    construction_account_id: props.defaultConstruction ?? '', title: '',
    releaser: '', pic_project: '', is_pkp: true,
    contract_value: 0, nominal_profit: 0, retention_pct: 5, advance_pct: 0, ppn_pct: 11, notes: '',
    items: [] as any[], terms: [] as any[],
    alloc_project_id: '', units: [] as any[],
});
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function addItem() { form.items.push({ description: '', unit: '', qty: 1, unit_price: 0 }); }
function rmItem(i: number) { form.items.splice(i, 1); }
function addTerm() { form.terms.push({ description: '', percentage: 0, amount: 0, due_date: '' }); }
function rmTerm(i: number) { form.terms.splice(i, 1); }
const itemsTotal = computed(() => form.items.reduce((s: number, it: any) => s + Number(it.qty || 0) * Number(it.unit_price || 0), 0));

// ── Alokasi Unit & Porsi Kontrak (Detil Pekerjaan per unit) ──
const allocLoading = ref(false);
const bulkHarga = ref<number | string>('');
let allocSeq = 0; // buang respons usang saat project alokasi berganti cepat
async function loadAllocUnits() {
    const seq = ++allocSeq;
    form.units = [];
    if (!form.alloc_project_id) return;
    allocLoading.value = true;
    try {
        const res = await window.axios.get(route('bs.spk.kpl.units'), { params: { project_id: form.alloc_project_id } });
        if (seq !== allocSeq) return;
        form.units = (res.data.units || []).map((u: any) => ({ unit_id: u.id, unit_code: u.unit_code, price: Number(u.price || 0), unit_price: 0, checked: false }));
    } catch { /* ignore */ } finally { if (seq === allocSeq) allocLoading.value = false; }
}
watch(() => form.alloc_project_id, loadAllocUnits);
const allocChecked = computed(() => (form.units as any[]).filter((u) => u.checked));
const allocTotal = computed(() => allocChecked.value.reduce((s: number, u: any) => s + (Number(u.unit_price) || 0), 0));
const allAlloc = computed(() => (form.units as any[]).length > 0 && (form.units as any[]).every((u) => u.checked));
function toggleAllAlloc(e: Event) { const v = (e.target as HTMLInputElement).checked; (form.units as any[]).forEach((u) => { u.checked = v; }); }
function applyBulkHarga() { const h = Number(bulkHarga.value) || 0; (form.units as any[]).forEach((u) => { if (u.checked) u.unit_price = h; }); }

function submit() {
    form.transform((d: any) => ({
        ...d,
        units: (d.units as any[]).filter((u) => u.checked).map((u) => ({ unit_id: u.unit_id, unit_code: u.unit_code, unit_price: Number(u.unit_price) || 0 })),
    })).post(route('bs.spk.store'));
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Registrasi SPK</h1>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl SPK <span class="text-danger-500">*</span></label><input v-model="form.spk_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. SPK <span class="text-danger-500">*</span></label><input v-model="form.no_manual" required placeholder="mis. 001/SPK/MUP-MUK/VII/2026" :class="inputCls" /><p v-if="form.errors.no_manual" class="mt-1 text-xs text-danger-500">{{ form.errors.no_manual }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project <span class="text-danger-500">*</span></label><select v-model="form.project_id" required :class="inputCls"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option></select><p v-if="form.errors.project_id" class="mt-1 text-xs text-danger-500">{{ form.errors.project_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Kontrak <span class="text-danger-500">*</span></label><select v-model="form.jenis" required :class="inputCls"><option value="">—</option><option v-for="(lbl, key) in jenisOpts" :key="key" :value="key">{{ lbl }}</option></select><p v-if="form.errors.jenis" class="mt-1 text-xs text-danger-500">{{ form.errors.jenis }}</p><p v-else class="mt-1 text-[11px] text-neutral-400">Terkunci setelah SPK dibuat — tidak dapat diubah saat penagihan.</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Est. Mulai <span class="text-danger-500">*</span></label><input v-model="form.est_start" type="date" required :class="inputCls" /><p v-if="form.errors.est_start" class="mt-1 text-xs text-danger-500">{{ form.errors.est_start }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Est. Selesai <span class="text-danger-500">*</span></label><input v-model="form.est_end" type="date" required :class="inputCls" /><p v-if="form.errors.est_end" class="mt-1 text-xs text-danger-500">{{ form.errors.est_end }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kontraktor <span class="text-danger-500">*</span></label><select v-model="form.supplier_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}<span v-if="s.category"> ({{ s.category }})</span></option></select><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center <span class="text-danger-500">*</span></label><select v-model="form.cost_center_id" required :class="inputCls"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select><p v-if="form.errors.cost_center_id" class="mt-1 text-xs text-danger-500">{{ form.errors.cost_center_id }}</p></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Uraian Pekerjaan <span class="text-danger-500">*</span></label><input v-model="form.title" required placeholder="mis. Pekerjaan struktur Cluster 3 Tahap 5" :class="inputCls" /><p v-if="form.errors.title" class="mt-1 text-xs text-danger-500">{{ form.errors.title }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Konstruksi/CIP <span class="text-danger-500">*</span></label><select v-model="form.construction_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in constructionAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Kontrak <span class="text-danger-500">*</span></label><input v-model.number="form.contract_value" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.contract_value" class="mt-1 text-xs text-danger-500">{{ form.errors.contract_value }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal Profit</label><input v-model.number="form.nominal_profit" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /><p class="mt-1 text-[11px] text-neutral-400">Opsional — estimasi profit SPK.</p></div>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 md:col-span-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Retensi (%) <span class="text-danger-500">*</span></label><input v-model.number="form.retention_pct" type="number" step="0.5" min="0" max="100" required :class="[inputCls,'text-right']" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Uang Muka (%) <span class="text-danger-500">*</span></label><input v-model.number="form.advance_pct" type="number" step="0.5" min="0" max="100" required :class="[inputCls,'text-right']" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN (%) <span class="text-danger-500">*</span></label><input v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" required :class="[inputCls,'text-right']" /></div>
                    <div class="flex items-end"><label class="inline-flex items-center gap-2 text-sm"><input v-model="form.is_pkp" type="checkbox" /> PKP</label></div>
                </div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Releaser</label><input v-model="form.releaser" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">PIC Project</label><input v-model="form.pic_project" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <!-- Detil Pekerjaan (BoQ) -->
            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between"><h2 class="text-sm font-semibold">Detil Pekerjaan (BoQ)</h2><button type="button" @click="addItem" class="inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800"><Plus class="h-4 w-4" /> Tambah Baris</button></div>
                <table v-if="form.items.length" class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-neutral-500"><th class="py-1">Uraian</th><th class="py-1 w-20">Satuan</th><th class="py-1 w-24 text-right">Qty</th><th class="py-1 w-32 text-right">Harga</th><th class="py-1 w-32 text-right">Jumlah</th><th class="w-8"></th></tr></thead>
                    <tbody>
                        <tr v-for="(it, i) in form.items" :key="i">
                            <td class="py-1 pr-2"><input v-model="it.description" :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model="it.unit" :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model.number="it.qty" type="number" step="0.01" :class="[inputCls,'text-right']" /></td>
                            <td class="py-1 pr-2"><input v-model.number="it.unit_price" type="number" step="1" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="py-1 text-right font-mono">{{ fmtRp(Number(it.qty || 0) * Number(it.unit_price || 0)) }}</td>
                            <td class="text-center"><button type="button" @click="rmItem(i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t border-neutral-200 font-semibold dark:border-neutral-700"><td colspan="4" class="py-1.5 text-right">Total BoQ</td><td class="py-1.5 text-right font-mono">{{ fmtRp(itemsTotal) }}</td><td></td></tr></tfoot>
                </table>
                <p v-else class="text-xs text-neutral-400">Opsional — rincian item pekerjaan. Total BoQ tidak wajib sama dengan nilai kontrak.</p>
            </div>

            <!-- Detil Pekerjaan per Unit (Alokasi Porsi Kontrak) -->
            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Detil Pekerjaan per Unit — Alokasi Porsi Kontrak</h2>
                    <span class="text-xs text-neutral-400">{{ allocChecked.length }} unit dipilih · Total {{ fmtRp(allocTotal) }}</span>
                </div>
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Project Sumber Unit (Sales)</label>
                        <select v-model="form.alloc_project_id" :class="inputCls" style="min-width: 240px"><option value="">— pilih project —</option><option v-for="p in salesProjects" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                    </div>
                    <div v-if="form.units.length">
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Set Harga Satuan (unit terpilih)</label>
                        <div class="flex items-center gap-2">
                            <input v-model.number="bulkHarga" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" style="width: 180px" placeholder="0" />
                            <button type="button" @click="applyBulkHarga" class="h-9 rounded-lg border border-neutral-300 px-3 text-sm hover:bg-neutral-50 dark:border-neutral-700">Terapkan</button>
                        </div>
                    </div>
                </div>
                <p v-if="allocLoading" class="text-xs text-neutral-500">Memuat unit…</p>
                <div v-else-if="form.units.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="w-10 py-1 text-center"><input type="checkbox" :checked="allAlloc" @change="toggleAllAlloc" class="h-4 w-4 rounded border-neutral-300" /></th>
                            <th class="py-1">Unit Rumah</th><th class="py-1 w-40 text-right">Harga Jual</th><th class="py-1 w-52 text-right">Harga Satuan (Porsi Kontrak)</th>
                        </tr></thead>
                        <tbody>
                            <tr v-for="u in form.units" :key="u.unit_id" :class="u.checked ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
                                <td class="py-1 text-center"><input type="checkbox" v-model="u.checked" class="h-4 w-4 rounded border-neutral-300" /></td>
                                <td class="py-1 font-medium">{{ u.unit_code }}</td>
                                <td class="py-1 text-right text-neutral-500 font-mono">{{ fmtRp(u.price) }}</td>
                                <td class="py-1 pl-2"><input v-model.number="u.unit_price" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else-if="form.alloc_project_id" class="text-xs text-warning-600">Project ini belum punya unit rumah.</p>
                <p v-else class="text-xs text-neutral-400">Opsional — pilih project, centang unit, lalu isi <b>Harga Satuan (Porsi Kontrak)</b> per unit. Dipakai saat Registrasi KPL: <b>DPP = Harga Satuan × Progress%</b>.</p>
            </div>

            <!-- Detil DP (Termin Pembayaran) -->
            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between"><h2 class="text-sm font-semibold">Detil DP (Termin Pembayaran)</h2><button type="button" @click="addTerm" class="inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800"><Plus class="h-4 w-4" /> Tambah Termin DP</button></div>
                <table v-if="form.terms.length" class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-neutral-500"><th class="py-1">Keterangan</th><th class="py-1 w-40">Tgl Jatuh Tempo</th><th class="py-1 w-28 text-right">Progress / %</th><th class="py-1 w-40 text-right">Nominal</th><th class="w-8"></th></tr></thead>
                    <tbody>
                        <tr v-for="(t, i) in form.terms" :key="i">
                            <td class="py-1 pr-2"><input v-model="t.description" placeholder="mis. Pembayaran DP Termin 1 30%" :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model="t.due_date" type="date" :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model.number="t.percentage" type="number" step="0.1" :class="[inputCls,'text-right']" /></td>
                            <td class="py-1 pr-2"><input v-model.number="t.amount" type="number" step="1" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="text-center"><button type="button" @click="rmTerm(i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-xs text-neutral-400">Opsional — termin pembayaran DP (beberapa kali). Bila Nominal kosong, dihitung dari % × nilai kontrak.</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan SPK</Button>
                <Link :href="route('bs.spk.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
