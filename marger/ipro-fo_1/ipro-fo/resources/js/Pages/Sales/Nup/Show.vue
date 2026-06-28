<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

const props = defineProps<{ registration: any; pay_link: string }>();
const r = props.registration;

const payForm    = useForm({ amount: 0, payment_method: 'transfer', paid_at: new Date().toISOString().slice(0, 10), reference_no: '' });
const refundForm = useForm({ reason: '' });
const emailForm  = useForm({});
const showPay = ref(false), showRefund = ref(false);

function sendEmail() { emailForm.post(route('nup.sendEmail', r.id), { preserveScroll: true }); }
function waLink() {
    let phone = String(r.customer?.phone ?? '').replace(/\D/g, '');
    if (phone.startsWith('0')) phone = '62' + phone.slice(1);
    else if (!phone.startsWith('62')) phone = '62' + phone;
    const amt = Number(r.scheme?.nup_amount ?? 0).toLocaleString('id-ID');
    const msg = `Halo ${r.customer?.full_name ?? ''}, berikut link pembayaran NUP ${r.nup_number} sebesar Rp ${amt}: ${props.pay_link}`;
    return `https://wa.me/${phone}?text=${encodeURIComponent(msg)}`;
}

const totalPaid = computed(() => (r.payments ?? []).reduce((s: number, p: any) => s + Number(p.amount), 0));
const outstanding = computed(() => Number(r.scheme?.nup_amount ?? 0) - totalPaid.value);

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }

function submitPay() {
    payForm.post(route('nup.pay', r.id), { onSuccess: () => { showPay.value = false; payForm.reset(); } });
}
function submitRefund() {
    refundForm.post(route('nup.refund', r.id), { onSuccess: () => { showRefund.value = false; } });
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-4">
        <Link :href="route('nup.index')" class="text-sm text-neutral-500">← Kembali</Link>

        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ r.nup_number }}</h1>
                <p class="text-sm text-neutral-500">Antrian #{{ r.queue_no }} · {{ r.registered_at }}</p>
            </div>
            <Badge :variant="r.status === 'paid' ? 'success' : 'info'" dot>{{ r.status }}</Badge>
        </div>

        <!-- Banner pembayaran online (Xendit / simulasi) -->
        <div v-if="r.payment_status !== 'paid' && r.status !== 'refunded'"
             class="card p-4 flex flex-wrap items-center justify-between gap-3 border-l-4 border-primary-500">
            <div>
                <p class="text-sm font-medium">Pembayaran NUP belum lunas</p>
                <p class="text-xs text-neutral-500">
                    Status: <span class="font-semibold capitalize">{{ r.payment_status ?? 'unpaid' }}</span>
                    <span v-if="r.payment_provider"> · via {{ r.payment_provider === 'xendit' ? 'Xendit' : 'Simulasi' }}</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('nup.bayar', r.id)">
                    <Button>💳 Bayar Sekarang</Button>
                </Link>
                <a v-if="r.customer?.phone" :href="waLink()" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-[#25D366] text-white px-4 h-10 text-sm font-medium hover:opacity-90 transition">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.8 14.03c-.24.68-1.42 1.3-1.95 1.35-.5.05-1.13.07-1.82-.11-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.79-4.17-4.94-4.36-.14-.19-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.27-.29.58-.36.78-.36l.56.01c.18.01.42-.07.66.5.24.59.83 2.03.9 2.18.07.14.12.31.02.5-.09.19-.14.31-.28.48l-.42.49c-.14.14-.28.29-.12.57.16.28.71 1.17 1.52 1.9 1.05.93 1.93 1.22 2.21 1.36.28.14.44.12.6-.07.16-.19.69-.81.88-1.09.18-.28.37-.23.62-.14.25.09 1.61.76 1.89.9.28.14.46.21.53.32.07.12.07.66-.17 1.34z"/></svg>
                    WhatsApp
                </a>
                <Button v-if="r.customer?.email" variant="secondary" :loading="emailForm.processing" @click="sendEmail">✉ Email</Button>
            </div>
        </div>
        <div v-else-if="r.payment_status === 'paid'" class="card p-4 border-l-4 border-emerald-500">
            <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">✓ Pembayaran NUP lunas</p>
        </div>

        <div class="flex gap-2">
            <Button v-if="['active','paid'].includes(r.status) && outstanding > 0" @click="showPay = true">+ Pembayaran Manual</Button>
            <Button v-if="['active','paid'].includes(r.status)" variant="danger" @click="showRefund = true">Refund</Button>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div class="card p-6">
                <h2 class="font-semibold mb-3">Informasi NUP</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-neutral-500">Customer:</dt><dd>{{ r.customer?.full_name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Skema:</dt><dd>{{ r.scheme?.name }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Nominal NUP:</dt><dd class="font-bold">{{ fmtRp(r.scheme?.nup_amount) }}</dd></div>
                </dl>
            </div>
            <div class="card p-6">
                <h2 class="font-semibold mb-3">Status Pembayaran</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between"><dt class="text-neutral-500">Total Terbayar:</dt><dd class="font-mono text-success-600">{{ fmtRp(totalPaid) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Outstanding:</dt><dd class="font-mono font-bold">{{ fmtRp(outstanding) }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="card p-6" v-if="r.payments?.length">
            <h2 class="font-semibold mb-3">Riwayat Pembayaran</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr><th class="py-2">Tanggal</th><th class="py-2">Method</th><th class="py-2">No Ref</th><th class="py-2 text-right">Nominal</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="p in r.payments" :key="p.id">
                        <td class="py-2">{{ p.paid_at }}</td>
                        <td class="py-2 capitalize">{{ p.payment_method }}</td>
                        <td class="py-2 font-mono text-xs">{{ p.reference_no ?? '-' }}</td>
                        <td class="py-2 text-right font-mono">{{ fmtRp(p.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pay Modal -->
        <div v-if="showPay" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showPay = false">
            <div class="card p-6 max-w-md w-full">
                <h3 class="font-semibold mb-3">Catat Pembayaran NUP</h3>
                <form @submit.prevent="submitPay" class="space-y-3">
                    <Input v-model.number="payForm.amount" type="number" label="Nominal" required />
                    <div>
                        <label class="label-base">Metode</label>
                        <select v-model="payForm.payment_method" class="input-base">
                            <option value="cash">Cash</option><option value="transfer">Transfer</option><option value="va">VA</option>
                        </select>
                    </div>
                    <Input v-model="payForm.paid_at" type="date" label="Tanggal Bayar" required />
                    <Input v-model="payForm.reference_no" label="No Referensi" />
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="showPay = false">Batal</Button>
                        <Button type="submit" :loading="payForm.processing">Simpan</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Refund Modal -->
        <div v-if="showRefund" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showRefund = false">
            <div class="card p-6 max-w-md w-full">
                <h3 class="font-semibold mb-3">Refund NUP</h3>
                <p class="text-sm text-neutral-500 mb-3">Penalti {{ Number(r.scheme?.refund_penalty_pct ?? 0) }}% akan dipotong.</p>
                <form @submit.prevent="submitRefund" class="space-y-3">
                    <textarea v-model="refundForm.reason" class="input-base h-20" placeholder="Alasan refund..." required></textarea>
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="showRefund = false">Batal</Button>
                        <Button variant="danger" type="submit" :loading="refundForm.processing">Konfirmasi Refund</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
