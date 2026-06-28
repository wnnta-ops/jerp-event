<script setup lang="ts">
import { ref, reactive } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowLeft, FileCog, Search, Save } from 'lucide-vue-next';

const props = defineProps<{
    slug: string;
    opType: string;
    title: string;
    customers: Array<{ id: number; code: string; full_name: string }>;
    units: Array<{ id: number; unit_code: string; current_price: number }>;
}>();

const number = ref('');
const finder = reactive<{ loading: boolean; error: string; summary: any | null }>({ loading: false, error: '', summary: null });

const form = useForm<Record<string, any>>({
    contract_number: '',
    base_price: 0, discount_amount: 0, reason: '',
    label: '', due_date: new Date().toISOString().substring(0, 10), amount: 0, payment_type: 'ADM',
    new_customer_id: null, new_unit_id: null, penalty: 0, refund: 0,
});

async function findContract() {
    if (!number.value) return;
    finder.loading = true; finder.error = ''; finder.summary = null;
    try {
        const { data } = await axios.get(route('sales.kontrak-ops.find'), { params: { number: number.value } });
        finder.summary = data.summary;
        form.contract_number = data.summary.contract_number;
        // Prefill adjustment fields with current values.
        form.base_price = data.summary.base_price;
        form.discount_amount = data.summary.discount_amount;
    } catch (e: any) {
        finder.error = e?.response?.data?.message ?? 'Kontrak tidak ditemukan.';
    } finally {
        finder.loading = false;
    }
}

function submit() {
    form.post(route('sales.kontrak-ops.apply', props.slug), { preserveScroll: true });
}

const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('sales.kontrak-ops.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Operasi Kontrak</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCog class="h-6 w-6 text-primary-600" /> {{ title }}</h1>

        <!-- Step 1: cari kontrak -->
        <div class="card p-4">
            <label :class="labelCls">No. Kontrak</label>
            <div class="flex gap-2">
                <input v-model="number" @keyup.enter="findContract" :class="inputCls + ' max-w-sm'" placeholder="Masukkan nomor kontrak / PPJB…" />
                <button type="button" @click="findContract" :disabled="finder.loading" class="inline-flex items-center gap-2 rounded-lg bg-neutral-800 px-4 py-2 text-sm font-semibold text-white hover:bg-neutral-900 disabled:opacity-50 dark:bg-neutral-700"><Search class="h-4 w-4" /> {{ finder.loading ? 'Mencari…' : 'Cari' }}</button>
            </div>
            <p v-if="finder.error" class="mt-2 text-xs text-danger-600">{{ finder.error }}</p>
        </div>

        <!-- Step 2: ringkasan + form operasi -->
        <template v-if="finder.summary">
            <div class="card p-4">
                <div class="mb-2 text-sm font-semibold">Ringkasan Kontrak {{ finder.summary.contract_number }}</div>
                <div class="grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    <div><div class="text-xs uppercase text-neutral-400">Customer</div><div>{{ finder.summary.customer?.name ?? '—' }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Unit</div><div>{{ finder.summary.unit?.code ?? '—' }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Harga Dasar</div><div>{{ rb(finder.summary.base_price) }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Diskon</div><div>{{ rb(finder.summary.discount_amount) }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">PPN ({{ finder.summary.ppn_pct }}%)</div><div>{{ rb(finder.summary.ppn_amount) }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Total Kontrak</div><div class="font-semibold">{{ rb(finder.summary.total_price) }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Terbayar</div><div class="text-success-600">{{ rb(finder.summary.total_terbayar) }}</div></div>
                    <div><div class="text-xs uppercase text-neutral-400">Sisa Tagihan</div><div class="text-danger-600">{{ rb(finder.summary.sisa_tagihan) }}</div></div>
                </div>
            </div>

            <form @submit.prevent="submit" class="card space-y-3 p-4">
                <!-- Adjustment -->
                <template v-if="opType === 'adjustment'">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :class="labelCls">Harga Dasar Baru</label><input type="number" v-model.number="form.base_price" :class="inputCls" /></div>
                        <div><label :class="labelCls">Diskon Baru</label><input type="number" v-model.number="form.discount_amount" :class="inputCls" /></div>
                    </div>
                    <div><label :class="labelCls">Alasan</label><input v-model="form.reason" :class="inputCls" placeholder="Alasan adjustment…" /></div>
                    <p class="text-xs text-neutral-400">PPN &amp; total dihitung ulang otomatis; jadwal disesuaikan agar tetap konsisten.</p>
                </template>

                <!-- Tagihan Custom -->
                <template v-else-if="opType === 'tagihan_custom'">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :class="labelCls">Keterangan Tagihan *</label><input v-model="form.label" :class="inputCls" placeholder="mis. Biaya BBN / Notaris" required /></div>
                        <div><label :class="labelCls">Jenis</label><select v-model="form.payment_type" :class="inputCls"><option value="ADM">Administrasi</option><option value="DP">DP</option><option value="ANG">Angsuran</option><option value="BF">Booking Fee</option></select></div>
                        <div><label :class="labelCls">Jatuh Tempo *</label><input type="date" v-model="form.due_date" :class="inputCls" required /></div>
                        <div><label :class="labelCls">Nilai *</label><input type="number" v-model.number="form.amount" :class="inputCls" required /></div>
                    </div>
                </template>

                <!-- Pengalihan Hak -->
                <template v-else-if="opType === 'ganti_nama'">
                    <div><label :class="labelCls">Pemilik Baru *</label>
                        <select v-model="form.new_customer_id" :class="inputCls" required>
                            <option :value="null" disabled>— Pilih customer —</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} — {{ c.full_name }}</option>
                        </select>
                    </div>
                    <div><label :class="labelCls">Alasan / Dasar Pengalihan</label><input v-model="form.reason" :class="inputCls" placeholder="mis. jual-beli, hibah, waris…" /></div>
                </template>

                <!-- Pindah Unit -->
                <template v-else-if="opType === 'pindah_unit'">
                    <div><label :class="labelCls">Unit Baru (tersedia) *</label>
                        <select v-model="form.new_unit_id" :class="inputCls" required>
                            <option :value="null" disabled>— Pilih unit —</option>
                            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.unit_code }} — {{ rb(u.current_price) }}</option>
                        </select>
                    </div>
                    <div><label :class="labelCls">Alasan</label><input v-model="form.reason" :class="inputCls" placeholder="Alasan pindah unit…" /></div>
                    <p class="text-xs text-neutral-400">Harga dasar mengikuti unit baru; PPN, total &amp; jadwal dihitung ulang. Unit lama dibebaskan.</p>
                </template>

                <!-- Pembatalan -->
                <template v-else-if="opType === 'batal'">
                    <div><label :class="labelCls">Alasan Pembatalan *</label><input v-model="form.reason" :class="inputCls" placeholder="Alasan pembatalan…" required /></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label :class="labelCls">Penalti (Rp)</label><input type="number" v-model.number="form.penalty" :class="inputCls" /></div>
                        <div><label :class="labelCls">Refund ke Customer (Rp)</label><input type="number" v-model.number="form.refund" :class="inputCls" /></div>
                    </div>
                    <p class="text-xs text-danger-500">Kontrak &amp; booking akan ditandai <b>cancelled</b>, unit dibebaskan kembali.</p>
                </template>

                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :class="opType === 'batal' ? 'bg-danger-600 hover:bg-danger-700' : 'bg-primary-600 hover:bg-primary-700'">
                    <Save class="h-4 w-4" /> Terapkan {{ title }}
                </button>
            </form>
        </template>
    </div>
</template>
