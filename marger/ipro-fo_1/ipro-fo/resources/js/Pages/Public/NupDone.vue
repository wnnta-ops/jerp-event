<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/UI/Button.vue';

defineOptions({ layout: PublicLayout });

const props = defineProps<{ result: any | null }>();

function rupiah(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0)); }

const notifiedChannels = computed<string[]>(() => {
    const n = props.result?.notified ?? {};
    const out: string[] = [];
    if (n.email && n.email !== 'failed') out.push('email');
    if (n.whatsapp && !['failed', 'skipped'].includes(n.whatsapp)) out.push('WhatsApp');
    return out;
});
</script>

<template>
    <div class="max-w-lg mx-auto">
        <div class="card p-8 text-center">
            <div class="mx-auto w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-3xl mb-4">✓</div>
            <h1 class="text-xl font-bold">Pendaftaran NUP Berhasil!</h1>

            <template v-if="result">
                <p class="text-sm text-neutral-500 mt-1">Terima kasih{{ result.name ? `, ${result.name}` : '' }}. Berikut detail pendaftaran Anda:</p>

                <dl class="text-sm text-left my-6 border-y border-neutral-100 dark:border-neutral-800 py-4 space-y-2">
                    <div class="flex justify-between"><dt class="text-neutral-500">No. NUP</dt><dd class="font-mono font-bold">{{ result.number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">No. Antrian</dt><dd>#{{ result.queue_no }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Total Tagihan</dt><dd class="font-bold text-primary-700">{{ rupiah(result.amount) }}</dd></div>
                </dl>

                <a v-if="result.pay_url" :href="result.pay_url">
                    <Button block size="lg">💳 Lanjut Bayar Sekarang</Button>
                </a>
                <div v-else class="rounded-lg bg-amber-50 text-amber-700 text-sm px-4 py-3">
                    Pendaftaran tercatat. Tim kami akan menghubungi Anda untuk proses pembayaran NUP.
                </div>

                <div v-if="notifiedChannels.length" class="mt-4 rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3">
                    📨 Instruksi pembayaran telah dikirim ke {{ notifiedChannels.join(' & ') }} Anda.
                </div>
            </template>

            <p v-else class="text-sm text-neutral-500 mt-2">
                Terima kasih, pendaftaran/pembayaran Anda telah kami terima.
            </p>

            <Link :href="route('nup.create')" class="inline-block mt-6 text-sm text-primary-600 hover:underline">
                ← Daftar NUP lagi
            </Link>
        </div>
    </div>
</template>
