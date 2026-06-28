<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Input from '@/Components/UI/Input.vue';

defineProps<{ rows: any }>();

const showRequest = ref(false);
const form = useForm({ booking_id: null as number | null, amount: 0, reason: '' });

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }

function approve(id: number) {
    if (confirm('Approve cashback?')) router.post(route('cashbacks.approve', id));
}
function pay(id: number) {
    if (confirm('Bayar cashback? Memo pengeluaran akan dibuat.')) router.post(route('cashbacks.pay', id));
}

function submit() {
    form.post(route('cashbacks.store'), { onSuccess: () => { showRequest.value = false; form.reset(); } });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Cashback</h1>
            <Button @click="showRequest = true"><template #icon-left><Plus class="h-4 w-4" /></template>Permintaan Baru</Button>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Booking</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Alasan</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="c in rows.data" :key="c.id">
                        <td class="px-4 py-3 font-mono text-xs">{{ c.booking?.booking_number }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(c.amount) }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">{{ c.reason }}</td>
                        <td class="px-4 py-3"><Badge :variant="c.status === 'paid' ? 'success' : c.status === 'approved' ? 'info' : 'warning'" dot>{{ c.status }}</Badge></td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button v-if="c.status === 'pending'" @click="approve(c.id)" class="text-success-600 text-sm">Approve</button>
                            <button v-if="c.status === 'approved'" @click="pay(c.id)" class="text-primary-600 text-sm">Pay</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada cashback" />
        </div>

        <!-- Request Modal -->
        <div v-if="showRequest" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showRequest = false">
            <div class="card p-6 max-w-md w-full">
                <h3 class="font-semibold mb-3">Permintaan Cashback</h3>
                <form @submit.prevent="submit" class="space-y-3">
                    <Input v-model.number="form.booking_id" type="number" label="Booking ID" required />
                    <Input v-model.number="form.amount" type="number" label="Nominal (Rp)" required />
                    <textarea v-model="form.reason" class="input-base h-20" placeholder="Alasan cashback..." required></textarea>
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="showRequest = false">Batal</Button>
                        <Button type="submit" :loading="form.processing">Submit</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
