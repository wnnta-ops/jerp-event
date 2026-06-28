<script setup lang="ts">
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ name_changes: Array<any>; unit_changes: Array<any>; reschedules: Array<any> }>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v); }
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold">Exception Requests</h1>
            <p class="text-sm text-neutral-500">Ganti Nama (GN), Ganti Unit (GU), Reschedule Pembayaran</p>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">🔄 Ganti Nama (GN)</h2>
            <table v-if="name_changes.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr><th class="py-2">Booking ID</th><th class="py-2">Old → New Customer</th><th class="py-2 text-right">Admin Fee</th><th class="py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in name_changes" :key="r.id">
                        <td class="py-2">#{{ r.booking_id }}</td>
                        <td class="py-2">{{ r.old_customer_id }} → {{ r.new_customer_id }}</td>
                        <td class="py-2 text-right font-mono">{{ fmtRp(r.admin_fee) }}</td>
                        <td class="py-2"><Badge>{{ r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Belum ada request GN" />
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">🏠 Ganti Unit (GU)</h2>
            <table v-if="unit_changes.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr><th class="py-2">Booking ID</th><th class="py-2">Old → New Unit</th><th class="py-2 text-right">Selisih</th><th class="py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in unit_changes" :key="r.id">
                        <td class="py-2">#{{ r.booking_id }}</td>
                        <td class="py-2">#{{ r.old_unit_id }} → #{{ r.new_unit_id }}</td>
                        <td class="py-2 text-right font-mono">{{ fmtRp(r.price_diff) }}</td>
                        <td class="py-2"><Badge>{{ r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Belum ada request GU" />
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-3">📅 Reschedule</h2>
            <table v-if="reschedules.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr><th class="py-2">Booking ID</th><th class="py-2 text-right">Admin Fee</th><th class="py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in reschedules" :key="r.id">
                        <td class="py-2">#{{ r.booking_id }}</td>
                        <td class="py-2 text-right font-mono">{{ fmtRp(r.admin_fee) }}</td>
                        <td class="py-2"><Badge>{{ r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Belum ada request reschedule" />
        </div>
    </div>
</template>
