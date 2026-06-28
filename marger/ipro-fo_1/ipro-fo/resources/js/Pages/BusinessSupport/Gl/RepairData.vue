<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Wrench, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ diagnostics: Record<string, number> }>();

const items = [
    { key: 'total_drift', label: 'Total jurnal tidak sinkron', desc: 'Header total_debit/credit ≠ jumlah baris. Diperbaiki otomatis.', fixable: true },
    { key: 'period_mismatch', label: 'Field periode tidak sesuai tanggal', desc: 'period_year/month ≠ tanggal jurnal. Diperbaiki otomatis.', fixable: true },
    { key: 'missing_periods', label: 'Periode fiskal belum ada', desc: 'Ada jurnal pada periode yang belum terdaftar. Dibuat otomatis (status open).', fixable: true },
    { key: 'unbalanced', label: 'Jurnal tidak balance', desc: 'Total debit ≠ kredit. Perlu tinjauan manual — tidak diperbaiki otomatis.', fixable: false },
    { key: 'orphan_lines', label: 'Baris jurnal yatim', desc: 'Baris yang jurnalnya terhapus. Perlu tinjauan manual.', fixable: false },
];

const hasIssues = Object.values(props.diagnostics).some(v => v > 0);
function run() { if (!confirm('Jalankan perbaikan otomatis (hitung ulang total, selaraskan periode, buat periode fiskal)?')) return; router.post(route('bs.gl.repair.run'), {}, { preserveScroll: true }); }
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wrench class="h-6 w-6 text-primary-600" /> Repair Data</h1>
            <Button @click="run"><template #icon-left><Wrench class="h-4 w-4" /></template>Jalankan Repair</Button>
        </div>
        <p class="text-sm text-neutral-500">Diagnosa & perbaikan integritas GL. Perbaikan otomatis hanya pada hal yang aman (hitung ulang total, selaraskan field periode, buat periode fiskal).</p>

        <div v-if="!hasIssues" class="card flex items-center gap-3 p-5 text-sm text-emerald-700 dark:text-emerald-300">
            <CheckCircle2 class="h-5 w-5" /> Tidak ada masalah integritas terdeteksi. Data GL sehat.
        </div>

        <div class="space-y-2">
            <div v-for="it in items" :key="it.key" class="card flex items-start justify-between gap-4 p-4">
                <div class="flex items-start gap-3">
                    <component :is="diagnostics[it.key] > 0 ? AlertTriangle : CheckCircle2" class="mt-0.5 h-5 w-5 flex-shrink-0" :class="diagnostics[it.key] > 0 ? (it.fixable ? 'text-amber-500' : 'text-danger-500') : 'text-emerald-500'" />
                    <div>
                        <div class="font-medium">{{ it.label }}</div>
                        <div class="text-xs text-neutral-500">{{ it.desc }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="font-mono text-lg font-bold" :class="diagnostics[it.key] > 0 ? (it.fixable ? 'text-amber-600' : 'text-danger-600') : 'text-neutral-300'">{{ diagnostics[it.key] }}</div>
                    <div class="text-[10px] uppercase text-neutral-400">{{ it.fixable ? 'auto-fix' : 'manual' }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
