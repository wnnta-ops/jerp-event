<script setup lang="ts">
import { computed } from 'vue';
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
}>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    spk_date: today, no_manual: '', est_start: '', est_end: '',
    project_id: '', jenis: 'bangunan', supplier_id: '', cost_center_id: '',
    construction_account_id: props.defaultConstruction ?? '', title: '',
    releaser: '', pic_project: '', is_pkp: true,
    contract_value: 0, retention_pct: 5, ppn_pct: 11, notes: '',
    items: [] as any[], terms: [] as any[],
});
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function addItem() { form.items.push({ description: '', unit: '', qty: 1, unit_price: 0 }); }
function rmItem(i: number) { form.items.splice(i, 1); }
function addTerm() { form.terms.push({ description: '', percentage: 0, amount: 0, due_date: '' }); }
function rmTerm(i: number) { form.terms.splice(i, 1); }
const itemsTotal = computed(() => form.items.reduce((s: number, it: any) => s + Number(it.qty || 0) * Number(it.unit_price || 0), 0));
function submit() { form.post(route('bs.spk.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><HardHat class="h-6 w-6 text-amber-600" /> Registrasi SPK</h1>

        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl SPK *</label><input v-model="form.spk_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Manual</label><input v-model="form.no_manual" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Project</label><select v-model="form.project_id" :class="inputCls"><option value="">—</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jenis</label><select v-model="form.jenis" :class="inputCls"><option v-for="(lbl, key) in jenisOpts" :key="key" :value="key">{{ lbl }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Est. Mulai</label><input v-model="form.est_start" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Est. Selesai</label><input v-model="form.est_end" type="date" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kontraktor *</label><select v-model="form.supplier_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}<span v-if="s.category"> ({{ s.category }})</span></option></select><p v-if="form.errors.supplier_id" class="mt-1 text-xs text-danger-500">{{ form.errors.supplier_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Cost Center</label><select v-model="form.cost_center_id" :class="inputCls"><option value="">—</option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Uraian Pekerjaan *</label><input v-model="form.title" required placeholder="mis. Pekerjaan struktur Cluster 3 Tahap 5" :class="inputCls" /><p v-if="form.errors.title" class="mt-1 text-xs text-danger-500">{{ form.errors.title }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Akun Konstruksi/CIP *</label><select v-model="form.construction_account_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="a in constructionAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Kontrak *</label><input v-model.number="form.contract_value" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.contract_value" class="mt-1 text-xs text-danger-500">{{ form.errors.contract_value }}</p></div>
                <div class="grid grid-cols-3 gap-3 md:col-span-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Retensi (%)</label><input v-model.number="form.retention_pct" type="number" step="0.5" min="0" max="100" :class="[inputCls,'text-right']" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">PPN (%)</label><input v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" :class="[inputCls,'text-right']" /></div>
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

            <!-- Detil Term (jadwal termin) -->
            <div class="card p-5">
                <div class="mb-2 flex items-center justify-between"><h2 class="text-sm font-semibold">Detil Term (Rencana Termin)</h2><button type="button" @click="addTerm" class="inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-800"><Plus class="h-4 w-4" /> Tambah Term</button></div>
                <table v-if="form.terms.length" class="w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase text-neutral-500"><th class="py-1">Keterangan</th><th class="py-1 w-24 text-right">%</th><th class="py-1 w-36 text-right">Nilai</th><th class="py-1 w-40">Jatuh Tempo</th><th class="w-8"></th></tr></thead>
                    <tbody>
                        <tr v-for="(t, i) in form.terms" :key="i">
                            <td class="py-1 pr-2"><input v-model="t.description" placeholder="Termin 1 - DP" :class="inputCls" /></td>
                            <td class="py-1 pr-2"><input v-model.number="t.percentage" type="number" step="0.1" :class="[inputCls,'text-right']" /></td>
                            <td class="py-1 pr-2"><input v-model.number="t.amount" type="number" step="1" :class="[inputCls,'text-right font-mono']" /></td>
                            <td class="py-1 pr-2"><input v-model="t.due_date" type="date" :class="inputCls" /></td>
                            <td class="text-center"><button type="button" @click="rmTerm(i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="text-xs text-neutral-400">Opsional — rencana termin (bila kosong, isi nilai/% akan dihitung dari nilai kontrak).</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan SPK</Button>
                <Link :href="route('bs.spk.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
