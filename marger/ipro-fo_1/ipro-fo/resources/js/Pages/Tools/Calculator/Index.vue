<script setup lang="ts">
import { ref, reactive } from 'vue';
import axios from 'axios';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

interface Scheme {
    id: number; code: string; name: string; type: string;
    down_payment_pct: string; installment_months: number; discount_pct: string;
}

const props = defineProps<{
    schemes: Scheme[];
    default_npotkp: number;
    default_ppn_pct: number;
}>();

const activeTab = ref<'bphtb' | 'kpr' | 'skema'>('bphtb');

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v ?? 0);
}

// ── BPHTB ───────────────────────────────────────────────
const bphtbForm = reactive({
    land_area: 90, building_area: 36,
    njop_per_m2_land: 3_000_000, njop_per_m2_building: 4_500_000,
    ajb_value: 500_000_000, npotkp: props.default_npotkp,
});
const bphtbResult = ref<any>(null);
const bphtbLoading = ref(false);

async function calcBphtb() {
    bphtbLoading.value = true;
    try {
        const { data } = await axios.post(route('calculator.bphtb'), bphtbForm);
        bphtbResult.value = data;
    } finally { bphtbLoading.value = false; }
}

// ── KPR ─────────────────────────────────────────────────
const kprForm = reactive({ loan: 500_000_000, tenor_months: 240, annual_rate: 7.5 });
const kprResult = ref<any>(null);
const kprLoading = ref(false);

async function calcKpr() {
    kprLoading.value = true;
    try {
        const { data } = await axios.post(route('calculator.kpr'), kprForm);
        kprResult.value = data;
    } finally { kprLoading.value = false; }
}

// ── SKEMA ───────────────────────────────────────────────
const skemaForm = reactive({
    payment_scheme_id: props.schemes[0]?.id ?? null,
    price_list: 1_000_000_000,
    discount_pct: 0,
    additional_discount: 0,
    ppn_pct: props.default_ppn_pct,
});
const skemaResult = ref<any>(null);
const skemaLoading = ref(false);

async function calcSkema() {
    if (!skemaForm.payment_scheme_id) return;
    skemaLoading.value = true;
    try {
        const { data } = await axios.post(route('calculator.skema'), skemaForm);
        skemaResult.value = data;
    } finally { skemaLoading.value = false; }
}

