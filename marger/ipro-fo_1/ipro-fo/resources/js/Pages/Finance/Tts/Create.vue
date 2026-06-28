<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{
    methods: Array<{ value: string; label: string }>;
    bank_accounts: Array<any>;
}>();

const form = useForm({
    customer_id:     null as number | null,
    booking_id:      null as number | null,
    amount:          0,
    payment_method:  'cash',
    received_date:   new Date().toISOString().slice(0, 10),
    bank_account_id: null as number | null,
    reference_no:    '',
    notes:           '',
    giro_number:     '',
    giro_bank:       '',
    giro_date:       '',
    giro_due_date:   '',
});

const isGiro = computed(() => form.payment_method === 'giro');

// Customer search
const customerSearch = ref('');
const customerResults = ref<any[]>([]);
const selectedCustomer = ref<any>(null);
const customerBookings = ref<any[]>([]);

const searchCustomers = debounce(async () => {
    if (customerSearch.value.length < 2) { customerResults.value = []; return; }
    const r = await axios.get('/api/v1/search', { params: { q: customerSearch.value }, withCredentials: true });
    customerResults.value = (r.data.data ?? []).filter((d: any) => d.type === 'customer');
}, 250);
watch(customerSearch, searchCustomers);

async function pickCustomer(c: any) {
    selectedCustomer.value = c;
    form.customer_id = c.id;
    customerResults.value = [];
    // fetch open bookings for this customer
    try {
        const r = await axios.get('/api/v1/customers/' + c.id, { withCredentials: true });
        customerBookings.value = r.data.data?.bookings ?? [];
    } catch {
        customerBookings.value = [];
    }
}

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function submit() { form.post(route('tts.store')); }
</script>

<template>
    <div class="max-w-2xl mx-auto space-y-4">
        <Link :href="route('tts.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">TTS Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div>
                <Input v-model="customerSearch" label="Cari Customer" placeholder="Nama atau kode..." required />
                <div v-if="customerResults.length" class="mt-2 space-y-1">
                    <button v-for="c in customerResults" :key="c.id" type="button" @click="pickCustomer(c)"
                        class="w-full text-left p-2 rounded border border-neutral-200 dark:border-neutral-700 hover:bg-neutral-50 dark:hover:bg-neutral-800 text-sm">
                        {{ c.label }}
                    </button>
                </div>
                <div v-if="selectedCustomer" class="mt-2 p-2 bg-success-500/10 rounded text-sm">
                    ✓ {{ selectedCustomer.label }}
                </div>
            </div>

            <div v-if="customerBookings.length">
                <label class="label-base">Booking (opsional)</label>
                <select v-model="form.booking_id" class="input-base">
                    <option :value="null">— Tanpa booking spesifik —</option>
                    <option v-for="b in customerBookings" :key="b.id" :value="b.id">{{ b.booking_number }}</option>
                </select>
            </div>

            <Input v-model.number="form.amount" type="number" label="Nominal (Rp)" required :error="form.errors.amount" />

            <div>
                <label class="label-base">Cara Bayar <span class="text-danger-500">*</span></label>
                <select v-model="form.payment_method" class="input-base" required>
                    <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                </select>
            </div>

            <Input v-model="form.received_date" type="date" label="Tanggal Diterima" required />

            <div>
                <label class="label-base">Rekening Tujuan</label>
                <select v-model="form.bank_account_id" class="input-base">
                    <option :value="null">-</option>
                    <option v-for="b in bank_accounts" :key="b.id" :value="b.id">{{ b.bank_name }} · {{ b.account_number }} ({{ b.account_name }})</option>
                </select>
            </div>

            <Input v-model="form.reference_no" label="No. Referensi" placeholder="TRX-..." />

            <!-- Giro section -->
            <div v-if="isGiro" class="border-t pt-4 space-y-3">
                <h3 class="font-semibold text-sm">Detail Bilyet Giro</h3>
                <Input v-model="form.giro_number" label="Nomor Giro" required />
                <Input v-model="form.giro_bank" label="Bank Penerbit" required />
                <div class="grid grid-cols-2 gap-3">
                    <Input v-model="form.giro_date" type="date" label="Tanggal Giro" />
                    <Input v-model="form.giro_due_date" type="date" label="Jatuh Tempo" required />
                </div>
            </div>

            <div>
                <label class="label-base">Catatan</label>
                <textarea v-model="form.notes" class="input-base h-20" />
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('tts.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">{{ isGiro ? 'Simpan TTS (Pending Giro)' : 'Simpan & Auto-Alokasi' }}</Button>
            </div>
        </form>
    </div>
</template>
