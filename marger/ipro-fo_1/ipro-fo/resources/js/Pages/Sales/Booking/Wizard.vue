<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { debounce } from 'lodash-es';
import { Check, ChevronRight, ChevronLeft } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    project_id: number;
    projects: Array<any>;
    units_available: Array<any>;
    payment_schemes: Array<any>;
    gimmicks: Array<any>;
    ppn_pct: number;
}>();

const step = ref(1);

const form = useForm({
    project_id:        props.project_id,
    customer_id:       null as number | null,
    unit_id:           null as number | null,
    payment_scheme_id: null as number | null,
    discount_pct:      0,
    gimmick_ids:       [] as number[],
    booking_date:      new Date().toISOString().slice(0, 10),
    booking_source:    'direct',
    notes:             '',
});

// Customer selection (search)
const customerSearch = ref('');
const customerResults = ref<any[]>([]);
const selectedCustomer = ref<any>(null);

const searchCustomers = debounce(async () => {
    if (customerSearch.value.length < 2) {
        customerResults.value = [];
        return;
    }
    const r = await axios.get(route('customers.search'), { params: { q: customerSearch.value }, withCredentials: true });
    customerResults.value = (r.data.data ?? []).filter((d: any) => d.type === 'customer');
}, 250);
watch(customerSearch, searchCustomers);

function pickCustomer(c: any) {
    selectedCustomer.value = c;
    form.customer_id = c.id;
}

// Unit pricing preview
const selectedUnit = computed(() => props.units_available.find(u => u.id === form.unit_id));
const pricing = ref<any>({ base: 0, discount: 0, ppn: 0, total: 0 });

async function recalcPricing() {
    if (!form.unit_id) return;
    const r = await axios.post(`/bookings/preview`, {
        unit_id: form.unit_id,
        discount_pct: form.discount_pct || 0,
        gimmick_ids: form.gimmick_ids,
    }, { withCredentials: true });
    pricing.value = r.data.data;
}

watch([() => form.unit_id, () => form.discount_pct, () => form.gimmick_ids], recalcPricing, { deep: true });

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function canNext(): boolean {
    if (step.value === 1) return !!form.customer_id;
    if (step.value === 2) return !!form.unit_id;
    if (step.value === 3) return !!form.payment_scheme_id;
    return true;
}

function next() { if (step.value < 5 && canNext()) step.value++; }
function back() { if (step.value > 1) step.value--; }

function submit() {
    form.post(route('bookings.store'));
}

