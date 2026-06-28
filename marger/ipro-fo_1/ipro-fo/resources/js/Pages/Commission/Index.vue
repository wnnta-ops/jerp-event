<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ commissions: any; filters?: { status?: string }; lineStatuses?: Record<string, string> }>();

const status = ref(props.filters?.status ?? '');
function applyFilter() { router.get(route('commissions.index'), { status: status.value || undefined }, { preserveState: true, replace: true }); }

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v ?? 0)); }

function requestPayout(lineId: number) {
    if (confirm('Buat permintaan pencairan komisi?')) router.post(route('commissions.payout-request', lineId));
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Komisi Agen</h1>
        <p class="text-sm text-neutral-500">Komisi otomatis dihitung saat booking active. Multi-tier (POKOK/P/R/CF/REWARD).</p>

        <div class="card flex flex-wrap items-center gap-3 p-4">
            <label class="text-sm text-neutral-500">Tampilkan:</label>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua komisi</option>
                <option v-for="(label, key) in (lineStatuses ?? {})" :key="key" :value="key">{{ label }}</option>
            </select>
            <span v-if="status === 'ready'" class="text-xs text-neutral-400">Menampilkan komisi yang sudah di-request pencairannya.</span>
        </div>

        <div v-for="c in commissions.data" :key="c.id" class="card p-6">
            <div class="flex justify-between items-center mb-3">
                <div>
                    <p class="font-mono text-sm">{{ c.booking?.booking_number }}</p>
                    <p class="text-xs text-neutral-500">Base: {{ fmtRp(c.base_amount) }}</p>
                </div>
                <Badge>{{ c.status }}</Badge>
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-neutral-500">
                    <tr>
                        <th class="py-1">Tier</th>
                        <th class="py-1">Agen</th>
                        <th class="py-1">Trigger</th>
                        <th class="py-1 text-right">Rate %</th>
                        <th class="py-1 text-right">Nominal</th>
                        <th class="py-1">Status</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="l in c.lines" :key="l.id">
                        <td class="py-1"><Badge>{{ l.tier }}</Badge></td>
                        <td class="py-1">{{ l.agent?.name }}</td>
                        <td class="py-1 capitalize">{{ l.trigger_event }}</td>
                        <td class="py-1 text-right">{{ l.rate_pct ?? '-' }}</td>
                        <td class="py-1 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                        <td class="py-1">{{ l.status }}</td>
                        <td class="py-1 text-right">
                            <button v-if="l.status === 'scheduled'" @click="requestPayout(l.id)" class="text-primary-600 text-xs">Request Payout</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <EmptyState v-if="!commissions.data?.length" title="Belum ada komisi" description="Komisi akan auto-terbit saat booking dengan agent_id active." />
    </div>
</template>
