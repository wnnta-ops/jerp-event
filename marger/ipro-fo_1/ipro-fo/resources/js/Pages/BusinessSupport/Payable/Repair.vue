<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Wrench, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ diagnostics: Record<string, number> }>();

const items = [
    { key: 'paid_drift', label: 'paid_amount tidak sinkron', desc: 'Terbayar di invoice ≠ jumlah pembayaran (BsApPayment). Diperbaiki otomatis.', fixable: true },
    { key: 'status_mismatch', label: 'Status tidak sesuai', desc: 'Status open/paid tidak konsisten dengan terbayar vs payable. Diselaraskan otomatis.', fixable: true },
    { key: 'orphan_payments', label: 'Pembayaran yatim', desc: 'Pembayaran yang invoice-nya terhapus. Perlu tinjauan manual.', fixable: false },
];

const hasIssues = Object.values(props.diagnostics).some(v => v > 0);
function run() { if (!confirm('Hitung ulang paid_amount & status semua invoice hutang dari pembayaran?')) return; router.post(route('bs.ap.repair.run'), {}, { preserveScroll: true }); }
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.ap.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wrench class="h-6 w-6 text-primary-600" /> Repair Data — Hutang</h1>
            <Button @click="run"><template #icon-left><Wrench class="h-4 w-4" /></template>Jalankan Repair</Button>
        </div>
        <p class="text-sm text-neutral-500">Hitung ulang <code>paid_amount</code> & status invoice hutang dari catatan pembayaran. Aman — hanya invoice open/paid yang disentuh.</p>

        <div v-if="!hasIssues" class="card flex items-center gap-3 p-5 text-sm text-emerald-700 dark:text-emerald-300">
            <CheckCircle2 class="h-5 w-5" /> Tidak ada masalah. Data hutang sinkron dengan pembayaran.
        </div>

        <div class="space-y-2">
            <div v-for="it in items" :key="it.key" class="card flex items-start justify-between gap-4 p-4">
                <div class="flex items-start gap-3">
                    <component :is="diagnostics[it.key] > 0 ? AlertTriangle : CheckCircle2" class="mt-0.5 h-5 w-5 flex-shrink-0" :class="diagnostics[it.key] > 0 ? (it.fixable ? 'text-amber-500' : 'text-danger-500') : 'text-emerald-500'" />
                    <div><div class="font-medium">{{ it.label }}</div><div class="text-xs text-neutral-500">{{ it.desc }}</div></div>
                </div>
                <div class="text-right">
                    <div class="font-mono text-lg font-bold" :class="diagnostics[it.key] > 0 ? (it.fixable ? 'text-amber-600' : 'text-danger-600') : 'text-neutral-300'">{{ diagnostics[it.key] }}</div>
                    <div class="text-[10px] uppercase text-neutral-400">{{ it.fixable ? 'auto-fix' : 'manual' }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