const tabs = [
    { key: 'bphtb', label: '🏛️ BPHTB' },
    { key: 'kpr', label: '🏦 KPR' },
    { key: 'skema', label: '📋 Skema Cara Bayar' },
] as const;
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-4">
        <div>
            <h1 class="text-2xl font-bold">Kalkulator</h1>
            <p class="text-sm text-neutral-500 mt-1">Tools pre-sales: estimasi BPHTB, simulasi cicilan KPR, dan skema cara bayar.</p>
        </div>

        <!-- Tabs -->
        <div class="card p-0 overflow-hidden">
            <div class="flex border-b border-neutral-200 dark:border-neutral-800 bg-primary-50 dark:bg-neutral-900">
                <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
                    :class="['px-5 py-3 text-sm font-medium transition',
                        activeTab === t.key
                            ? 'bg-white dark:bg-neutral-800 border-b-2 border-primary-600 text-primary-700 dark:text-primary-300'
                            : 'text-neutral-600 dark:text-neutral-400 hover:bg-white/50']">
                    {{ t.label }}
                </button>
            </div>

            <div class="p-6">
                <!-- BPHTB -->
                <div v-if="activeTab === 'bphtb'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <h2 class="font-semibold">Input</h2>
                        <div class="grid grid-cols-2 gap-3">
                            <Input v-model.number="bphtbForm.land_area" label="Luas Tanah (m²)" type="number" />
                            <Input v-model.number="bphtbForm.building_area" label="Luas Bangunan (m²)" type="number" />
                            <Input v-model.number="bphtbForm.njop_per_m2_land" label="NJOP Tanah / m² (Rp)" type="number" />
                            <Input v-model.number="bphtbForm.njop_per_m2_building" label="NJOP Bangunan / m² (Rp)" type="number" />
                            <Input v-model.number="bphtbForm.ajb_value" label="Nilai AJB (Rp)" type="number" />
                            <Input v-model.number="bphtbForm.npotkp" label="NPOTKP (Rp)" type="number" />
                        </div>
                        <Button :loading="bphtbLoading" @click="calcBphtb">Hitung BPHTB</Button>
                    </div>

                    <div v-if="bphtbResult" class="space-y-2">
                        <h2 class="font-semibold">Hasil</h2>
                        <dl class="text-sm space-y-1">
                            <div class="flex justify-between"><dt class="text-neutral-500">NJOP Tanah:</dt><dd>{{ fmtRp(bphtbResult.njop_tanah) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">NJOP Bangunan:</dt><dd>{{ fmtRp(bphtbResult.njop_bangunan) }}</dd></div>
                            <div class="flex justify-between border-t pt-1"><dt class="text-neutral-500">Total NJOP:</dt><dd class="font-semibold">{{ fmtRp(bphtbResult.total_njop) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">NPOP (max AJB vs NJOP):</dt><dd>{{ fmtRp(bphtbResult.npop) }}</dd></div>
                            <div class="flex justify-between text-danger-600"><dt>NPOTKP:</dt><dd>- {{ fmtRp(bphtbResult.npotkp) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">NPOPKP:</dt><dd>{{ fmtRp(bphtbResult.npopkp) }}</dd></div>
                            <div class="flex justify-between border-t pt-2 mt-2 text-lg font-bold">
                                <dt>BPHTB (5%):</dt><dd class="text-primary-600">{{ fmtRp(bphtbResult.bphtb) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-else class="flex items-center justify-center text-neutral-400 text-sm">Isi input lalu klik Hitung.</div>
                </div>

                <!-- KPR -->
                <div v-else-if="activeTab === 'kpr'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <h2 class="font-semibold">Input</h2>
                        <Input v-model.number="kprForm.loan" label="Nilai KPR / Pokok Pinjaman (Rp)" type="number" />
                        <Input v-model.number="kprForm.tenor_months" label="Tenor (Bulan)" type="number" />
                        <Input v-model.number="kprForm.annual_rate" label="Bunga Efektif (% / tahun)" type="number" />
                        <Button :loading="kprLoading" @click="calcKpr">Hitung Cicilan</Button>
                    </div>

                    <div v-if="kprResult" class="space-y-2">
                        <h2 class="font-semibold">Hasil</h2>
                        <div class="card p-4 bg-primary-50 dark:bg-primary-900/20">
                            <p class="text-sm text-neutral-500">Cicilan Bulanan</p>
                            <p class="text-2xl font-bold text-primary-600">{{ fmtRp(kprResult.monthly_installment) }}</p>
                        </div>
                        <dl class="text-sm space-y-1 mt-3">
                            <div class="flex justify-between"><dt class="text-neutral-500">Total Pembayaran:</dt><dd>{{ fmtRp(kprResult.total_payment) }}</dd></div>
                            <div class="flex justify-between text-danger-600"><dt>Total Bunga:</dt><dd>{{ fmtRp(kprResult.total_interest) }}</dd></div>
                        </dl>
                        <div v-if="kprResult.schedule_preview?.length" class="mt-3">
                            <p class="text-xs font-semibold text-neutral-500 mb-1">Preview 12 bulan pertama:</p>
                            <table class="w-full text-xs">
                                <thead class="text-neutral-400"><tr><th class="text-left">Bln</th><th class="text-right">Pokok</th><th class="text-right">Bunga</th><th class="text-right">Sisa</th></tr></thead>
                                <tbody>
                                    <tr v-for="r in kprResult.schedule_preview" :key="r.month" class="border-t border-neutral-100 dark:border-neutral-800">
                                        <td>{{ r.month }}</td>
                                        <td class="text-right">{{ fmtRp(r.principal) }}</td>
                                        <td class="text-right text-danger-500">{{ fmtRp(r.interest) }}</td>
                                        <td class="text-right">{{ fmtRp(r.balance) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-else class="flex items-center justify-center text-neutral-400 text-sm">Isi input lalu klik Hitung.</div>
                </div>

                <!-- SKEMA -->
                <div v-else-if="activeTab === 'skema'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <h2 class="font-semibold">Input</h2>
                        <div>
                            <label class="label-base">Skema Pembayaran</label>
                            <select v-model.number="skemaForm.payment_scheme_id" class="input-base">
                                <option v-for="s in schemes" :key="s.id" :value="s.id">
                                    {{ s.name }} (DP {{ s.down_payment_pct }}%, {{ s.installment_months }}bln)
                                </option>
                            </select>
                        </div>
                        <Input v-model.number="skemaForm.price_list" label="Price List Unit (Rp)" type="number" />
                        <div class="grid grid-cols-2 gap-3">
                            <Input v-model.number="skemaForm.discount_pct" label="Diskon (%)" type="number" />
                            <Input v-model.number="skemaForm.additional_discount" label="Diskon Tambahan (Rp)" type="number" />
                        </div>
                        <Input v-model.number="skemaForm.ppn_pct" label="PPN (%)" type="number" />
                        <Button :loading="skemaLoading" @click="calcSkema">Hitung Skema</Button>
                    </div>

                    <div v-if="skemaResult" class="space-y-2">
                        <h2 class="font-semibold">Hasil — {{ skemaResult.scheme.name }}</h2>
                        <dl class="text-sm space-y-1">
                            <div class="flex justify-between"><dt class="text-neutral-500">Price List:</dt><dd>{{ fmtRp(skemaResult.price_list) }}</dd></div>
                            <div class="flex justify-between text-danger-600"><dt>Diskon ({{ skemaResult.discount_pct }}%):</dt><dd>- {{ fmtRp(skemaResult.discount_value) }}</dd></div>
                            <div v-if="skemaResult.additional_discount" class="flex justify-between text-danger-600"><dt>Diskon Tambahan:</dt><dd>- {{ fmtRp(skemaResult.additional_discount) }}</dd></div>
                            <div class="flex justify-between border-t pt-1"><dt class="text-neutral-500">DPP:</dt><dd>{{ fmtRp(skemaResult.dpp) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">PPN ({{ skemaResult.ppn_pct }}%):</dt><dd>{{ fmtRp(skemaResult.ppn_value) }}</dd></div>
                            <div class="flex justify-between border-t pt-2 mt-2 text-lg font-bold">
                                <dt>Nilai Kontrak:</dt><dd class="text-primary-600">{{ fmtRp(skemaResult.nilai_kontrak) }}</dd>
                            </div>
                            <div class="flex justify-between pt-2 border-t mt-2"><dt class="text-neutral-500">DP ({{ skemaResult.dp_pct }}%):</dt><dd class="font-semibold">{{ fmtRp(skemaResult.dp_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-neutral-500">Sisa setelah DP:</dt><dd>{{ fmtRp(skemaResult.sisa_setelah_dp) }}</dd></div>
                            <div v-if="skemaResult.installment_months" class="flex justify-between text-success-600">
                                <dt>Cicilan / bln ({{ skemaResult.installment_months }} bln):</dt><dd class="font-semibold">{{ fmtRp(skemaResult.installment_per_month) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-else class="flex items-center justify-center text-neutral-400 text-sm">Pilih skema lalu klik Hitung.</div>
                </div>
            </div>
        </div>
    </div>
</template>