const steps = [
    { n: 1, label: 'Customer' },
    { n: 2, label: 'Unit' },
    { n: 3, label: 'Skema Bayar' },
    { n: 4, label: 'Diskon & Gimmick' },
    { n: 5, label: 'Konfirmasi' },
];
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-4">
        <Link :href="route('bookings.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">Booking Baru</h1>

        <!-- Step indicator -->
        <div class="card p-4">
            <div class="flex items-center justify-between">
                <div v-for="(s, i) in steps" :key="s.n" class="flex-1 flex items-center">
                    <div class="flex items-center gap-2">
                        <div :class="[
                            'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold',
                            step >= s.n ? 'bg-primary-600 text-white' : 'bg-neutral-200 dark:bg-neutral-700 text-neutral-500',
                        ]">
                            <Check v-if="step > s.n" class="h-4 w-4" />
                            <span v-else>{{ s.n }}</span>
                        </div>
                        <span class="text-sm hidden md:inline">{{ s.label }}</span>
                    </div>
                    <div v-if="i < steps.length - 1" class="flex-1 h-0.5 mx-2"
                        :class="step > s.n ? 'bg-primary-600' : 'bg-neutral-200 dark:bg-neutral-700'" />
                </div>
            </div>
        </div>

        <!-- Step 1: Customer -->
        <div v-if="step === 1" class="card p-6 space-y-4">
            <h2 class="font-semibold">Step 1: Pilih Customer</h2>
            <Input v-model="customerSearch" label="Cari Customer" placeholder="Nama atau kode..." />
            <div v-if="customerResults.length" class="space-y-2">
                <button v-for="c in customerResults" :key="c.id"
                    @click="pickCustomer(c)"
                    :class="['w-full text-left p-3 rounded-lg border', selectedCustomer?.id === c.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-200 dark:border-neutral-700']"
                >
                    <p class="font-medium">{{ c.label }}</p>
                </button>
            </div>
            <div v-if="selectedCustomer" class="p-3 bg-success-500/10 rounded-lg text-sm">
                ✓ Terpilih: <strong>{{ selectedCustomer.label }}</strong>
            </div>
        </div>

        <!-- Step 2: Unit -->
        <div v-if="step === 2" class="card p-6 space-y-4">
            <h2 class="font-semibold">Step 2: Pilih Unit</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 max-h-96 overflow-y-auto">
                <button v-for="u in units_available" :key="u.id"
                    @click="form.unit_id = u.id"
                    :class="['p-4 rounded-lg border text-left', form.unit_id === u.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-200 dark:border-neutral-700']"
                >
                    <p class="font-mono font-bold">{{ u.unit_code }}</p>
                    <p class="text-xs text-neutral-500">{{ u.unit_type?.name }} · {{ u.unit_type?.lt }}/{{ u.unit_type?.lb }} m²</p>
                    <p class="text-sm font-semibold mt-2">{{ fmtRp(u.current_price) }}</p>
                </button>
            </div>
        </div>

        <!-- Step 3: Scheme -->
        <div v-if="step === 3" class="card p-6 space-y-4">
            <h2 class="font-semibold">Step 3: Skema Pembayaran</h2>
            <div class="space-y-3">
                <button v-for="s in payment_schemes" :key="s.id"
                    @click="form.payment_scheme_id = s.id"
                    :class="['w-full text-left p-4 rounded-lg border', form.payment_scheme_id === s.id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-200 dark:border-neutral-700']"
                >
                    <div class="flex items-center justify-between">
                        <p class="font-semibold">{{ s.name }}</p>
                        <span class="text-xs px-2 py-1 rounded bg-neutral-200 dark:bg-neutral-700">{{ s.type }}</span>
                    </div>
                    <p class="text-sm text-neutral-500">DP {{ s.down_payment_pct }}% · {{ s.terms?.length ?? 0 }} termin</p>
                </button>
            </div>
        </div>

        <!-- Step 4: Diskon + Gimmick -->
        <div v-if="step === 4" class="card p-6 space-y-4">
            <h2 class="font-semibold">Step 4: Diskon & Gimmick</h2>
            <Input v-model.number="form.discount_pct" type="number" label="Diskon (%)" step="0.1" />
            <p class="text-xs text-neutral-500">Diskon > 3% akan otomatis butuh approval Sales Manager. Diskon > 7% butuh Direktur Sales.</p>

            <div>
                <label class="label-base">Gimmick (multiple)</label>
                <div class="space-y-2">
                    <label v-for="g in gimmicks" :key="g.id" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" :value="g.id" v-model="form.gimmick_ids" />
                        <span>{{ g.name }} ({{ fmtRp(g.value) }})</span>
                    </label>
                </div>
            </div>

            <div class="border-t pt-4 mt-4">
                <h3 class="font-semibold mb-3">Preview Harga</h3>
                <dl class="text-sm space-y-1">
                    <div class="flex justify-between"><dt>Harga Dasar:</dt><dd>{{ fmtRp(pricing.base) }}</dd></div>
                    <div class="flex justify-between text-danger-600"><dt>Diskon:</dt><dd>- {{ fmtRp(pricing.discount) }}</dd></div>
                    <div class="flex justify-between"><dt>Gimmick:</dt><dd>{{ fmtRp(pricing.gimmick) }}</dd></div>
                    <div class="flex justify-between"><dt>PPN {{ ppn_pct }}%:</dt><dd>{{ fmtRp(pricing.ppn) }}</dd></div>
                    <div class="flex justify-between border-t pt-2 mt-2 font-bold text-lg">
                        <dt>TOTAL:</dt><dd class="text-primary-600">{{ fmtRp(pricing.total) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Step 5: Konfirmasi -->
        <div v-if="step === 5" class="card p-6 space-y-4">
            <h2 class="font-semibold">Step 5: Konfirmasi</h2>
            <Input v-model="form.booking_date" type="date" label="Tanggal Booking" />
            <div>
                <label class="label-base">Catatan</label>
                <textarea v-model="form.notes" class="input-base h-20" placeholder="Catatan opsional..."></textarea>
            </div>

            <div class="card p-4 bg-neutral-50 dark:bg-neutral-800">
                <p class="font-semibold mb-3">Ringkasan Booking</p>
                <dl class="text-sm space-y-1.5">
                    <div class="flex justify-between"><dt class="text-neutral-500">Customer:</dt><dd>{{ selectedCustomer?.label }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Unit:</dt><dd>{{ selectedUnit?.unit_code }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Skema:</dt><dd>{{ payment_schemes.find(s => s.id === form.payment_scheme_id)?.name }}</dd></div>
                    <div class="flex justify-between font-bold pt-2 border-t mt-2"><dt>Total Harga:</dt><dd class="text-primary-600">{{ fmtRp(pricing.total) }}</dd></div>
                </dl>
            </div>
        </div>

        <!-- Nav -->
        <div class="flex justify-between">
            <Button v-if="step > 1" variant="secondary" @click="back">
                <template #icon-left><ChevronLeft class="h-4 w-4" /></template>
                Kembali
            </Button>
            <div v-else></div>
            <Button v-if="step < 5" @click="next" :disabled="!canNext()">
                Lanjut
                <template #icon-right><ChevronRight class="h-4 w-4" /></template>
            </Button>
            <Button v-else @click="submit" :loading="form.processing" variant="success">
                Simpan Booking
            </Button>
        </div>
    </div>
</template>
