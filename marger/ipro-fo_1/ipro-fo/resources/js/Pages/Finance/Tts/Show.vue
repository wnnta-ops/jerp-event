<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ tts: { data: any } }>();
const t = props.tts.data;

const showReject = ref(false);
const rejectForm = useForm({ reason: '' });

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function postTts() {
    if (confirm('Posting TTS ini? Setelah posted tidak bisa diubah.')) {
        router.post(route('tts.post', t.id));
    }
}

function clearGiro() {
    if (confirm('Konfirmasi giro berhasil cair?')) {
        router.post(route('tts.clear-giro', t.id));
    }
}

function rejectGiro() {
    rejectForm.post(route('tts.reject-giro', t.id), {
        onSuccess: () => { showReject.value = false; rejectForm.reset(); },
    });
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-4">
        <Link :href="route('tts.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>

        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ t.tts_number }}</h1>
                <p class="text-sm text-neutral-500">{{ t.received_date }}</p>
            </div>
            <Badge :variant="t.status === 'posted' ? 'success' : t.status === 'pending' ? 'warning' : 'danger'" dot>{{ t.status }}</Badge>
        </div>

        <div class="flex gap-2">
            <Button v-if="t.status === 'verified'" @click="postTts">Posting TTS</Button>
            <Button v-if="t.giro && t.giro.status === 'open'" variant="success" @click="clearGiro">Giro Cair</Button>
            <Button v-if="t.giro && t.giro.status === 'open'" variant="danger" @click="showReject = true">Giro Ditolak</Button>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">Info Pembayaran</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-neutral-500">Customer</dt><dd class="font-medium">{{ t.customer?.full_name }}</dd></div>
                <div><dt class="text-neutral-500">Booking</dt><dd class="font-mono">{{ t.booking?.booking_number ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Nominal</dt><dd class="font-bold text-lg">{{ fmtRp(t.amount) }}</dd></div>
                <div><dt class="text-neutral-500">Cara Bayar</dt><dd class="capitalize">{{ t.payment_method }}</dd></div>
                <div><dt class="text-neutral-500">No. Referensi</dt><dd class="font-mono">{{ t.reference_no || '-' }}</dd></div>
                <div><dt class="text-neutral-500">Catatan</dt><dd>{{ t.notes || '-' }}</dd></div>
            </dl>
        </div>

        <div v-if="t.giro" class="card p-6">
            <h2 class="font-semibold mb-3">Detail Bilyet Giro</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-neutral-500">No Giro</dt><dd class="font-mono">{{ t.giro.giro_number }}</dd></div>
                <div><dt class="text-neutral-500">Bank</dt><dd>{{ t.giro.issuing_bank }}</dd></div>
                <div><dt class="text-neutral-500">Jatuh Tempo</dt><dd>{{ t.giro.due_date }}</dd></div>
                <div><dt class="text-neutral-500">Status Clearing</dt>
                    <dd><Badge :variant="t.giro.status === 'cleared' ? 'success' : t.giro.status === 'rejected' ? 'danger' : 'warning'">{{ t.giro.status }}</Badge></dd>
                </div>
            </dl>
        </div>

        <div v-if="t.allocations?.length" class="card p-6">
            <h2 class="font-semibold mb-3">Alokasi ke Jadwal Tagihan</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr>
                        <th class="px-2 py-2">Schedule ID</th>
                        <th class="px-2 py-2 text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in t.allocations" :key="a.payment_schedule_id" class="border-t">
                        <td class="px-2 py-2 font-mono">#{{ a.payment_schedule_id }}</td>
                        <td class="px-2 py-2 text-right font-mono">{{ fmtRp(a.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Reject Giro Modal -->
        <div v-if="showReject" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showReject = false">
            <div class="card p-6 max-w-md w-full">
                <h2 class="font-semibold mb-3">Tolak Giro</h2>
                <p class="text-sm text-neutral-500 mb-3">Reverse alokasi yang sudah dilakukan dan mark giro sebagai ditolak.</p>
                <textarea v-model="rejectForm.reason" class="input-base h-20" placeholder="Alasan penolakan (mis. cek kosong, tanda tangan tidak match)..." />
                <p v-if="rejectForm.errors.reason" class="text-xs text-danger-500 mt-1">{{ rejectForm.errors.reason }}</p>
                <div class="flex gap-2 justify-end mt-4">
                    <Button variant="secondary" @click="showReject = false">Batal</Button>
                    <Button variant="danger" :loading="rejectForm.processing" @click="rejectGiro">Konfirmasi Tolak</Button>
                </div>
            </div>
        </div>
    </div>
</template>
