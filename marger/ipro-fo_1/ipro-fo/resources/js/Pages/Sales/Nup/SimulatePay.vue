<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ registration: any }>();
const r = props.registration;

const form = useForm({});
function rupiah(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0)); }
function pay() { form.post(route('nup.simulatePay', r.id)); }
</script>

<template>
    <div class="max-w-md mx-auto space-y-4">
        <Link :href="route('nup.show', r.id)" class="text-sm text-neutral-500">← Kembali</Link>

        <div class="card p-6 text-center">
            <div class="inline-flex items-center gap-2 text-xs font-semibold px-3 py-1 rounded-full bg-amber-100 text-amber-700 mb-4">
                ⚙ MODE SIMULASI (Xendit belum dikonfigurasi)
            </div>
            <h1 class="text-lg font-bold">Pembayaran NUP</h1>
            <p class="text-sm text-neutral-500 font-mono mt-1">{{ r.nup_number }}</p>

            <div class="my-6">
                <p class="text-sm text-neutral-500">Total tagihan</p>
                <p class="text-3xl font-extrabold text-primary-700 dark:text-primary-300">{{ rupiah(r.scheme?.nup_amount) }}</p>
            </div>

            <dl class="text-sm text-left space-y-1 mb-6 border-t border-b border-neutral-100 dark:border-neutral-800 py-4">
                <div class="flex justify-between"><dt class="text-neutral-500">Pemesan</dt><dd>{{ r.customer?.full_name }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">Skema</dt><dd>{{ r.scheme?.name }}</dd></div>
                <div class="flex justify-between"><dt class="text-neutral-500">Antrian</dt><dd>#{{ r.queue_no }}</dd></div>
            </dl>

            <Button block size="lg" :loading="form.processing" @click="pay">💳 Bayar Sekarang (Simulasi)</Button>
            <p class="text-xs text-neutral-400 mt-3">
                Saat XENDIT_SECRET_KEY diisi di .env, tombol ini diganti redirect ke halaman pembayaran Xendit asli (VA, e-wallet, QRIS, kartu).
            </p>
        </div>
    </div>
</template>
