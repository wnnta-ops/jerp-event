<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Plus, ShieldCheck } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{
    registrations: any;
    schemes: Array<any>;
    filters: Record<string, any>;
    pending_count?: number;
}>();

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0)); }
function statusColor(s: string) {
    return ({ active: 'info', paid: 'success', converted: 'primary', refunded: 'neutral', cancelled: 'danger' } as any)[s] ?? 'neutral';
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">NUP (Nomor Urut Pemesanan)</h1>
            <div class="flex items-center gap-2">
                <Link :href="route('nup.verifikasi')">
                    <Button variant="secondary">
                        <template #icon-left><ShieldCheck class="h-4 w-4" /></template>
                        Verifikasi<span v-if="pending_count"> ({{ pending_count }})</span>
                    </Button>
                </Link>
                <Link :href="route('nup.create')">
                    <Button><template #icon-left><Plus class="h-4 w-4" /></template>Daftar NUP</Button>
                </Link>
            </div>
        </div>

        <Link
            v-if="pending_count"
            :href="route('nup.verifikasi')"
            class="flex items-center gap-2 rounded-lg border border-warning-500/40 bg-warning-500/10 px-4 py-2.5 text-sm text-warning-700 hover:bg-warning-500/20 dark:text-warning-400"
        >
            <ShieldCheck class="h-4 w-4 shrink-0" />
            <span><strong>{{ pending_count }}</strong> pendaftaran NUP menunggu verifikasi.</span>
            <span class="ml-auto font-semibold">Verifikasi sekarang →</span>
        </Link>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3 text-right">Queue #</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Skema</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in registrations.data" :key="r.id">
                        <td class="px-4 py-3 font-mono text-xs">{{ r.nup_number }}</td>
                        <td class="px-4 py-3 text-right font-bold">#{{ r.queue_no }}</td>
                        <td class="px-4 py-3">{{ r.customer?.full_name }}</td>
                        <td class="px-4 py-3">{{ r.scheme?.name }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(r.scheme?.nup_amount) }}</td>
                        <td class="px-4 py-3"><Badge :variant="statusColor(r.status)" dot>{{ r.status }}</Badge></td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('nup.show', r.id)" class="text-primary-600 text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!registrations.data?.length" title="Belum ada registrasi NUP" />
        </div>
    </div>
</template>
