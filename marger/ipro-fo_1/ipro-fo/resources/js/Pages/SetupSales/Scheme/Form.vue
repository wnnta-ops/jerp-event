<script setup lang="ts">
import { ref, reactive } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, Layers, Plus, X, Calculator, Save } from 'lucide-vue-next';

const props = defineProps<{
    projects: Array<{ id: number; name: string }>;
    types: Record<string, string>;
    bfTreatments: Record<string, string>;
    scheme?: any;
}>();

const isEdit = !!props.scheme;
const s = props.scheme ?? {};

const form = useForm({
    project_id: s.project_id ?? null,
    code: s.code ?? '',
    name: s.name ?? '',
    type: s.type ?? 'kpr',
    is_active: s.is_active ?? true,
    // Rumus Global
    present_value: s.present_value ?? false,
    tiered_discounts: (s.tiered_discounts ?? []) as number[],
    booking_fee: s.booking_fee ?? 0,
    bf_treatment: s.bf_treatment ?? 'tidak',
    // Rumus Nilai
    dp_count: s.dp_count ?? 1,
    dp_basis: s.dp_basis ?? 'percent',
    dp_value: s.dp_value ?? 20,
    inst_count: s.inst_count ?? 0,
    inst_basis: s.inst_basis ?? 'percent',
    inst_value: s.inst_value ?? 0,
    // Rumus Jadwal
    schedule_first: s.schedule_first ?? 1,
    schedule_interval: s.schedule_interval ?? 1,
    schedule_unit: s.schedule_unit ?? 'month',
    discount_pct: s.discount_pct ?? 0,
    down_payment_pct: s.down_payment_pct ?? 0,
    notes: s.notes ?? '',
});

function addTier() { form.tiered_discounts.push(0); }
function rmTier(i: number) { form.tiered_discounts.splice(i, 1); }

// ── Live preview ──
const samplePv = ref(1_000_000_000);
const startDate = ref('');
const preview = reactive<{ loading: boolean; data: any | null; error: string }>({ loading: false, data: null, error: '' });

async function runPreview() {
    preview.loading = true; preview.error = '';
    try {
        const { data } = await axios.post(route('setup-sales.scheme.preview'), {
            sample_pv: samplePv.value, start_date: startDate.value || null,
            tiered_discounts: form.tiered_discounts, booking_fee: form.booking_fee, bf_treatment: form.bf_treatment,
            dp_count: form.dp_count, dp_basis: form.dp_basis, dp_value: form.dp_value,
            inst_count: form.inst_count, inst_basis: form.inst_basis, inst_value: form.inst_value,
            schedule_first: form.schedule_first, schedule_interval: form.schedule_interval, schedule_unit: form.schedule_unit,
        });
        preview.data = data;
    } catch (e: any) {
        preview.error = e?.response?.data?.message ?? 'Gagal menghitung preview.';
    } finally {
        preview.loading = false;
    }
}

function submit() {
    if (isEdit) form.put(route('setup-sales.scheme.update', props.scheme.id));
    else form.post(route('setup-sales.scheme.store'));
}

