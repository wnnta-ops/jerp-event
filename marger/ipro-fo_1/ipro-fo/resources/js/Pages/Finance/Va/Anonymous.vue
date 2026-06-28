<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import axios from 'axios';
import { debounce } from 'lodash-es';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Input from '@/Components/UI/Input.vue';

defineProps<{ rows: any; filters: Record<string, any> }>();

const targetPayment = ref<any>(null);
const allocForm = useForm({ customer_id: null as number | null, booking_id: null as number | null });
const custSearch  = ref('');
const custResults = ref<any[]>([]);

const search = debounce(async () => {
    if (custSearch.value.length < 2) return;
    const r = await axios.get('/api/v1/search', { params: { q: custSearch.value }, withCredentials: true });
    custResults.value = (r.data.data ?? []).filter((d: any) => d.type === 'customer');
}, 250);
watch(custSearch, search);

function pick(c: any) { allocForm.customer_id = c.id; custResults.value = []; custSearch.value = c.label; }

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }

function submit() {
    if (! targetPayment.value) return;
    allocForm.post(route('va.anonymous.allocate', targetPayment.value.id), {
        onSuccess: () => { targetPayment.value = null; allocForm.reset(); custSearch.value = ''; },
    });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('va.index')" class="text-sm text-neutral-500">← Kembali</Link>
        <h1 class="text-2xl font-bold">Pembayaran Anonim</h1>
        <p class="text-sm text-neutral-500">Dana masuk tanpa identifikasi yang jelas. Identifikasi → otomatis buat TTS + alokasi.</p>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Waktu</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Ref</th>
                        <th class="px-4 py-3">Deskripsi</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="p in rows.data" :key="p.id">
                        <td class="px-4 py-3">{{ new Date(p.received_at).toLocaleString('id-ID') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(p.amount) }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ p.reference_no }}</td>
                        <td class="px-4 py-3 max-w-xs truncate">{{ p.description }}</td>
                        <td class="px-4 py-3"><Badge :variant="p.status === 'allocated' ? 'success' : p.status === 'identified' ? 'info' : 'warning'" dot>{{ p.status }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <button v-if="p.status === 'unidentified'" @click="targetPayment = p" class="text-primary-600 text-sm">Identifikasi</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Tidak ada pembayaran anonim" />
        </div>

        <!-- Allocate Modal -->
        <div v-if="targetPayment" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="targetPayment = null">
            <div class="card p-6 max-w-md w-full">
                <h3 class="font-semibold mb-2">Identifikasi Pembayaran</h3>
                <p class="text-sm mb-3">Nominal: <strong class="font-mono">{{ fmtRp(targetPayment.amount) }}</strong> · Ref: {{ targetPayment.reference_no }}</p>
                <form @submit.prevent="submit" class="space-y-3">
                    <Input v-model="custSearch" label="Cari Customer" required />
                    <div v-if="custResults.length" class="space-y-1 max-h-32 overflow-y-auto">
                        <button v-for="c in custResults" :key="c.id" type="button" @click="pick(c)" class="w-full text-left p-2 rounded border hover:bg-neutral-50 dark:hover:bg-neutral-800 text-sm">{{ c.label }}</button>
                    </div>
                    <Input v-model.number="allocForm.booking_id" type="number" label="Booking ID (opsional)" />
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="targetPayment = null">Batal</Button>
                        <Button type="submit" :loading="allocForm.processing">Identifikasi & Alokasi</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
