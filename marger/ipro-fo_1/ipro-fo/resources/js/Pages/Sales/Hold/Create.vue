<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { debounce } from 'lodash-es';
import axios from 'axios';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{ units: Array<any>; max_days: number }>();

const mode = ref<'existing' | 'new'>('existing');
const form = useForm<any>({
    unit_id:          null as number | null,
    customer_id:      null as number | null,
    duration_minutes: (7 * 24 * 60),
    new_customer:     { full_name: '', phone: '', id_number: '' },
});

const customerSearch  = ref('');
const customerResults = ref<any[]>([]);
const selectedCust    = ref<any>(null);

const search = debounce(async () => {
    if (customerSearch.value.length < 2) { customerResults.value = []; return; }
    const r = await axios.get(route('customers.search'), { params: { q: customerSearch.value }, withCredentials: true });
    customerResults.value = (r.data.data ?? []).filter((d: any) => d.type === 'customer');
}, 250);
watch(customerSearch, search);

function pickCustomer(c: any) {
    selectedCust.value = c;
    form.customer_id = c.id;
    customerResults.value = [];
}

const canSubmit = computed(() =>
    !!form.unit_id && (mode.value === 'existing' ? !!form.customer_id : !!form.new_customer.full_name.trim()),
);

function submit() {
    form.transform((d: any) => mode.value === 'new'
        ? { ...d, customer_id: null }
        : { ...d, new_customer: { full_name: '', phone: '', id_number: '' } })
        .post(route('holds.store'));
}

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }
</script>

<template>
    <div class="max-w-xl mx-auto space-y-4">
        <Link :href="route('holds.index')" class="text-sm text-neutral-500">← Kembali</Link>
        <h1 class="text-2xl font-bold">Hold Unit Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div>
                <label class="label-base">Unit <span class="text-danger-500">*</span></label>
                <select v-model="form.unit_id" class="input-base" required>
                    <option :value="null">Pilih unit...</option>
                    <option v-for="u in units" :key="u.id" :value="u.id">{{ u.unit_code }} — {{ fmtRp(u.current_price) }}</option>
                </select>
            </div>

            <!-- Customer: pilih terdaftar atau input baru langsung -->
            <div>
                <div class="mb-2 inline-flex rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700">
                    <button type="button" @click="mode = 'existing'" :class="['rounded-md px-3 py-1', mode === 'existing' ? 'bg-primary-600 text-white' : 'text-neutral-500']">Customer Terdaftar</button>
                    <button type="button" @click="mode = 'new'" :class="['rounded-md px-3 py-1', mode === 'new' ? 'bg-primary-600 text-white' : 'text-neutral-500']">Customer Baru</button>
                </div>

                <div v-if="mode === 'existing'">
                    <Input v-model="customerSearch" label="Cari Customer" placeholder="Nama / kode / HP / KTP..." />
                    <div v-if="customerResults.length" class="mt-2 space-y-1">
                        <button v-for="c in customerResults" :key="c.id" type="button" @click="pickCustomer(c)"
                            class="w-full text-left p-2 rounded border hover:bg-neutral-50 dark:hover:bg-neutral-800 text-sm">{{ c.label }}</button>
                    </div>
                    <div v-if="selectedCust" class="mt-2 p-2 bg-success-500/10 rounded text-sm">✓ {{ selectedCust.label }}</div>
                </div>

                <div v-else class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><Input v-model="form.new_customer.full_name" label="Nama Lengkap *" placeholder="Nama customer" /></div>
                    <Input v-model="form.new_customer.phone" label="No. HP" placeholder="08…" />
                    <Input v-model="form.new_customer.id_number" label="No. KTP" placeholder="16 angka" />
                    <p class="col-span-2 text-xs text-neutral-400">Customer baru otomatis tersimpan ke modul Customer (kode dibuat sistem).</p>
                </div>
            </div>

            <div>
                <label class="label-base">Durasi (menit)</label>
                <select v-model.number="form.duration_minutes" class="input-base">
                    <option :value="60">1 jam</option>
                    <option :value="60 * 24">1 hari</option>
                    <option :value="60 * 24 * 3">3 hari</option>
                    <option :value="60 * 24 * 7">7 hari (default)</option>
                    <option v-if="max_days >= 14" :value="60 * 24 * 14">14 hari (max)</option>
                </select>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('holds.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing" :disabled="!canSubmit">Hold Unit</Button>
            </div>
        </form>
    </div>
</template>
