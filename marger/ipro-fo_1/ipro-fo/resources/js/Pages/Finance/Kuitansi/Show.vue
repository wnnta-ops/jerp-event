<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const props = defineProps<{ kuitansi: any }>();
const k = props.kuitansi;

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function printNow() {
    window.print();
}
</script>

<template>
    <div class="max-w-3xl mx-auto space-y-4">
        <Link :href="route('kuitansi.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>

        <div class="card p-10 bg-white dark:bg-neutral-900">
            <div class="text-center mb-6">
                <h1 class="text-3xl font-bold">KUITANSI</h1>
                <p class="text-sm text-neutral-500 mt-1 font-mono">{{ k.kuitansi_number }}</p>
            </div>

            <div class="border-2 border-neutral-300 dark:border-neutral-600 rounded-lg p-6 space-y-4">
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div class="font-semibold">Nomor</div>
                    <div class="col-span-2 font-mono">{{ k.kuitansi_number }}</div>

                    <div class="font-semibold">Tanggal</div>
                    <div class="col-span-2">{{ k.issued_date }}</div>

                    <div class="font-semibold">Sebesar</div>
                    <div class="col-span-2 font-bold text-2xl text-primary-600">{{ fmtRp(k.amount) }}</div>

                    <div class="font-semibold">Terbilang</div>
                    <div class="col-span-2 italic">{{ k.terbilang }}</div>

                    <div class="font-semibold">Untuk Pembayaran</div>
                    <div class="col-span-2">
                        <ul class="space-y-1">
                            <li v-for="i in k.items" :key="i.id">{{ i.description }} — {{ fmtRp(i.amount) }}</li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 text-right">
                    <p class="text-sm">Hormat kami,</p>
                    <p class="font-semibold mt-12">{{ k.signed_by }}</p>
                </div>
            </div>

            <div class="mt-6 text-center">
                <button type="button" @click="printNow" class="text-sm text-primary-600 hover:underline">🖨 Cetak Kuitansi</button>
            </div>
        </div>
    </div>
</template>