const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const fmtDate = (d: string) => new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <form @submit.prevent="submit" class="space-y-5">
        <Link :href="route('setup-sales.scheme.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Skema Cara Bayar</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> {{ isEdit ? 'Edit' : 'Buat' }} Skema Cara Bayar</h1>

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Kolom kiri: form -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Rumus Global -->
                <div class="card p-4">
                    <div class="mb-3 text-sm font-semibold text-primary-700">Rumus Global</div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :class="labelCls">Project</label><select v-model="form.project_id" :class="inputCls"><option :value="null">— Semua Project —</option><option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <div><label :class="labelCls">Tipe</label><select v-model="form.type" :class="inputCls"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
                        <div><label :class="labelCls">Kode Skema *</label><input v-model="form.code" :class="inputCls" placeholder="mis. KPR-STD" required /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-600">{{ form.errors.code }}</p></div>
                        <div><label :class="labelCls">Nama Skema *</label><input v-model="form.name" :class="inputCls" placeholder="mis. KPR Standar 20%" required /></div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-5">
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.present_value" class="rounded border-neutral-300" /> Hitung Present Value</label>
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded border-neutral-300" /> Aktif</label>
                    </div>
                    <!-- Diskon bertingkat -->
                    <div class="mt-3">
                        <label :class="labelCls">Diskon Bertingkat (%)</label>
                        <div class="flex flex-wrap items-center gap-2">
                            <div v-for="(d, i) in form.tiered_discounts" :key="i" class="flex items-center gap-1">
                                <input type="number" step="0.01" v-model.number="form.tiered_discounts[i]" class="h-9 w-20 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                <button type="button" @click="rmTier(i)" class="text-danger-500 hover:text-danger-700"><X class="h-4 w-4" /></button>
                            </div>
                            <button type="button" @click="addTier" class="inline-flex items-center gap-1 rounded-lg border border-dashed border-neutral-300 px-2 py-1.5 text-xs text-neutral-500 hover:border-primary-400 hover:text-primary-600"><Plus class="h-3.5 w-3.5" /> Tambah</button>
                        </div>
                    </div>
                    <!-- Booking Fee -->
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label :class="labelCls">Booking Fee (Rp)</label><input type="number" v-model.number="form.booking_fee" :class="inputCls" /></div>
                        <div><label :class="labelCls">Booking Fee Dipotong di</label><select v-model="form.bf_treatment" :class="inputCls"><option v-for="(l, k) in bfTreatments" :key="k" :value="k">{{ l }}</option></select></div>
                    </div>
                </div>

                <!-- Rumus Nilai -->
                <div class="card p-4">
                    <div class="mb-3 text-sm font-semibold text-accent-700">Rumus Nilai (n × nilai)</div>
                    <div class="space-y-3">
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 text-xs font-semibold text-neutral-500 sm:col-span-2">Down Payment</div>
                            <div class="col-span-4 sm:col-span-3"><label :class="labelCls">Jumlah (n)</label><input type="number" min="0" v-model.number="form.dp_count" :class="inputCls" /></div>
                            <div class="col-span-4 sm:col-span-3"><label :class="labelCls">Basis</label><select v-model="form.dp_basis" :class="inputCls"><option value="percent">Persen (%)</option><option value="amount">Rupiah</option></select></div>
                            <div class="col-span-4 sm:col-span-4"><label :class="labelCls">Nilai per termin</label><input type="number" step="0.01" v-model.number="form.dp_value" :class="inputCls" /></div>
                        </div>
                        <div class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-12 text-xs font-semibold text-neutral-500 sm:col-span-2">Angsuran</div>
                            <div class="col-span-4 sm:col-span-3"><label :class="labelCls">Jumlah (n)</label><input type="number" min="0" v-model.number="form.inst_count" :class="inputCls" /></div>
                            <div class="col-span-4 sm:col-span-3"><label :class="labelCls">Basis</label><select v-model="form.inst_basis" :class="inputCls"><option value="percent">Persen (%)</option><option value="amount">Rupiah</option></select></div>
                            <div class="col-span-4 sm:col-span-4"><label :class="labelCls">Nilai per termin</label><input type="number" step="0.01" v-model.number="form.inst_value" :class="inputCls" /></div>
                        </div>
                    </div>
                </div>

                <!-- Rumus Jadwal -->
                <div class="card p-4">
                    <div class="mb-3 text-sm font-semibold text-success-700">Rumus Jadwal</div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label :class="labelCls">Jatuh Tempo Pertama</label><input type="number" min="0" v-model.number="form.schedule_first" :class="inputCls" /></div>
                        <div><label :class="labelCls">Interval</label><input type="number" min="1" v-model.number="form.schedule_interval" :class="inputCls" /></div>
                        <div><label :class="labelCls">Satuan</label><select v-model="form.schedule_unit" :class="inputCls"><option value="month">Bulan</option><option value="day">Hari</option></select></div>
                    </div>
                    <div class="mt-3"><label :class="labelCls">Catatan</label><textarea v-model="form.notes" rows="2" :class="inputCls.replace('h-9','')"></textarea></div>
                </div>

                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Save class="h-4 w-4" /> {{ isEdit ? 'Simpan Perubahan' : 'Simpan Skema' }}</button>
            </div>

            <!-- Kolom kanan: preview -->
            <div class="space-y-3">
                <div class="card sticky top-4 p-4">
                    <div class="mb-3 flex items-center gap-2 text-sm font-semibold"><Calculator class="h-4 w-4 text-primary-600" /> Preview Jadwal</div>
                    <div class="space-y-2">
                        <div><label :class="labelCls">Contoh Harga (PV)</label><input type="number" v-model.number="samplePv" :class="inputCls" /></div>
                        <div><label :class="labelCls">Tanggal Mulai (opsional)</label><input type="date" v-model="startDate" :class="inputCls" /></div>
                        <button type="button" @click="runPreview" :disabled="preview.loading" class="w-full rounded-lg bg-neutral-800 px-3 py-2 text-sm font-semibold text-white hover:bg-neutral-900 disabled:opacity-50 dark:bg-neutral-700">{{ preview.loading ? 'Menghitung…' : 'Hitung Preview' }}</button>
                    </div>
                    <p v-if="preview.error" class="mt-2 text-xs text-danger-600">{{ preview.error }}</p>

                    <div v-if="preview.data" class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between text-xs text-neutral-500"><span>PV Kotor</span><span>{{ rb(preview.data.pv_gross) }}</span></div>
                        <div v-if="preview.data.discount_steps?.length" class="flex justify-between text-xs text-amber-600"><span>Diskon</span><span>{{ preview.data.discount_steps.join('% → ') }}%</span></div>
                        <div class="flex justify-between border-b border-neutral-200 pb-1 text-xs font-semibold dark:border-neutral-700"><span>PV Bersih</span><span>{{ rb(preview.data.pv_net) }}</span></div>
                        <div class="max-h-64 overflow-y-auto">
                            <table class="w-full text-xs">
                                <thead class="text-neutral-400"><tr><th class="py-1 text-left">Termin</th><th class="py-1 text-right">Jatuh Tempo</th><th class="py-1 text-right">Nominal</th></tr></thead>
                                <tbody>
                                    <tr v-for="row in preview.data.rows" :key="row.no" class="border-t border-neutral-100 dark:border-neutral-800">
                                        <td class="py-1">{{ row.label }}</td>
                                        <td class="py-1 text-right text-neutral-500">{{ fmtDate(row.due_date) }}</td>
                                        <td class="py-1 text-right tabular-nums">{{ rb(row.amount) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="space-y-1 border-t border-neutral-200 pt-2 text-xs dark:border-neutral-700">
                            <div class="flex justify-between"><span class="text-neutral-500">Booking Fee</span><span>{{ rb(preview.data.totals.booking_fee) }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Total DP</span><span>{{ rb(preview.data.totals.dp_total) }}</span></div>
                            <div class="flex justify-between"><span class="text-neutral-500">Total Angsuran</span><span>{{ rb(preview.data.totals.inst_total) }}</span></div>
                            <div class="flex justify-between font-semibold"><span>Dijadwalkan</span><span>{{ rb(preview.data.totals.scheduled_total) }}</span></div>
                            <div class="flex justify-between" :class="preview.data.totals.bank_portion > 0 ? 'text-primary-600' : 'text-neutral-400'"><span>Porsi Bank/KPR</span><span>{{ rb(preview.data.totals.bank_portion) }}</span></div>
                        </div>
                    </div>
                    <p v-else-if="!preview.error" class="mt-3 text-xs text-neutral-400">Isi rumus lalu klik “Hitung Preview” untuk melihat simulasi jadwal pembayaran.</p>
                </div>
            </div>
        </div>
    </form>
</template>
